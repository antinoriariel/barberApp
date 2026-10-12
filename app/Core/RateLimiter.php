<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Limitador de intentos. Con driver db guarda los contadores en la tabla `login_attempts`;
 * con driver files, en archivos (storage/ratelimit/).
 *
 * Cada clave (p. ej. "login:ip:1.2.3.4") guarda un contador y el momento en que vence.
 * La clave se guarda hasheada (sha256) en ambos almacenamientos.
 */
final class RateLimiter
{
    /** Carpeta donde se guardan los contadores (bloqueada al público por storage/.htaccess). */
    private static function dir(): string
    {
        return BASE_PATH . '/storage/ratelimit';
    }

    /** Ruta del archivo de una clave; se hashea para no depender de caracteres raros ni de la longitud. */
    private static function file(string $key): string
    {
        return self::dir() . '/' . hash('sha256', $key) . '.json';
    }

    /** Lee el contador de una clave: ['count' => n, 'reset' => timestamp de vencimiento]. */
    private static function read(string $key): array
    {
        if (Database::enabled()) {
            $r = Database::selectOne('SELECT attempts, expires_at FROM login_attempts WHERE key_hash = ?', [hash('sha256', $key)]);
            return $r ? ['count' => (int) $r['attempts'], 'reset' => (int) strtotime($r['expires_at'])] : ['count' => 0, 'reset' => 0];
        }
        $raw = @file_get_contents(self::file($key));
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) && isset($data['count'], $data['reset'])
            ? ['count' => (int) $data['count'], 'reset' => (int) $data['reset']]
            : ['count' => 0, 'reset' => 0];
    }

    /** ¿La clave ya alcanzó $max intentos dentro de su ventana vigente? */
    public static function tooMany(string $key, int $max): bool
    {
        $d = self::read($key);
        return $d['reset'] > time() && $d['count'] >= $max;
    }

    /** Intentos que le quedan a la clave antes de llegar a $max dentro de su ventana (con la ventana vencida, $max). */
    public static function remaining(string $key, int $max): int
    {
        $d = self::read($key);
        return $d['reset'] > time() ? max(0, $max - $d['count']) : $max;
    }

    /** Segundos que faltan para que la clave deje de estar bloqueada (0 si no lo está). */
    public static function retryAfter(string $key): int
    {
        return max(0, self::read($key)['reset'] - time());
    }

    /**
     * Suma un intento a la clave y devuelve el contador ya sumado. Si la ventana anterior venció, arranca una
     * nueva de $window segundos. El incremento es atómico (UPSERT en la base, flock en archivos), así que con
     * N peticiones simultáneas cada una ve un número distinto y como mucho $max pasan el control: sirve para
     * "reservar" el intento antes de verificar, sin la carrera de consultar primero y sumar después.
     * Si no se puede escribir en storage/ se registra el error y se sigue (fail-open, devuelve 0).
     */
    public static function hit(string $key, int $window): int
    {
        if (Database::enabled()) {
            // Una sola sentencia atómica: si la ventana venció arranca otra (attempts = 1), si no suma uno.
            $now = date('Y-m-d H:i:s');
            Database::execute(
                'INSERT INTO login_attempts (key_hash, attempts, expires_at) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE attempts = IF(expires_at <= ?, 1, attempts + 1),
                                         expires_at = IF(expires_at <= ?, VALUES(expires_at), expires_at)',
                [hash('sha256', $key), date('Y-m-d H:i:s', time() + $window), $now, $now]
            );
            if (random_int(1, 100) === 1) {
                Database::execute('DELETE FROM login_attempts WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
            }
            return self::read($key)['count'];
        }
        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true) && !is_dir(self::dir())) {
            error_log('RateLimiter: no se pudo crear ' . self::dir());
            return 0;
        }
        $fh = @fopen(self::file($key), 'c+');
        if ($fh === false) {
            error_log('RateLimiter: no se pudo abrir el contador de ' . $key);
            return 0;
        }
        flock($fh, LOCK_EX);
        $d = json_decode((string) stream_get_contents($fh), true);
        $expired = !is_array($d) || ($d['reset'] ?? 0) <= time();
        $next = $expired
            ? ['count' => 1, 'reset' => time() + $window]
            : ['count' => (int) $d['count'] + 1, 'reset' => (int) $d['reset']];
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($next));
        flock($fh, LOCK_UN);
        fclose($fh);

        if (random_int(1, 100) === 1) {
            self::purge();
        }
        return $next['count'];
    }

    /**
     * Devuelve un intento reservado con hit() (resta uno, sin bajar de cero ni tocar el vencimiento).
     * Se usa cuando el intento no debe contar: un login correcto, o una petición rechazada por bloqueo.
     */
    public static function release(string $key): void
    {
        if (Database::enabled()) {
            Database::execute('UPDATE login_attempts SET attempts = GREATEST(attempts - 1, 0) WHERE key_hash = ?', [hash('sha256', $key)]);
            return;
        }
        $fh = @fopen(self::file($key), 'r+');
        if ($fh === false) {
            return;
        }
        flock($fh, LOCK_EX);
        $d = json_decode((string) stream_get_contents($fh), true);
        if (is_array($d) && isset($d['count'], $d['reset'])) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode(['count' => max(0, (int) $d['count'] - 1), 'reset' => (int) $d['reset']]));
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /**
     * Agrupa la IP para las claves de límite: una IPv4 se usa entera, una IPv6 por su prefijo /64 (lo que
     * suele recibir un solo cliente; si no, rotando direcciones dentro de su bloque tendría intentos infinitos).
     */
    public static function ipBucket(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Borra el contador de una clave (p. ej. tras un login exitoso). */
    public static function clear(string $key): void
    {
        if (Database::enabled()) {
            Database::execute('DELETE FROM login_attempts WHERE key_hash = ?', [hash('sha256', $key)]);
            return;
        }
        @unlink(self::file($key));
    }

    /** Limpieza ocasional: elimina contadores sin actividad hace más de un día. */
    private static function purge(): void
    {
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }
}
