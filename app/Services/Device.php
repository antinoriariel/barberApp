<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Core\Session;
use App\Models\User;

/**
 * Identificación del navegador: cada navegador recibe una cookie `navaja_dev` con 32 bytes aleatorios que dura
 * 10 años. En la base solo se guarda su sha256 (hash()), así una copia de la base no sirve para hacerse pasar por
 * ese navegador. Con ella se arma el historial de dispositivos de cada cuenta (user_devices) y el "Permaban"
 * (device_bans): ese navegador queda sin acceso aunque cambie de IP o de cuenta.
 *
 * Límite honesto: borrar los datos del sitio o usar modo incógnito genera una cookie nueva; por eso el Permaban
 * además suspende la cuenta y se puede combinar con el bloqueo de IP.
 */
final class Device
{
    public const COOKIE = 'navaja_dev';

    /** Id del navegador de esta petición (se calcula una vez). */
    private static ?string $id = null;

    /**
     * Id del navegador: el de la cookie si es válido (64 hex); si falta o fue alterado, genera uno nuevo y lo envía
     * (HttpOnly: el JS no lo lee; SameSite=Lax; Secure bajo HTTPS). En la línea de comandos no hay cookies.
     */
    public static function id(): string
    {
        if (self::$id !== null) {
            return self::$id;
        }
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($raw) && preg_match('/^[a-f0-9]{64}$/D', $raw)) {
            return self::$id = $raw;
        }
        self::$id = bin2hex(random_bytes(32));
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, self::$id, [
                'expires'  => time() + 60 * 60 * 24 * 365 * 10,
                'path'     => '/',
                'secure'   => Security::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return self::$id;
    }

    /** sha256 del id del navegador: es lo único que se guarda en la base. */
    public static function hash(): string
    {
        return hash('sha256', self::id());
    }

    /** User agent de la petición, recortado a 255 caracteres (lo que entra en las columnas). */
    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    /**
     * Anota que la cuenta está usando este navegador desde esta IP (alta o actualización en user_devices).
     * Para no escribir en cada petición, se registra como mucho una vez por minuto por sesión, o enseguida si
     * cambió la IP.
     */
    public static function touch(User $user, string $ip): void
    {
        if (!Database::enabled() || $user->id === 0) {
            return;
        }
        $seen = Session::get('device_seen');
        if (is_array($seen) && ($seen['ip'] ?? '') === $ip && ($seen['user'] ?? 0) === $user->id && ($seen['at'] ?? 0) > time() - 60) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO user_devices (user_id, device_hash, ip, user_agent, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ip = VALUES(ip), user_agent = VALUES(user_agent), last_seen_at = VALUES(last_seen_at)',
            [$user->id, self::hash(), $ip, self::userAgent(), $now, $now]
        );
        Session::set('device_seen', ['ip' => $ip, 'user' => $user->id, 'at' => time()]);
    }
}
