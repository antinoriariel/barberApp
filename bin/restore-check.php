<?php
/**
 * bin/restore-check.php — Prueba de restauración: carga el último respaldo en una base temporal
 * (navaja_restore_check), compara las tablas con la base real, informa y borra la temporal.
 * Uso: php bin/restore-check.php [ruta-al-respaldo.sql.gz]
 *
 * Un respaldo que nunca se restauró no es un respaldo: correr esto después de programar backup.php y de vez en cuando.
 * Sale con código 0 si las tablas y los datos principales se restauraron bien.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$db = Config::get('database');
$tmp = 'navaja_restore_check';

$backups = glob(BASE_PATH . '/storage/backups/navaja-*.sql.gz') ?: [];
sort($backups); // el nombre lleva la fecha: el último es el más reciente
$file = $argv[1] ?? (end($backups) ?: null);
if (!$file || !is_file($file)) {
    fwrite(STDERR, "No hay respaldos. Corré primero: php bin/backup.php\n");
    exit(1);
}
echo 'Restaurando ' . basename($file) . " en {$tmp}…\n";

$pdo = Database::connect($db['migrate_user'], $db['migrate_password'], false);
$pdo->exec("DROP DATABASE IF EXISTS {$tmp}");
$pdo->exec("CREATE DATABASE {$tmp} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$client = getenv('MYSQL') ?: (is_file('C:/xampp/mysql/bin/mysql.exe') ? 'C:/xampp/mysql/bin/mysql.exe' : 'mysql');
$proc = proc_open(
    [$client, '--host=' . $db['host'], '--port=' . $db['port'], '--user=' . $db['migrate_user'], '--default-character-set=utf8mb4', $tmp],
    [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], $pipes, null,
    array_merge(getenv(), ['MYSQL_PWD' => $db['migrate_password']])
);
$gz = gzopen($file, 'rb');
while (!gzeof($gz)) {
    fwrite($pipes[0], gzread($gz, 65536));
}
gzclose($gz);
fclose($pipes[0]);
$errors = stream_get_contents($pipes[2]);
$code = proc_close($proc);

$fail = $code !== 0;
if ($fail) {
    fwrite(STDERR, 'Error al restaurar: ' . trim($errors) . "\n");
}

$tables = static fn (string $schema): array => array_column(
    $pdo->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = '{$schema}' AND table_type = 'BASE TABLE' ORDER BY 1")->fetchAll(), 't'
);
$live = $tables($db['name']);
$restored = $tables($tmp);
$missing = array_diff($live, $restored);
if ($missing) {
    $fail = true;
    echo 'FALTAN tablas en la restauración: ' . implode(', ', $missing) . "\n";
}

echo sprintf("%-18s %10s %10s\n", 'tabla', 'respaldo', 'actual');
foreach ($restored as $t) {
    $r = (int) $pdo->query("SELECT COUNT(*) FROM `{$tmp}`.`{$t}`")->fetchColumn();
    $l = in_array($t, $live, true) ? (int) $pdo->query("SELECT COUNT(*) FROM `{$db['name']}`.`{$t}`")->fetchColumn() : '-';
    echo sprintf("%-18s %10d %10s\n", $t, $r, $l);
    if ($t === 'users' && $r === 0) {
        $fail = true;
        echo "La tabla users restaurada está vacía.\n";
    }
}

$pdo->exec("DROP DATABASE {$tmp}");
echo $fail ? "RESTAURACIÓN CON PROBLEMAS.\n" : "Restauración correcta (la base temporal se borró).\n";
exit($fail ? 1 : 0);
