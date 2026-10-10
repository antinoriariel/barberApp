<?php
declare(strict_types=1);

namespace App\Core;

/** Datos de la petición HTTP actual (envoltorio de solo lectura sobre las superglobales). */
final class Request
{
    /** Método HTTP en mayúsculas ("GET", "POST"…). */
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Ruta sin query ni barra final: "/login/" → "/login". La raíz queda como "/". */
    public function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** Parámetro de la query string (?s=192). */
    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** Campo de un formulario POST. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /** Archivo subido en un formulario multipart (entrada de $_FILES: name, tmp_name, size, error…) o null si no vino. */
    public function file(string $key): ?array
    {
        $file = $_FILES[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    /** Cabecera de la petición por nombre ('X-CSRF-Token'), o null. */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    /**
     * IP del cliente según REMOTE_ADDR.
     * No se confía en X-Forwarded-For: cualquiera puede falsificarlo y esquivaría el límite de intentos.
     * Detrás de un proxy propio habría que configurarlo explícitamente.
     */
    public function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}
