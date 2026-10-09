<?php
/**
 * tests/bootstrap.php — Prepara el entorno de pruebas.
 *
 * Fuerza el driver db sobre una base APARTE (navaja_test) para no tocar los datos reales, la crea si no existe
 * y le aplica migraciones y semilla (ambas idempotentes). Usa las credenciales de migraciones del .env, que
 * deben poder crear bases.
 */
putenv('DB_DRIVER=db');
putenv('DB_NAME=navaja_test');

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

// Salvaguarda: jamás correr las pruebas (que borran filas) contra otra base.
if (Config::get('database.name') !== 'navaja_test') {
    fwrite(STDERR, "Las pruebas solo pueden correr contra la base navaja_test.\n");
    exit(1);
}

// Las pruebas ejercitan Auth con sesión real: sin cookies ni cabeceras (la salida de PHPUnit ya empezó).
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');

$admin = Database::connect(Config::get('database.migrate_user'), Config::get('database.migrate_password'), false);
$admin->exec('CREATE DATABASE IF NOT EXISTS navaja_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

foreach (['migrate', 'seed'] as $script) {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . "/bin/{$script}.php") . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "bin/{$script}.php falló:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
}
