<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Firma de registros internos (HMAC-SHA256). Sirve para detectar filas editadas a mano en la base (p. ej. un puntaje
 * o un tiempo de juego cambiado con un cliente SQL): la firma se calcula con una clave que NO está en la base, así que
 * quien solo tiene acceso a las tablas no puede recalcularla. Las filas cuya firma no cuadra se ignoran.
 *
 * Clave: la variable de entorno APP_KEY (si tiene al menos 32 caracteres) o, si no hay, storage/integrity.key, que se
 * genera sola la primera vez (32 bytes aleatorios; storage/ está fuera de public/ y bloqueado por .htaccess).
 * Cambiar o perder la clave invalida todas las firmas existentes.
 */
final class Integrity
{
    private static ?string $key = null;

    /** Ruta del archivo de la clave cuando no hay APP_KEY. */
    private static function keyFile(): string
    {
        return BASE_PATH . '/storage/integrity.key';
    }

    /**
     * Clave HMAC de la instalación (ver el docblock de la clase). Si el archivo no existe lo crea de forma atómica
     * (modo 'x': si dos peticiones compiten, gana una y la otra lee la clave ya escrita).
     */
    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $env = (string) getenv('APP_KEY');
        if (strlen($env) >= 32) {
            return self::$key = $env;
        }
        $file = self::keyFile();
        if (!is_file($file)) {
            $fh = @fopen($file, 'x');
            if ($fh !== false) {
                fwrite($fh, bin2hex(random_bytes(32)));
                fclose($fh);
                @chmod($file, 0600);
            }
        }
        $key = trim((string) @file_get_contents($file));
        if (strlen($key) < 32) {
            throw new \RuntimeException('No se pudo leer ni crear la clave de integridad (storage/integrity.key).');
        }
        return self::$key = $key;
    }

    /**
     * Firma una lista de valores con un contexto (nombre de la tabla) para que una firma no sirva en otra tabla.
     * Los valores se serializan en JSON, así "1|23" y "12|3" nunca dan la misma cadena.
     */
    public static function sign(string $context, array $values): string
    {
        return hash_hmac('sha256', $context . "\n" . json_encode(array_values($values), JSON_THROW_ON_ERROR), self::key());
    }

    /** ¿La firma guardada corresponde a esos valores? Comparación en tiempo constante. */
    public static function valid(string $context, array $values, ?string $sig): bool
    {
        return is_string($sig) && hash_equals(self::sign($context, $values), $sig);
    }
}
