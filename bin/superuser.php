<?php
/**
 * bin/superuser.php — Configura la cuenta "superuser" y sirve de salida de emergencia de los bloqueos.
 *
 * Uso:
 *   php bin/superuser.php                    genera una contraseña aleatoria, la guarda y la muestra UNA vez
 *   php bin/superuser.php <contraseña>       fija esa contraseña (mismas reglas que las cuentas)
 *   php bin/superuser.php --desbloquear-ip <ip>   levanta el bloqueo de una IP
 *   php bin/superuser.php --desbloquear-todo      borra todos los bloqueos de IP y todos los Permaban
 *
 * Cambiar la contraseña cierra las sesiones de superusuario abiertas (sube session_version). Para entrar: en el login
 * escribir "superuser" en el campo DNI y esta contraseña. Ojo: la contraseña pasada como argumento queda en el
 * historial de la consola; sin argumento no.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Ban;
use App\Models\Superuser;
use App\Support\Password;

if (!Database::enabled()) {
    fwrite(STDERR, "El superusuario necesita la base de datos (DB_DRIVER=db).\n");
    exit(1);
}

$arg = $argv[1] ?? null;

if ($arg === '--desbloquear-ip') {
    $ip = Ban::normalizeIp($argv[2] ?? '');
    if ($ip === null) {
        fwrite(STDERR, "Uso: php bin/superuser.php --desbloquear-ip <ip>\n");
        exit(1);
    }
    echo Ban::unbanIp($ip) ? "IP {$ip} desbloqueada.\n" : "La IP {$ip} no estaba bloqueada.\n";
    exit(0);
}

if ($arg === '--desbloquear-todo') {
    $ips = Database::execute('DELETE FROM ip_bans');
    $devices = Database::execute('DELETE FROM device_bans');
    echo "Listo: se levantaron {$ips} bloqueo(s) de IP y {$devices} Permaban.\n";
    exit(0);
}

if ($arg !== null && str_starts_with($arg, '--')) {
    fwrite(STDERR, "Opción desconocida: {$arg}\n");
    exit(1);
}

// Sin argumento: 20 caracteres sin los que se confunden (0/O, 1/l/I).
$generated = $arg === null;
$password = $arg;
if ($generated) {
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 20; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
if ($error = Password::validate($password)) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}

Superuser::setPassword(Password::hash($password));
echo "Superusuario listo. Usuario: " . Superuser::LOGIN . "\n";
echo $generated ? "Contraseña (guardala, no se vuelve a mostrar): {$password}\n" : "Contraseña actualizada.\n";
