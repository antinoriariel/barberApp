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

/** Login, alta de cuenta, recuperación de contraseña y cierre de sesión. */
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

    /** GET /registro — formulario de alta (requiere base de datos). */
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
            'email'     => $request->string('email'),
        ];
        $error = Auth::register($values['dni'], $values['full_name'], $values['email'],
            $request->string('password'), $request->string('password2'), $request->ip());

        if ($error === null) {
            $this->redirect('/');
        }
        $this->throttleHeaders($error);
        $this->renderRegister($error, $values);
    }

    /** GET /recuperar — pide el email para mandar el enlace. */
    public function showForgot(Request $request): void
    {
        $this->requireDatabase();
        $this->view('pages/forgot', $this->authPage('Recuperar contraseña'));
    }

    /** POST /recuperar — responde siempre lo mismo, exista o no la cuenta (no revela qué emails están registrados). */
    public function forgot(Request $request): void
    {
        $this->requireDatabase();
        $this->requireCsrf($request);

        $sent = Auth::requestReset($request->string('email'), $request->ip());
        if (!$sent) {
            http_response_code(429);
            header('Retry-After: 900');
        }
        $this->view('pages/forgot', $this->authPage('Recuperar contraseña', [
            'error' => $sent ? '' : Auth::ERROR_THROTTLED,
            'sent'  => $sent,
        ]));
    }

    /** GET /restablecer?token= — formulario de contraseña nueva si el enlace es válido. */
    public function showReset(Request $request): void
    {
        $this->requireDatabase();
        $token = (string) $request->query('token', '');
        $this->view('pages/reset', $this->authPage('Nueva contraseña', [
            'token' => $token,
            'valid' => Auth::resetTokenValid($token),
        ]));
    }

    /** POST /restablecer — guarda la contraseña nueva (y cierra las sesiones abiertas de esa cuenta). */
    public function reset(Request $request): void
    {
        $this->requireDatabase();
        $this->requireCsrf($request);

        $token = $request->string('token');
        $error = Auth::resetPassword($token, $request->string('password'), $request->string('password2'));
        if ($error === null) {
            $this->view('pages/reset', $this->authPage('Nueva contraseña', ['token' => '', 'valid' => false, 'done' => true]));
            return;
        }
        $this->view('pages/reset', $this->authPage('Nueva contraseña', [
            'token' => $token,
            'valid' => Auth::resetTokenValid($token),
            'error' => $error,
        ]));
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

    /** Alta y recuperación necesitan dónde guardar: sin base de datos (driver files) no existen. */
    private function requireDatabase(): void
    {
        if (!Database::enabled()) {
            Response::error(404, 'No encontramos esa página');
        }
    }

    /**
     * Si el error fue por exceso de intentos responde 429 con Retry-After: los segundos reales del bloqueo
     * del login, o la ventana completa para alta y recuperación.
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
            'canSignUp' => Database::enabled(),
        ]));
    }

    /** Renderiza el formulario de alta con los valores ya escritos. */
    private function renderRegister(string $error = '', array $values = []): void
    {
        $this->view('pages/register', $this->authPage('Crear cuenta', [
            'error'  => $error,
            'values' => $values + ['dni' => '', 'full_name' => '', 'email' => ''],
        ]));
    }
}
