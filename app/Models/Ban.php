<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Bloqueos que administra el superusuario: por IP (ip_bans) y por navegador (device_bans, el "Permaban", ver
 * Services\Device). public/index.php consulta blocks() antes de despachar cualquier ruta. Solo con base de datos.
 */
final class Ban
{
    /** ¿Esta IP o este navegador (sha256 de su cookie) están bloqueados? Una sola consulta por petición. */
    public static function blocks(string $ip, string $deviceHash): bool
    {
        if (!Database::enabled()) {
            return false;
        }
        return Database::selectOne(
            'SELECT 1 AS x FROM ip_bans WHERE ip = ? UNION ALL SELECT 1 FROM device_bans WHERE device_hash = ? LIMIT 1',
            [$ip, $deviceHash]
        ) !== null;
    }

    /** Valida una IP (v4 o v6) y la devuelve normalizada (inet_ntop), o null si no es una IP. */
    public static function normalizeIp(string $raw): ?string
    {
        $raw = trim($raw);
        if (!filter_var($raw, FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = inet_pton($raw);
        return $packed !== false ? (string) inet_ntop($packed) : null;
    }

    /** Bloquea una IP (si ya estaba, actualiza el motivo). */
    public static function banIp(string $ip, string $reason = ''): void
    {
        Database::execute(
            'INSERT INTO ip_bans (ip, reason, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason)',
            [$ip, mb_substr($reason, 0, 255), date('Y-m-d H:i:s')]
        );
    }

    /** Levanta el bloqueo de una IP. Devuelve false si no estaba bloqueada. */
    public static function unbanIp(string $ip): bool
    {
        return Database::execute('DELETE FROM ip_bans WHERE ip = ?', [$ip]) > 0;
    }

    /**
     * Bloquea un navegador para siempre. Guarda el user agent conocido (para reconocerlo en la lista) y la cuenta
     * por la que se lo bloqueó (null si fue suelto).
     */
    public static function banDevice(string $deviceHash, ?int $userId, string $userAgent = '', string $reason = ''): void
    {
        Database::execute(
            'INSERT INTO device_bans (device_hash, user_id, user_agent, reason, created_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason)',
            [$deviceHash, $userId, mb_substr($userAgent, 0, 255), mb_substr($reason, 0, 255), date('Y-m-d H:i:s')]
        );
    }

    /** Levanta el bloqueo de un navegador. Devuelve false si no estaba bloqueado. */
    public static function unbanDevice(string $deviceHash): bool
    {
        return Database::execute('DELETE FROM device_bans WHERE device_hash = ?', [$deviceHash]) > 0;
    }

    /**
     * IP bloqueadas, la más reciente primero.
     * @return array<int, array{ip: string, reason: string, created_at: string}>
     */
    public static function ips(): array
    {
        return Database::select('SELECT ip, reason, created_at FROM ip_bans ORDER BY created_at DESC');
    }

    /**
     * Navegadores bloqueados, el más reciente primero, con el nombre de la cuenta por la que se bloquearon.
     * @return array<int, array{device_hash: string, user_id: ?int, user_agent: string, reason: string, created_at: string, full_name: ?string}>
     */
    public static function devices(): array
    {
        return Database::select(
            'SELECT b.device_hash, b.user_id, b.user_agent, b.reason, b.created_at, u.full_name
               FROM device_bans b LEFT JOIN users u ON u.id = b.user_id
              ORDER BY b.created_at DESC'
        );
    }

    /**
     * Hashes de navegador bloqueados (para marcar los dispositivos de cada cuenta en la vista).
     * @return array<string, true>
     */
    public static function deviceSet(): array
    {
        return array_fill_keys(array_column(Database::select('SELECT device_hash FROM device_bans'), 'device_hash'), true);
    }
}
