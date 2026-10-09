<?php
declare(strict_types=1);

namespace App\Core;

/** Envoltorio mínimo sobre $_SESSION, con la cookie de sesión endurecida. */
final class Session
{
    /**
     * Inicia la sesión (una sola vez por petición).
     *
     * Endurecimiento: modo estricto (rechaza ids que el servidor no generó), ids solo por cookie
     * (nunca en la URL), cookie HttpOnly (el JS no la lee), SameSite=Lax (no viaja en POST de otros
     * sitios) y Secure cuando se usa HTTPS. Nombre propio para no delatar "PHPSESSID".
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (int) Config::get('session_lifetime');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        // Con base de datos las sesiones viven en la tabla `sessions` (se pueden revocar por usuario).
        if (Database::enabled()) {
            session_set_save_handler(new DbSessionHandler(), true);
        }

        session_name('navaja_sid');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => Security::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Lee un valor de la sesión o null. */
    public static function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    /** Guarda un valor en la sesión. */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /** Elimina una clave de la sesión. */
    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Cambia el id de sesión (previene la fijación de sesión al iniciar login). */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Cierra la sesión: vacía los datos, destruye el archivo del servidor y expira la cookie. */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
    }
}
