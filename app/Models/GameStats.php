<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Estadísticas de tiempo en los minijuegos para el superusuario (/su/juegos). Parte de las partidas cerradas con
 * firma válida (GameSession::closedSince: el tiempo lo midió el servidor) y las cruza con la fila y los turnos para
 * saber si se jugó mientras se esperaba:
 *  - 'fila':  la persona tenía número en la fila (sacado antes de empezar a jugar y todavía sin llamar);
 *  - 'turno': tenía un turno ese mismo día que empezaba dentro de las WAIT_BEFORE horas siguientes;
 *  - 'otro':  ninguna de las dos (jugó desde su casa, por ejemplo).
 * Todo se calcula en PHP sobre las filas del período: son pocas (una por partida) y así las firmas se verifican.
 */
final class GameStats
{
    /** Cuánto antes de un turno se considera "esperando el turno" (segundos). */
    public const WAIT_BEFORE = 2 * 60 * 60;

    /** Períodos que ofrece el panel (días hacia atrás). */
    public const PERIODS = [7 => '7 días', 30 => '30 días', 90 => '90 días', 365 => '1 año'];

    /** Nombres de los contextos de espera. */
    public const CONTEXTS = ['fila' => 'Esperando en la fila', 'turno' => 'Antes de su turno', 'otro' => 'Sin espera'];

    /** Período válido a partir de ?dias= (30 por defecto). */
    public static function period(mixed $days): int
    {
        $days = (int) $days;
        return isset(self::PERIODS[$days]) ? $days : 30;
    }

    /**
     * Todo lo que muestra /su/juegos para los últimos `$days` días:
     *  - totals:  partidas, jugadores, segundos, promedio por partida y por jugador, mediana, % abandonadas,
     *             rechazadas (puntaje no creíble) y premios;
     *  - games:   lo mismo por juego (slug => …) más el mejor puntaje y los segundos jugados esperando;
     *  - players: los 15 que más tiempo jugaron (nombre, partidas, segundos, juego favorito, última vez);
     *  - days:    segundos por día y por juego (los últimos min(días, 31) días, para el gráfico);
     *  - hours:   segundos por hora del día (0-23) de inicio de la partida;
     *  - context: segundos por contexto de espera (CONTEXTS).
     */
    public static function build(int $days, ?int $now = null): array
    {
        $now ??= time();
        $from = date('Y-m-d 00:00:00', $now - ($days - 1) * 86400);
        $rows = GameSession::closedSince($from);
        $slugs = array_keys((require BASE_PATH . '/config/games.php')['games']);
        $context = self::contexts($rows, $from);

        $totals = self::summary($rows);
        $games = [];
        foreach ($slugs as $slug) {
            $mine = array_values(array_filter($rows, static fn (array $r) => $r['game'] === $slug));
            $games[$slug] = self::summary($mine) + ['best' => $mine ? max(array_column($mine, 'score')) : 0, 'waiting' => 0];
        }

        $chartDays = min($days, 31);
        $byDay = [];
        for ($i = $chartDays - 1; $i >= 0; $i--) {
            $byDay[date('Y-m-d', $now - $i * 86400)] = array_fill_keys($slugs, 0);
        }
        $hours = array_fill(0, 24, 0);
        $ctx = array_fill_keys(array_keys(self::CONTEXTS), 0);
        $players = [];
        foreach ($rows as $i => $r) {
            $day = substr($r['started_at'], 0, 10);
            if (isset($byDay[$day][$r['game']])) {
                $byDay[$day][$r['game']] += $r['seconds'];
            }
            $hours[(int) substr($r['started_at'], 11, 2)] += $r['seconds'];
            $ctx[$context[$i]] += $r['seconds'];
            if ($context[$i] !== 'otro' && isset($games[$r['game']])) {
                $games[$r['game']]['waiting'] += $r['seconds'];
            }
            $p = &$players[$r['user_id']];
            $p ??= ['plays' => 0, 'seconds' => 0, 'games' => [], 'last' => ''];
            $p['plays']++;
            $p['seconds'] += $r['seconds'];
            $p['games'][$r['game']] = ($p['games'][$r['game']] ?? 0) + $r['seconds'];
            $p['last'] = max($p['last'], $r['started_at']);
            unset($p);
        }

        uasort($players, static fn (array $a, array $b) => $b['seconds'] <=> $a['seconds']);
        $players = array_slice($players, 0, 15, true);
        $names = self::names(array_keys($players));
        $top = [];
        foreach ($players as $id => $p) {
            arsort($p['games']);
            $top[] = ['user_id' => $id, 'name' => $names[$id] ?? 'Cuenta #' . $id, 'plays' => $p['plays'],
                      'seconds' => $p['seconds'], 'favorite' => (string) array_key_first($p['games']), 'last' => $p['last']];
        }

        return ['totals' => $totals, 'games' => $games, 'players' => $top, 'days' => $byDay, 'hours' => $hours,
                'context' => $ctx, 'from' => $from];
    }

    /**
     * Resumen de un grupo de partidas: partidas, jugadores distintos, segundos, promedio por partida y por jugador,
     * mediana por partida, % abandonadas, rechazadas y premios entregados.
     */
    private static function summary(array $rows): array
    {
        $seconds = array_column($rows, 'seconds');
        sort($seconds);
        $n = count($seconds);
        $players = count(array_unique(array_column($rows, 'user_id')));
        $total = array_sum($seconds);
        $count = static fn (string $status) => count(array_filter($rows, static fn (array $r) => $r['status'] === $status));
        return [
            'plays'      => $n,
            'players'    => $players,
            'seconds'    => $total,
            'avg'        => $n ? (int) round($total / $n) : 0,
            'per_player' => $players ? (int) round($total / $players) : 0,
            'median'     => $n ? (int) ($n % 2 ? $seconds[intdiv($n, 2)] : round(($seconds[$n / 2 - 1] + $seconds[$n / 2]) / 2)) : 0,
            'abandoned'  => $n ? round($count('abandoned') / $n * 100, 1) : 0.0,
            'rejected'   => $count('rejected'),
            'rewards'    => array_sum(array_column($rows, 'rewarded')),
        ];
    }

    /**
     * Contexto de espera de cada partida (mismo índice que $rows): 'fila', 'turno' u 'otro'. Trae de una vez los
     * números de la fila y los turnos del período de los usuarios que jugaron (nada de una consulta por partida).
     * @return array<int, string>
     */
    private static function contexts(array $rows, string $from): array
    {
        $users = array_values(array_unique(array_column($rows, 'user_id')));
        $tickets = $appointments = [];
        if ($users) {
            $in = implode(',', array_fill(0, count($users), '?'));
            foreach (Database::select(
                "SELECT user_id, created_at, called_at FROM queue_tickets
                  WHERE day >= ? AND status <> 'cancelled' AND user_id IN ({$in})",
                [substr($from, 0, 10), ...$users]
            ) as $t) {
                $tickets[(int) $t['user_id']][] = [strtotime($t['created_at']), $t['called_at'] ? strtotime($t['called_at']) : null];
            }
            foreach (Database::select(
                "SELECT user_id, starts_at FROM appointments
                  WHERE starts_at >= ? AND status <> 'cancelled' AND user_id IN ({$in})",
                [$from, ...$users]
            ) as $a) {
                $appointments[(int) $a['user_id']][] = strtotime($a['starts_at']);
            }
        }

        $out = [];
        foreach ($rows as $i => $r) {
            $start = strtotime($r['started_at']);
            $out[$i] = 'otro';
            foreach ($tickets[$r['user_id']] ?? [] as [$taken, $called]) {
                // Sacó el número antes de empezar, el mismo día, y todavía no lo habían llamado.
                if ($taken <= $start && date('Y-m-d', $taken) === date('Y-m-d', $start) && ($called === null || $called > $start)) {
                    $out[$i] = 'fila';
                    continue 2;
                }
            }
            foreach ($appointments[$r['user_id']] ?? [] as $at) {
                if ($at >= $start && $at - $start <= self::WAIT_BEFORE && date('Y-m-d', $at) === date('Y-m-d', $start)) {
                    $out[$i] = 'turno';
                    continue 2;
                }
            }
        }
        return $out;
    }

    /** Nombre completo de cada cuenta (id => nombre). */
    private static function names(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (Database::select(
            'SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        ) as $r) {
            $out[(int) $r['id']] = $r['full_name'];
        }
        return $out;
    }

    /** Segundos como texto corto: "45 s", "12 min", "3 h 05 min". */
    public static function duration(int $s): string
    {
        if ($s < 60) {
            return $s . ' s';
        }
        if ($s < 3600) {
            return intdiv($s, 60) . ' min';
        }
        return intdiv($s, 3600) . ' h ' . str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT) . ' min';
    }
}
