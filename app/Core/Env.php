<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Carga el archivo .env de la raíz del proyecto (CLAVE=valor por línea) en el entorno de PHP.
 * El .env está fuera de public/, bloqueado por .htaccess e ignorado por git: ahí viven las credenciales.
 * Una variable ya definida en el entorno real tiene prioridad sobre el archivo.
 */
final class Env
{
    /** Lee BASE_PATH/.env si existe. Ignora líneas vacías y comentarios (#). */
    public static function load(): void
    {
        $file = BASE_PATH . '/.env';
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }
    }
}
