<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Seguridad HTTP transversal: cabeceras de protección y token anti-CSRF.
 * Detalle y justificación de cada cabecera en docs/seguridad.md.
 */
final class Security
{
    /** Indica si la petición llegó por HTTPS (directo o por el puerto 443). */
    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        return ($https !== '' && strtolower((string) $https) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * Envía las cabeceras de seguridad de toda respuesta generada por PHP.
     *
     * La política CSP permite solo recursos propios, salvo las fuentes de Google. Los atributos
     * style="" (los usan los avatares y la barra de fidelidad para pasar variables CSS) se
     * habilitan aparte con style-src-attr; los <style> y <link> siguen restringidos y no
     * se permite ningún script inline.
     */
    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By'); // no anunciar la versión de PHP

        $csp = [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' https://fonts.googleapis.com",
            "style-src-attr 'unsafe-inline'",
            "font-src https://fonts.gstatic.com",
            "img-src 'self' data:",
            "connect-src 'self'",
            "manifest-src 'self'",
            "worker-src 'self'",
            "object-src 'none'",          // sin plugins
            "base-uri 'none'",            // evita que inyecten un <base> y desvíen URLs relativas
            "form-action 'self'",         // los formularios solo envían a este sitio
            "frame-ancestors 'none'",     // nadie puede embeber la app en un iframe (clickjacking)
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));
        header('X-Content-Type-Options: nosniff');          // el navegador respeta el Content-Type
        header('X-Frame-Options: DENY');                    // equivalente de frame-ancestors para navegadores viejos
        header('Referrer-Policy: same-origin');             // no filtrar URLs a otros sitios
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()'); // cámara solo para este sitio: lector del QR de la fila
        header('Cross-Origin-Opener-Policy: same-origin');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=' . (int) Config::get('security.hsts_max_age') . '; includeSubDomains');
        }
    }

    /**
     * Token CSRF de la sesión actual (se crea la primera vez que se pide).
     * Se incluye en cada formulario POST con csrf_field() y se verifica con verifyCsrf().
     */
    public static function csrfToken(): string
    {
        $token = Session::get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf', $token);
        }
        return $token;
    }

    /** Compara el token recibido con el de la sesión en tiempo constante (hash_equals). */
    public static function verifyCsrf(mixed $token): bool
    {
        $expected = Session::get('csrf');
        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }
}
