<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Conexión PDO perezosa (se abre en la primera consulta) y API mínima de consultas.
 *
 * Todas las consultas son sentencias preparadas con parámetros (EMULATE_PREPARES = false):
 * nunca se interpola entrada del usuario en el SQL. Los errores son excepciones; public/index.php
 * las registra en el log y muestra la página 500 genérica.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** ¿La app está configurada para leer y escribir en la base (driver = db)? */
    public static function enabled(): bool
    {
        return Config::get('database.driver') === 'db';
    }

    /** Abre una conexión nueva con el usuario indicado (usado por la app y por bin/migrate.php). */
    public static function connect(?string $user = null, ?string $password = null, bool $withDb = true): PDO
    {
        $c = Config::get('database');
        $dsn = sprintf('mysql:host=%s;port=%d;%scharset=%s', $c['host'], $c['port'],
            $withDb ? 'dbname=' . $c['name'] . ';' : '', $c['charset']);
        $pdo = new PDO($dsn, $user ?? $c['user'], $password ?? $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '{$c['timezone']}'");
        return $pdo;
    }

    /** Conexión compartida de la petición. */
    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    /** @return array<int, array<string, mixed>> todas las filas */
    public static function select(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Primera fila o null. */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        return self::select($sql, $params)[0] ?? null;
    }

    /** Ejecuta INSERT/UPDATE/DELETE y devuelve las filas afectadas. */
    public static function execute(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** Id autogenerado del último INSERT. */
    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Ejecuta $fn dentro de una transacción: commit si termina bien, rollback si lanza una excepción
     * (que se vuelve a lanzar). Admite anidación: dentro de otra transacción solo ejecuta $fn.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
