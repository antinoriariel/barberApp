<?php
/**
 * bin/game-stats.php — Tiempo invertido en los minijuegos (dato interno: la app no lo muestra).
 * Uso: php bin/game-stats.php            totales por juego (partidas, jugadores, horas)
 *      php bin/game-stats.php <dni>      tiempo de ese usuario en cada juego
 *
 * Solo cuenta partidas cerradas con firma válida (Models\GameSession); las filas alteradas en la base se ignoran
 * y quedan anotadas en el log de errores.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\GameSession;
use App\Support\Dni;

if (!Database::enabled()) {
    fwrite(STDERR, "Requiere DB_DRIVER=db.\n");
    exit(1);
}
$games = (require BASE_PATH . '/config/games.php')['games'];

/** Segundos como "1 h 05 min 12 s". */
$fmt = static fn (int $s): string => sprintf('%d h %02d min %02d s', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);

if (isset($argv[1])) {
    $dni = Dni::normalize($argv[1]);
    $user = Dni::isValid($dni) ? Database::selectOne('SELECT id, full_name FROM users WHERE dni = ?', [$dni]) : null;
    if ($user === null) {
        fwrite(STDERR, "No existe ningún usuario con DNI {$argv[1]}.\n");
        exit(1);
    }
    $spent = GameSession::timeSpent((int) $user['id']);
    echo "{$user['full_name']} ({$dni})\n";
    foreach ($games as $slug => $game) {
        printf("  %-18s %s\n", $game['name'], $fmt($spent[$slug] ?? 0));
    }
    exit(0);
}

$totals = GameSession::totals();
foreach ($games as $slug => $game) {
    $t = $totals[$slug] ?? ['plays' => 0, 'players' => 0, 'seconds' => 0];
    printf("%-18s %5d partidas  %4d jugadores  %s\n", $game['name'], $t['plays'], $t['players'], $fmt($t['seconds']));
}
