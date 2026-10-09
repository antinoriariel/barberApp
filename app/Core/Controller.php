<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Services\Auth;

/** Base de los controladores: render de páginas, redirecciones, CSRF y protección por login. */
abstract class Controller
{
    /**
     * Renderiza `$template` dentro de `$layout` y lo envía como respuesta.
     * Las páginas dependen de la sesión, así que se prohíbe que el navegador las guarde en su caché HTTP.
     */
    protected function view(string $template, array $data = [], string $layout = 'layouts/base'): void
    {
        header('Cache-Control: no-store');
        echo View::page($template, $data, $layout);
    }

    /**
     * Redirige y termina la petición.
     * Solo acepta rutas internas ("/algo"): rechaza "//host" y "/\host" (que los navegadores
     * interpretan como otro sitio) para evitar redirecciones abiertas si algún día el destino viene del usuario.
     */
    protected function redirect(string $to): never
    {
        if (!preg_match('#^/(?![/\\\\])#', $to)) {
            $to = '/';
        }
        header('Location: ' . $to);
        exit;
    }

    /** Devuelve el usuario logueado o redirige al login. */
    protected function requireLogin(): User
    {
        return Auth::user() ?? $this->redirect('/login');
    }

    /** ¿La petición trae un token CSRF válido? Lo acepta en el campo POST "_csrf" o en la cabecera X-CSRF-Token (fetch). */
    protected function csrfValid(Request $request): bool
    {
        return Security::verifyCsrf($request->input('_csrf') ?? $request->header('X-CSRF-Token'));
    }

    /**
     * Exige un token CSRF válido (ver csrfValid); si falta o no coincide responde 419.
     * Llamar al inicio de toda acción que cambie estado (POST).
     */
    protected function requireCsrf(Request $request): void
    {
        if (!$this->csrfValid($request)) {
            Response::error(419, 'La página caducó', 'Volvé atrás, recargá la página e intentá de nuevo.');
        }
    }
}
