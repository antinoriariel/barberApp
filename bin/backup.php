<?php
/**
 * bin/backup.php — Respaldo de la base en storage/backups/navaja-AAAAmmdd-HHMMSS.sql.gz
 * Uso: php bin/backup.php
 *
 * Corre mysqldump con una transacción consistente (--single-transaction) y la contraseña por variable de
 * entorno (nunca en la línea de comandos, donde otros usuarios del equipo la verían). Comprueba que el volcado
 * terminó completo y conserva solo los últimos BACKUP_KEEP (14 por defecto).
 *
 * Variables opcionales (.env): MYSQLDUMP (ruta del ejecutable), BACKUP_KEEP.
 * Programarlo a diario: en Linux con cron (`0 3 * * * php /ruta/bin/backup.php`), en Windows con el Programador
 * de tareas. Después de programarlo, probá la restauración con `php bin/restore-check.php`.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;

$db = Config::get('database');
$dir = BASE_PATH . '/storage/backups';
if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
    fwrite(STDERR, "No se pudo crear {$dir}\n");
    exit(1);
}

/** Ejecutable de un cliente de MySQL: variable de entorno, carpeta de XAMPP o el PATH. */
function mysql_bin(string $name, string $envVar): string
{
    $custom = getenv($envVar);
    if ($custom) {
        return $custom;
    }
    $xampp = 'C:/xampp/mysql/bin/' . $name . '.exe';
    return is_file($xampp) ? $xampp : $name;
}

$file = $dir . '/navaja-' . date('Ymd-His') . '.sql.gz';
$cmd = [
    mysql_bin('mysqldump', 'MYSQLDUMP'),
    '--host=' . $db['host'], '--port=' . $db['port'], '--user=' . $db['migrate_user'],
    '--single-transaction', '--routines', '--default-character-set=' . $db['charset'],
    $db['name'],
];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['MYSQL_PWD' => $db['migrate_password']]));
if (!is_resource($proc)) {
    fwrite(STDERR, "No se pudo ejecutar mysqldump.\n");
    exit(1);
}

$gz = gzopen($file, 'wb9');
$tail = '';
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 65536);
    if ($chunk === false || $chunk === '') {
        continue;
    }
    gzwrite($gz, $chunk);
    $tail = substr($tail . $chunk, -200);
}
gzclose($gz);
$errors = stream_get_contents($pipes[2]);
$code = proc_close($proc);

// mysqldump termina con "-- Dump completed on …": si falta, el archivo está cortado y no sirve.
if ($code !== 0 || !str_contains($tail, 'Dump completed')) {
    @unlink($file);
    fwrite(STDERR, "El respaldo falló (código {$code}): " . trim($errors) . "\n");
    exit(1);
}
chmod($file, 0640);
echo 'Respaldo creado: ' . basename($file) . ' (' . number_format(filesize($file) / 1024, 1) . " KB)\n";

// Retención: se borran los más viejos.
$keep = max(1, (int) (getenv('BACKUP_KEEP') ?: 14));
$all = glob($dir . '/navaja-*.sql.gz') ?: [];
rsort($all); // el nombre lleva la fecha: orden alfabético = cronológico
foreach (array_slice($all, $keep) as $old) {
    unlink($old);
    echo 'Borrado por antigüedad: ' . basename($old) . "\n";
}
