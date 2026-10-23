<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;
use App\Support\Dni;

/** Login, alta de cuenta, "Olvidé mi contraseña" (DNI + celular del registro) y cierre de sesión. */
final class AuthController extends Controller
{
    /** GET /login — muestra el formulario (o redirige a la app si ya hay sesión). */
    public function showLogin(Request $request): void
    {
        if ($user = Auth::user()) {
            $this->redirect($user->hasPanel() ? '/admin' : '/');
        }
        if (Auth::isSuperuser()) {
            $this->redirect('/su');
        }
        $this->renderLogin();
    }

    /**
     * POST /login — verifica CSRF y delega en Auth::attempt (que aplica el límite de intentos).
     * Éxito → redirige a la app. Error → vuelve a mostrar el formulario con el mensaje
     * (HTTP 429 si fue por exceso de intentos, para que clientes y proxies lo distingan).
     * La contraseña nunca se vuelve a imprimir en el formulario.
     */
    public function login(Request $request): void
    {
        $this->requireCsrf($request);

        $raw = $request->string('dni');
        $error = Auth::attempt($raw, $request->string('password'), $request->ip());

        if ($error === null) {
            if (Auth::isSuperuser()) {
                $this->redirect('/su');
            }
            // Si venía de escanear el QR de la fila sin sesión, vuelve a esa pantalla (solo rutas /fila, nada externo).
            $after = Session::get('after_login');
            Session::remove('after_login');
            if (is_string($after) && str_starts_with($after, '/fila?')) {
                $this->redirect($after);
            }
            // El administrador (y el barbero con secciones del panel habilitadas) entra directo al panel.
            $this->redirect(Auth::user()?->hasPanel() ? '/admin' : '/');
        }
        $this->throttleHeaders($error);
        $this->renderLogin($error, Dni::normalize($raw));
    }

    /**
     * GET /registro — formulario de alta (requiere base de datos). Si este navegador ya creó una cuenta muestra el
     * aviso en lugar del formulario (Auth::canRegisterHere): solo se permite una cuenta por navegador.
     */
    public function showRegister(Request $request): void
    {
        $this->requireDatabase();
        if (Auth::user()) {
            $this->redirect('/');
        }
        $this->renderRegister();
    }

    /** POST /registro — crea la cuenta y entra. Error → vuelve al formulario conservando lo escrito (menos las contraseñas). */
    public function register(Request $request): void
    {
        $this->requireDatabase();
        $this->requireCsrf($request);

        $values = [
            'dni'       => Dni::normalize($request->string('dni')),
            'full_name' => $request->string('full_name'),
            'phone'     => $request->string('phone', '', 30),
        ];
        $error = Auth::register($values['dni'], $values['full_name'], $values['phone'],
            $request->string('password'), $request->string('password2'), $request->ip());

        if ($error === null) {
            $this->redirect('/');
        }
        $this->throttleHeaders($error);
        $this->renderRegister($error, $values);
    }

    /**
     * GET /recuperar — formulario de "Olvidé mi contraseña": DNI, el celular que dio al registrarse y la contraseña
     * nueva (dos veces). Sin base de datos (driver files) no hay dónde guardarla, así que manda al WhatsApp de soporte.
     */
    public function showForgot(Request $request): void
    {
        if (!Database::enabled()) {
            header('Location: ' . Auth::supportWhatsappUrl(), true, 302);
            exit;
        }
        if (Auth::user()) {
            $this->redirect('/');
        }
        $this->renderForgot();
    }

    /**
     * POST /recuperar — delega en Auth::recover (límite de intentos, DNI + celular del registro). Éxito → entra con la
     * contraseña nueva. Error → vuelve al formulario con el DNI y el celular escritos (nunca las contraseñas).
     */
    public function forgot(Request $request): void
    {
        $this->requireDatabase();
        $this->requireCsrf($request);

        $values = [
            'dni'   => Dni::normalize($request->string('dni')),
            'phone' => $request->string('phone', '', 30),
        ];
        $error = Auth::recover($values['dni'], $values['phone'], $request->string('password'),
            $request->string('password2'), $request->ip());

        if ($error === null) {
            $user = Auth::user();
            $this->redirect($user?->hasPanel() ? '/admin' : '/');
        }
        $this->throttleHeaders($error);
        $this->renderForgot($error, $values);
    }

    /** POST /logout — cierra la sesión (POST + CSRF: un enlace de otro sitio no puede desloguear al usuario). */
    public function logout(Request $request): void
    {
        $this->requireCsrf($request);
        Auth::logout();
        $this->redirect('/login');
    }

    /** POST /logout-todo — cierra la sesión en todos los dispositivos. */
    public function logoutAll(Request $request): void
    {
        $user = $this->requireLogin();
        $this->requireCsrf($request);
        Auth::logoutEverywhere($user);
        $this->redirect('/login');
    }

    /** El alta necesita dónde guardar: sin base de datos (driver files) no existe. */
    private function requireDatabase(): void
    {
        if (!Database::enabled()) {
            Response::error(404, 'No encontramos esa página');
        }
    }

    /**
     * Si el error fue por exceso de intentos responde 429 con Retry-After: los segundos reales del bloqueo
     * del login, o la ventana completa para el alta.
     */
    private function throttleHeaders(?string $error): void
    {
        if ($error !== null && str_starts_with($error, Auth::THROTTLED_PREFIX)) {
            http_response_code(429);
            header('Retry-After: ' . (Auth::retryAfter() ?: (int) Config::get('security.login_window')));
        }
    }

    /** Datos comunes de las páginas de cuenta (layout de login). */
    private function authPage(string $heading, array $extra = []): array
    {
        return array_merge([
            'title'     => $heading . ' · Barbería',
            'bodyClass' => 'login-page',
            'scripts'   => ['js/login.js'],
            'error'     => '',
        ], $extra);
    }

    /**
     * Renderiza el formulario de login.
     *
     * @param string $error mensaje a mostrar (vacío si no hay)
     * @param string $dni   último DNI ingresado, solo dígitos, para no obligar a reescribirlo
     */
    private function renderLogin(string $error = '', string $dni = ''): void
    {
        $this->view('pages/login', $this->authPage('Ingresar', [
            'error'     => $error,
            'dni'       => $dni,
            'canSignUp' => Auth::canRegisterHere(),
            'forgotUrl' => Database::enabled() ? '/recuperar' : Auth::supportWhatsappUrl(),
        ]));
    }

    /** Renderiza "Olvidé mi contraseña" con lo ya escrito y el WhatsApp de soporte como plan B. */
    private function renderForgot(string $error = '', array $values = []): void
    {
        $this->view('pages/forgot', $this->authPage('Recuperar contraseña', [
            'error'      => $error,
            'values'     => $values + ['dni' => '', 'phone' => ''],
            'supportUrl' => Auth::supportWhatsappUrl(),
        ]));
    }

    /**
     * Renderiza el formulario de alta con los valores ya escritos, o el aviso de que este navegador ya creó una
     * cuenta (allowed = false).
     */
    private function renderRegister(string $error = '', array $values = []): void
    {
        $this->view('pages/register', $this->authPage('Crear cuenta', [
            'error'     => $error,
            'values'    => $values + ['dni' => '', 'full_name' => '', 'phone' => ''],
            'allowed'   => Auth::canRegisterHere(),
            'forgotUrl' => '/recuperar',
        ]));
    }
}
