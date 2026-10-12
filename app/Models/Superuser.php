<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * La única cuenta de superusuario. No es una fila de `users` (no aparece en listados, turnos ni finanzas): entra
 * escribiendo "superuser" en el campo DNI del login y su contraseña vive en la tabla `superuser` (fila id = 1),
 * que se fija con `php bin/superuser.php`. Sin esa fila (o sin base de datos) el superusuario no existe.
 */
final class Superuser
{
    /** Lo que se escribe en el campo DNI del login para entrar como superusuario. */
    public const LOGIN = 'superuser';

    /** ¿Lo escrito en el campo DNI es el usuario del superusuario? (sin distinguir mayúsculas ni espacios). */
    public static function matches(string $raw): bool
    {
        return strtolower(trim($raw)) === self::LOGIN;
    }

    /** Hash de la contraseña, o null si el superusuario no está configurado (o no hay base de datos). */
    public static function passwordHash(): ?string
    {
        if (!Database::enabled()) {
            return null;
        }
        return Database::selectOne('SELECT password_hash FROM superuser WHERE id = 1')['password_hash'] ?? null;
    }

    /**
     * Versión vigente de las sesiones de superusuario (0 si no está configurado). Una sesión guarda la versión con la
     * que entró: si la contraseña cambió desde entonces, ya no vale.
     */
    public static function sessionVersion(): int
    {
        if (!Database::enabled()) {
            return 0;
        }
        return (int) (Database::selectOne('SELECT session_version FROM superuser WHERE id = 1')['session_version'] ?? 0);
    }

    /**
     * Crea o cambia la contraseña (hash ya calculado) y sube session_version: toda sesión de superusuario abierta
     * se cierra.
     */
    public static function setPassword(string $passwordHash): void
    {
        Database::execute(
            'INSERT INTO superuser (id, password_hash, session_version, updated_at) VALUES (1, ?, 1, ?)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), session_version = session_version + 1,
                                     updated_at = VALUES(updated_at)',
            [$passwordHash, date('Y-m-d H:i:s')]
        );
    }
}
