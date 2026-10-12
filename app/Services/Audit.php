<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Registro de auditoría (tabla `audit_log`): quién hizo qué, desde qué IP y con qué navegador (user agent).
 * Sin base de datos no hace nada. Nunca guardar contraseñas ni tokens en $detail.
 */
final class Audit
{
    /**
     * Anota un evento (login_ok, login_fail, logout, register, password_reset_request, password_reset,
     * appointment_create, appointment_cancel, appointment_reschedule…).
     *
     * @param int|null $userId usuario que lo hizo (null si no se sabe, p. ej. un login fallido)
     * @param string   $detail dato breve y no sensible (se corta a 255 caracteres)
     */
    public static function log(string $event, ?int $userId = null, string $detail = ''): void
    {
        if (!Database::enabled()) {
            return;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        Database::execute(
            'INSERT INTO audit_log (user_id, event, ip, user_agent, detail, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $event, filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0', Device::userAgent() ?: null,
             mb_substr($detail, 0, 255), date('Y-m-d H:i:s')]
        );
    }
}
