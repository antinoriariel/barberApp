<?php
/**
 * bin/migrate.php — Aplica las migraciones pendientes de database/migrations/NNN_nombre.sql, en orden.
 * Uso: php bin/migrate.php
 *
 * Usa el usuario de migraciones (DB_MIGRATE_USER / DB_MIGRATE_PASSWORD del .env), que puede crear tablas;
 * la app corre con otro usuario sin esos privilegios. Es idempotente: lo ya aplicado (tabla `migrations`) se omite.
 * MySQL/MariaDB no revierten DDL: si una migración falla a medio camino hay que corregirla a mano.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$pdo = Database::connect(Config::get('database.migrate_user'), Config::get('database.migrate_password'));

$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
    name VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$done = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);

$applied = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        continue;
    }
    // Quita los comentarios "-- …" y separa las sentencias por ";" al final de línea.
    $sql = preg_replace('/^\s*--.*$|\s--[^\n]*$/m', '', (string) file_get_contents($file));
    foreach (preg_split('/;\s*(?:\r?\n|$)/', (string) $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    $pdo->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
    echo "Aplicada: {$name}\n";
    $applied++;
}
echo $applied === 0 ? "Nada para aplicar: la base está al día.\n" : "Listo: {$applied} migración(es).\n";
