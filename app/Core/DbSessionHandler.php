<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sesiones guardadas en la tabla `sessions` (en vez de archivos).
 *
 * Permite cerrar todas las sesiones de un usuario (revokeUser) y que funcionen varios servidores.
 * Cada sesión se asocia a su usuario con bindUser() al iniciar sesión.
 */
final class DbSessionHandler implements \SessionHandlerInterface
{
    /** Usuario a asociar a la sesión en el próximo write() (lo fija Auth tras un login correcto). */
    private static ?int $userId = null;

    /** Asocia la sesión actual al usuario (se guarda al cerrarse la petición). */
    public static function bindUser(?int $userId): void
    {
        self::$userId = $userId;
    }

    /** Borra todas las sesiones de un usuario (cierre de sesión en todos los dispositivos). */
    public static function revokeUser(int $userId): void
    {
        Database::execute('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    /** Datos de la sesión o cadena vacía si no existe o venció. */
    public function read(string $id): string|false
    {
        $row = Database::selectOne('SELECT data, updated_at FROM sessions WHERE id = ?', [$id]);
        if (!$row || strtotime($row['updated_at']) < time() - (int) Config::get('session_lifetime')) {
            return '';
        }
        return (string) $row['data'];
    }

    /** Guarda (o actualiza) la sesión; conserva el user_id ya asociado si esta petición no lo cambia. */
    public function write(string $id, string $data): bool
    {
        Database::execute(
            'INSERT INTO sessions (id, user_id, data, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at),
                                     user_id = COALESCE(VALUES(user_id), user_id)',
            [$id, self::$userId, $data, date('Y-m-d H:i:s')]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        Database::execute('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    /** Borra las sesiones vencidas (PHP lo invoca de vez en cuando). */
    public function gc(int $max_lifetime): int|false
    {
        return Database::execute('DELETE FROM sessions WHERE updated_at < ?', [date('Y-m-d H:i:s', time() - $max_lifetime)]);
    }
}
