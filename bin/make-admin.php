<?php
/**
 * bin/make-admin.php — Da (o quita) el rol de administrador a un usuario por DNI.
 * Uso: php bin/make-admin.php <dni> [--quitar]
 *
 * Usa el usuario de migraciones. El usuario debe existir (registrarse en /registro o venir del seed).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Support\Dni;

$dni = Dni::normalize($argv[1] ?? '');
if (!Dni::isValid($dni)) {
    fwrite(STDERR, "Uso: php bin/make-admin.php <dni> [--quitar]\n");
    exit(1);
}
$flag = in_array('--quitar', $argv, true) ? 0 : 1;

$pdo = Database::connect(Config::get('database.migrate_user'), Config::get('database.migrate_password'));
$st = $pdo->prepare('UPDATE users SET is_admin = ? WHERE dni = ?');
$st->execute([$flag, $dni]);

$exists = $pdo->prepare('SELECT full_name FROM users WHERE dni = ?');
$exists->execute([$dni]);
$name = $exists->fetchColumn();
if ($name === false) {
    fwrite(STDERR, "No existe ningún usuario con DNI {$dni}.\n");
    exit(1);
}
echo $flag ? "{$name} ({$dni}) ahora es administrador.\n" : "{$name} ({$dni}) ya no es administrador.\n";
