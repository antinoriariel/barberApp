<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Métricas del panel de administración (solo con base de datos).
 *
 * Los ingresos son la suma de `price` de los turnos en estado 'done' (atendidos) del día; los turnos
 * reservados, cancelados o ausentes no cuentan. El día se toma por `starts_at`.
 */
final class Dashboard
{
    /** Inicio (inclusive) y fin (exclusivo) SQL de un día "YYYY-MM-DD". */
    private static function bounds(string $day): array
    {
        return [$day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00'];
    }

    /**
     * Ingresos y turnos atendidos de un día, por barbero activo (incluye a los que no atendieron: 0).
     * @return array{total: int, served: int, ticket: int, barbers: array<int, array{id: int, name: string, tone: string, served: int, revenue: int}>}
     *         "ticket" es el valor promedio por turno atendido; "barbers" va de mayor a menor ingreso
     */
    public static function day(string $day): array
    {
        [$from, $to] = self::bounds($day);
        $rows = Database::select(
            "SELECT b.id, b.name, b.tone, COUNT(a.id) AS served, COALESCE(SUM(a.price), 0) AS revenue
               FROM barbers b
               LEFT JOIN appointments a ON a.barber_id = b.id AND a.status = 'done'
                                        AND a.starts_at >= ? AND a.starts_at < ?
              WHERE b.active = 1
              GROUP BY b.id, b.name, b.tone
              ORDER BY revenue DESC, b.name",
            [$from, $to]
        );
        $barbers = array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'tone' => $r['tone'],
            'served' => (int) $r['served'], 'revenue' => (int) $r['revenue'],
        ], $rows);

        $total = array_sum(array_column($barbers, 'revenue'));
        $served = array_sum(array_column($barbers, 'served'));
        return ['total' => $total, 'served' => $served, 'ticket' => $served ? intdiv($total, $served) : 0, 'barbers' => $barbers];
    }

    /**
     * Ingresos y turnos atendidos de los últimos `$count` meses, terminando en el mes de `$day` (el más viejo primero).
     * Los meses sin turnos atendidos salen con 0. Solo hay precios de turnos: no se registran costos ni comisiones,
     * así que "neto" acá es lo facturado por turnos atendidos (sin cancelados, ausentes ni reservas pendientes).
     * @return array<int, array{month: string, revenue: int, served: int}> month es "YYYY-MM"
     */
    public static function months(string $day, int $count = 6): array
    {
        $first = date('Y-m-01', strtotime(substr($day, 0, 7) . '-01 -' . ($count - 1) . ' months'));
        $rows = Database::select(
            "SELECT DATE_FORMAT(starts_at, '%Y-%m') AS m, COUNT(*) AS served, SUM(price) AS revenue
               FROM appointments
              WHERE status = 'done' AND starts_at >= ? AND starts_at < ?
              GROUP BY m",
            [$first . ' 00:00:00', date('Y-m-01', strtotime(substr($day, 0, 7) . '-01 +1 month')) . ' 00:00:00']
        );
        $by = array_column($rows, null, 'm');
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $m = date('Y-m', strtotime($first . " +{$i} months"));
            $out[] = ['month' => $m, 'revenue' => (int) ($by[$m]['revenue'] ?? 0), 'served' => (int) ($by[$m]['served'] ?? 0)];
        }
        return $out;
    }

    /**
     * Ingresos y egresos de los últimos `$count` meses (terminando en el de `$day`), el más viejo primero.
     * Ingresos = turnos atendidos (months()); egresos = tabla `expenses`; net = ingresos − egresos.
     * @return array<int, array{month: string, revenue: int, served: int, expenses: int, net: int}>
     */
    public static function finance(string $day, int $count = 6): array
    {
        $months = self::months($day, $count);
        $spent = Expense::totalsByMonth($months[0]['month'] . '-01', $months[$count - 1]['month']);
        return array_map(static function (array $m) use ($spent) {
            $m['expenses'] = $spent[$m['month']] ?? 0;
            $m['net'] = $m['revenue'] - $m['expenses'];
            return $m;
        }, $months);
    }

    /**
     * Cortes atendidos por barbero en la semana (lunes a domingo) que contiene `$day`.
     * @return array{from: string, to: string, days: string[], total: int, barbers: array<int, array{id: int, name: string, tone: string, perDay: int[], total: int, revenue: int}>}
     *         "days" son las 7 fechas YYYY-MM-DD; "barbers" va de más a menos cortes
     */
    public static function week(string $day): array
    {
        $monday = date('Y-m-d', strtotime($day . ' -' . ((int) date('N', strtotime($day)) - 1) . ' days'));
        $days = array_map(static fn (int $i) => date('Y-m-d', strtotime($monday . " +{$i} days")), range(0, 6));
        $rows = Database::select(
            "SELECT b.id, b.name, b.tone, DATE(a.starts_at) AS d, COUNT(a.id) AS n, COALESCE(SUM(a.price), 0) AS revenue
               FROM barbers b
               LEFT JOIN appointments a ON a.barber_id = b.id AND a.status = 'done'
                                        AND a.starts_at >= ? AND a.starts_at < ?
              WHERE b.active = 1
              GROUP BY b.id, b.name, b.tone, d",
            [$monday . ' 00:00:00', date('Y-m-d', strtotime($monday . ' +7 days')) . ' 00:00:00']
        );
        $by = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $by[$id] ??= ['id' => $id, 'name' => $r['name'], 'tone' => $r['tone'], 'perDay' => array_fill(0, 7, 0), 'total' => 0, 'revenue' => 0];
            if ($r['d'] !== null) {
                $by[$id]['perDay'][(int) array_search($r['d'], $days, true)] = (int) $r['n'];
                $by[$id]['total'] += (int) $r['n'];
                $by[$id]['revenue'] += (int) $r['revenue'];
            }
        }
        $barbers = array_values($by);
        usort($barbers, static fn (array $a, array $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
        return ['from' => $days[0], 'to' => $days[6], 'days' => $days, 'total' => array_sum(array_column($barbers, 'total')), 'barbers' => $barbers];
    }

    /**
     * Turnos del día con cliente, servicio y barbero (para marcarlos como atendidos).
     * @return array<int, array{id: int, time: string, client: string, service: string, barber: string, price: int, status: string}>
     */
    public static function appointments(string $day): array
    {
        [$from, $to] = self::bounds($day);
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'time' => date('H:i', strtotime($r['starts_at'])), 'client' => $r['client'],
            'service' => $r['service'], 'barber' => $r['barber'], 'price' => (int) $r['price'], 'status' => $r['status'],
        ], Database::select(
            'SELECT a.id, a.starts_at, a.price, a.status, u.full_name AS client, ' . Appointment::SERVICE_NAMES_SQL . ' AS service, b.name AS barber
               FROM appointments a
               JOIN users u ON u.id = a.user_id
               JOIN services s ON s.id = a.service_id
               JOIN barbers b ON b.id = a.barber_id
              WHERE a.starts_at >= ? AND a.starts_at < ?
              ORDER BY a.starts_at, b.name',
            [$from, $to]
        ));
    }

    /**
     * Marca un turno reservado como atendido ('done') o ausente ('no_show').
     * Solo cambia turnos que siguen en 'booked'; devuelve false si no existía o ya tenía otro estado.
     */
    public static function mark(int $id, string $status): bool
    {
        if (!in_array($status, ['done', 'no_show'], true)) {
            return false;
        }
        return Database::execute("UPDATE appointments SET status = ? WHERE id = ? AND status = 'booked'", [$status, $id]) === 1;
    }

    /**
     * Compara un valor contra el del día anterior.
     * @return array{diff: int, pct: ?float, trend: string} trend: 'up' | 'down' | 'flat' | 'new' (ayer 0 y hoy > 0)
     *         pct es null cuando el valor de ayer es 0
     */
    public static function delta(int $today, int $yesterday): array
    {
        $diff = $today - $yesterday;
        $trend = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat');
        if ($yesterday === 0 && $today > 0) {
            $trend = 'new';
        }
        return ['diff' => $diff, 'pct' => $yesterday > 0 ? round($diff / $yesterday * 100, 1) : null, 'trend' => $trend];
    }

    /**
     * Evaluación en palabras de cómo le fue al local frente al día anterior (ingresos y turnos atendidos),
     * con el barbero que más facturó y el que más mejoró.
     *
     * @param array $today     resultado de day() del día mostrado
     * @param array $yesterday resultado de day() del día anterior
     */
    public static function verdict(array $today, array $yesterday): string
    {
        if ($today['served'] === 0 && $yesterday['served'] === 0) {
            return 'Todavía no hay turnos atendidos ni hoy ni el día anterior para comparar.';
        }
        $rev = self::delta($today['total'], $yesterday['total']);
        $cnt = self::delta($today['served'], $yesterday['served']);

        $text = match ($rev['trend']) {
            'new'  => 'Ayer no hubo ventas y hoy se facturaron en total: ' . money($today['total']) . '.',
            'up'   => 'Las ventas subieron ' . self::pct($rev['pct']) . ' respecto del día anterior (+' . money($rev['diff']) . ').',
            'down' => 'Las ventas bajaron ' . self::pct(abs((float) $rev['pct'])) . ' respecto del día anterior (−' . money(abs($rev['diff'])) . ').',
            default => 'Las ventas fueron iguales a las del día anterior.',
        };
        $served = ' ' . self::servedPhrase($today['served']);
        $text .= match ($cnt['trend']) {
            'new'  => $served . ' (ayer ninguno).',
            'up'   => $served . ', ' . $cnt['diff'] . ' más que ayer.',
            'down' => $served . ', ' . abs($cnt['diff']) . ' menos que ayer.',
            default => $served . ', igual que ayer.',
        };

        if ($today['total'] > 0) {
            $text .= ' Quien más facturó: ' . $today['barbers'][0]['name'] . ' (' . money($today['barbers'][0]['revenue']) . ').';
        }
        // Si ayer no hubo ventas, "quien más mejoró" repetiría a quien más facturó: se omite.
        if ($yesterday['total'] === 0) {
            return $text;
        }
        $before = array_column($yesterday['barbers'], 'revenue', 'id');
        $best = null;
        foreach ($today['barbers'] as $b) {
            $gain = $b['revenue'] - ($before[$b['id']] ?? 0);
            if ($gain > 0 && ($best === null || $gain > $best[1])) {
                $best = [$b['name'], $gain];
            }
        }
        return $best ? $text . ' Quien más mejoró: ' . $best[0] . ' (+' . money($best[1]) . ').' : $text;
    }

    /** Frase de turnos atendidos con número y verbo concordados: 1 → "Se atendió 1 turno", 3 → "Se atendieron 3 turnos". */
    private static function servedPhrase(int $n): string
    {
        return $n === 1 ? 'Se atendió 1 turno' : 'Se atendieron ' . $n . ' turnos';
    }

    /** Porcentaje con coma decimal: 12.5 → "12,5%". */
    private static function pct(?float $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',') . '%';
    }
}
