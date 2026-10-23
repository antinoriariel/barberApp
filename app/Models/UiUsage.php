<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Uso de la interfaz: clicks/taps por componente, presencia del mouse en PC y visitas por pantalla (tablas ui_clicks,
 * ui_moves y ui_views, migración 022). Lo escribe POST /api/uso (Controllers\UsageController) con los lotes de
 * public/assets/js/track.js y lo lee el mapa de calor del superusuario (/su/uso). Requiere base de datos.
 *
 * Unidades (las mismas que usa track.js; ver la migración):
 * - x: milésimos del ancho de la app (0-1000), así un celular de 360 px y una PC con la columna de 480 px coinciden.
 * - y en la capa 'page': px desde el borde superior de la pantalla escalados a REF_WIDTH de ancho (el contenido es
 *   una sola columna, así que escalar por el ancho es una buena aproximación del mismo punto en otro equipo).
 * - y en la capa 'fixed' (barras y diálogos, que no se mueven con el scroll): milésimos del alto de la ventana.
 *
 * Nada de lo que manda el navegador se guarda sin pasar por sanitize(): pantallas y capas de una lista cerrada,
 * códigos con un alfabeto acotado, números recortados a su rango y topes por lote (config/tracking.php).
 */
final class UiUsage
{
    /** Ancho de referencia (px) al que track.js escala la coordenada y de la capa 'page'. */
    public const REF_WIDTH = 400;

    /** Alto (px de referencia) con que se dibuja la capa 'fixed': una ventana de celular típica (400 x 800). */
    public const FIXED_HEIGHT = 800;

    /** Mayor y aceptada en la capa 'page' (una pantalla de 15.000 px ya es un error de medición). */
    private const MAX_Y = 15000;

    /** Tamaño de las celdas del mouse (track.js): 25 milésimos de ancho x 20 px (o 20 milésimos en 'fixed'). */
    public const MOVE_CELL_X = 25;
    public const MOVE_CELL_Y = 20;

    /** Celda con que se agrupan los clicks para el mapa (en las unidades de x / y): ~2 px de pantalla. */
    private const HEAT_CELL = 5;

    /** Períodos que ofrece el panel (días hacia atrás). */
    public const PERIODS = [1 => 'Hoy', 7 => '7 días', 30 => '30 días', 90 => '90 días'];

    private const LAYERS = ['page', 'fixed'];
    private const DEVICES = ['touch', 'mouse', 'pen'];

    /** Configuración de config/tracking.php (se lee una vez por petición). */
    public static function config(): array
    {
        static $cfg = null;
        return $cfg ??= require BASE_PATH . '/config/tracking.php';
    }

    /**
     * Valida y normaliza un lote de track.js. Formato esperado (todo opcional):
     *   c: [{s: pantalla, l: capa, d: dispositivo, k: componente, i: ítem, t: destino, x, y, b: [x, y, w, h], w: ancho}]
     *   m: [[pantalla, capa, gx, gy, muestras]]   v: [[pantalla, visitas]]
     * Descarta (sin fallar) cada entrada inválida y corta en los topes de config/tracking.php.
     * @return array{clicks: array<int, array>, moves: array<int, array>, views: array<string, int>}
     */
    public static function sanitize(array $raw): array
    {
        $cfg = self::config();
        $screens = $cfg['screens'];
        $out = ['clicks' => [], 'moves' => [], 'views' => []];

        foreach (array_slice(is_array($raw['c'] ?? null) ? $raw['c'] : [], 0, $cfg['limits']['clicks']) as $c) {
            if (!is_array($c) || !self::known($screens, $c['s'] ?? null) || !in_array($c['l'] ?? '', self::LAYERS, true)
                || !in_array($c['d'] ?? '', self::DEVICES, true)) {
                continue;
            }
            $comp = self::code($c['k'] ?? '');
            if ($comp === '') {
                continue;
            }
            $maxY = $c['l'] === 'page' ? self::MAX_Y : 1000;
            $box = is_array($c['b'] ?? null) && count($c['b']) === 4 ? array_values($c['b']) : null;
            $out['clicks'][] = [
                'screen' => $c['s'], 'layer' => $c['l'], 'device' => $c['d'], 'comp' => $comp,
                'item'   => self::code($c['i'] ?? ''), 'dest' => self::dest($c['t'] ?? ''),
                'x'      => self::int($c['x'] ?? null, 0, 1000), 'y' => self::int($c['y'] ?? null, 0, $maxY),
                'bx'     => $box ? self::int($box[0], 0, 1000) : null, 'by' => $box ? self::int($box[1], 0, $maxY) : null,
                'bw'     => $box ? self::int($box[2], 0, 1000) : null, 'bh' => $box ? self::int($box[3], 0, $maxY) : null,
                'vw'     => self::int($c['w'] ?? 0, 0, 10000),
            ];
        }

        foreach (array_slice(is_array($raw['m'] ?? null) ? $raw['m'] : [], 0, $cfg['limits']['moves']) as $m) {
            $m = is_array($m) ? array_values($m) : [];
            if (count($m) !== 5 || !self::known($screens, $m[0]) || !in_array($m[1], self::LAYERS, true)) {
                continue;
            }
            $maxGy = intdiv($m[1] === 'page' ? self::MAX_Y : 1000, self::MOVE_CELL_Y);
            $n = self::int($m[4], 0, 240); // un lote cubre como mucho ~60 s: 240 muestras de 250 ms
            if ($n > 0) {
                $out['moves'][] = [$m[0], $m[1], self::int($m[2], 0, intdiv(1000, self::MOVE_CELL_X)), self::int($m[3], 0, $maxGy), $n];
            }
        }

        foreach (array_slice(is_array($raw['v'] ?? null) ? $raw['v'] : [], 0, 50) as $v) {
            $v = is_array($v) ? array_values($v) : [];
            if (count($v) === 2 && self::known($screens, $v[0])) {
                $out['views'][$v[0]] = min(50, ($out['views'][$v[0]] ?? 0) + self::int($v[1], 0, 50));
            }
        }
        $out['views'] = array_filter($out['views']);
        return $out;
    }

    /**
     * Guarda un lote ya normalizado (sanitize) del usuario: los clicks en un solo INSERT de varias filas, y el mouse y
     * las visitas sumándose a la fila del día (INSERT … ON DUPLICATE KEY UPDATE). Todo en una transacción.
     * @return int cuántos registros entraron (clicks + celdas + pantallas)
     */
    public static function record(int $userId, array $batch, ?int $now = null): int
    {
        $now ??= time();
        $at = date('Y-m-d H:i:s', $now);
        $day = date('Y-m-d', $now);
        Database::transaction(function () use ($userId, $batch, $at, $day) {
            if ($batch['clicks']) {
                $rows = $params = [];
                foreach ($batch['clicks'] as $c) {
                    $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, $userId, $c['screen'], $c['layer'], $c['device'], $c['comp'], $c['item'], $c['dest'],
                        $c['x'], $c['y'], $c['bx'], $c['by'], $c['bw'], $c['bh'], $c['vw'], $at);
                }
                Database::execute(
                    'INSERT INTO ui_clicks (user_id, screen, layer, device, comp, item, dest, x, y, bx, `by`, bw, bh, vw, created_at)
                     VALUES ' . implode(', ', $rows),
                    $params
                );
            }
            if ($batch['moves']) {
                $rows = $params = [];
                foreach ($batch['moves'] as [$screen, $layer, $gx, $gy, $n]) {
                    $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, $day, $userId, $screen, $layer, $gx, $gy, $n);
                }
                Database::execute(
                    'INSERT INTO ui_moves (day, user_id, screen, layer, gx, gy, samples) VALUES ' . implode(', ', $rows)
                    . ' ON DUPLICATE KEY UPDATE samples = samples + VALUES(samples)',
                    $params
                );
            }
            foreach ($batch['views'] as $screen => $n) {
                Database::execute(
                    'INSERT INTO ui_views (day, user_id, screen, views) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE views = views + VALUES(views)',
                    [$day, $userId, $screen, $n]
                );
            }
        });
        return count($batch['clicks']) + count($batch['moves']) + count($batch['views']);
    }

    /**
     * Filtros del panel a partir de la query (?dias, pantalla, capa, disp, modo, peso), siempre con valores válidos:
     *  - dias: clave de PERIODS (30 por defecto); pantalla: de config/tracking.php ('inicio');
     *  - capa: 'page' (contenido con scroll) o 'fixed' (barras y diálogos);
     *  - disp: '' (todos), 'touch' o 'mouse'; modo: 'clicks' o 'mouse' (presencia del mouse, solo PC);
     *  - peso: 'usuario' (cada persona pesa lo mismo: promedio entre usuarios) o 'total' (suma cruda).
     * @return array{dias: int, pantalla: string, capa: string, disp: string, modo: string, peso: string, from: string}
     */
    public static function filters(array $q): array
    {
        $pick = static fn (string $key, array $allowed, string $default): string =>
            in_array($v = (string) ($q[$key] ?? ''), $allowed, true) ? $v : $default;
        $days = (int) ($q['dias'] ?? 30);
        $days = isset(self::PERIODS[$days]) ? $days : 30;
        return [
            'dias'     => $days,
            'pantalla' => $pick('pantalla', array_keys(self::config()['screens']), 'inicio'),
            'capa'     => $pick('capa', self::LAYERS, 'page'),
            'disp'     => $pick('disp', ['touch', 'mouse'], ''),
            'modo'     => $pick('modo', ['clicks', 'mouse'], 'clicks'),
            'peso'     => $pick('peso', ['usuario', 'total'], 'usuario'),
            'from'     => date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')),
        ];
    }

    /** Condición SQL común de los clicks según los filtros (pantalla, período y dispositivo). Siempre con parámetros. */
    private static function clickWhere(array $f, bool $withLayer): array
    {
        $where = ['screen = ?', 'created_at >= ?'];
        $params = [$f['pantalla'], $f['from']];
        if ($withLayer) {
            $where[] = 'layer = ?';
            $params[] = $f['capa'];
        }
        if ($f['disp'] === 'touch') {
            $where[] = "device IN ('touch','pen')";
        } elseif ($f['disp'] === 'mouse') {
            $where[] = "device = 'mouse'";
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Puntos del mapa de calor para los filtros: [[x, y, peso], …] en las unidades de la capa (x en milésimos, y en px
     * de referencia o milésimos). La base agrupa por usuario y celda (así viajan cientos de filas, no cada click) y acá
     * se aplica el peso:
     *  - 'usuario': cada persona suma 1 en total, repartido entre sus celdas (el mapa es el promedio de todos los
     *    usuarios y alguien que toca 500 veces no tapa al resto);
     *  - 'total': cada click (o cada muestra de 250 ms del mouse) suma 1.
     * @return array{points: array<int, array{0: int, 1: int, 2: float}>, users: int, events: int, height: int}
     */
    public static function heat(array $f): array
    {
        if ($f['modo'] === 'mouse') {
            $rows = Database::select(
                'SELECT user_id, gx, gy, SUM(samples) AS n FROM ui_moves
                  WHERE screen = ? AND layer = ? AND day >= ? GROUP BY user_id, gx, gy',
                [$f['pantalla'], $f['capa'], substr($f['from'], 0, 10)]
            );
            $cx = static fn (array $r): int => (int) $r['gx'] * self::MOVE_CELL_X + intdiv(self::MOVE_CELL_X, 2);
            $cy = static fn (array $r): int => (int) $r['gy'] * self::MOVE_CELL_Y + intdiv(self::MOVE_CELL_Y, 2);
        } else {
            [$where, $params] = self::clickWhere($f, true);
            $cell = self::HEAT_CELL;
            $rows = Database::select(
                "SELECT user_id, x DIV {$cell} AS gx, y DIV {$cell} AS gy, COUNT(*) AS n FROM ui_clicks
                  WHERE {$where} GROUP BY user_id, gx, gy",
                $params
            );
            $cx = static fn (array $r): int => (int) $r['gx'] * $cell + intdiv($cell, 2);
            $cy = static fn (array $r): int => (int) $r['gy'] * $cell + intdiv($cell, 2);
        }

        $perUser = [];
        foreach ($rows as $r) {
            $perUser[$r['user_id']] = ($perUser[$r['user_id']] ?? 0) + (int) $r['n'];
        }
        $cells = [];
        $events = 0;
        $maxY = 0;
        foreach ($rows as $r) {
            $n = (int) $r['n'];
            $events += $n;
            $key = $cx($r) . ':' . $cy($r);
            $w = $f['peso'] === 'usuario' ? $n / $perUser[$r['user_id']] : $n;
            $cells[$key] = ($cells[$key] ?? 0) + $w;
        }
        $points = [];
        foreach ($cells as $key => $w) {
            [$x, $y] = array_map('intval', explode(':', $key));
            $points[] = [$x, $y, round($w, 4)];
            $maxY = max($maxY, $y);
        }
        $height = $f['capa'] === 'fixed' ? self::FIXED_HEIGHT : max(800, min(self::MAX_Y, $maxY + 60));
        return ['points' => $points, 'users' => count($perUser), 'events' => $events, 'height' => $height];
    }

    /**
     * Plano de la pantalla: caja promedio de cada componente (en las unidades de la capa) con su cantidad de clicks,
     * para dibujarla debajo del calor y saber qué hay en cada lugar sin una captura de pantalla.
     * @return array<int, array{comp: string, label: string, x: int, y: int, w: int, h: int, clicks: int}>
     */
    public static function boxes(array $f): array
    {
        [$where, $params] = self::clickWhere($f, true);
        $labels = self::config()['components'];
        return array_map(static fn (array $r) => [
            'comp'   => $r['comp'],
            'label'  => $labels[$r['comp']] ?? $r['comp'],
            'x'      => (int) round((float) $r['bx']), 'y' => (int) round((float) $r['by']),
            'w'      => (int) round((float) $r['bw']), 'h' => (int) round((float) $r['bh']),
            'clicks' => (int) $r['n'],
        ], Database::select(
            "SELECT comp, AVG(bx) AS bx, AVG(`by`) AS `by`, AVG(bw) AS bw, AVG(bh) AS bh, COUNT(*) AS n FROM ui_clicks
              WHERE {$where} AND bx IS NOT NULL AND comp <> 'fondo' GROUP BY comp ORDER BY n DESC LIMIT 60",
            $params
        ));
    }

    /**
     * Ranking de componentes de la pantalla (ambas capas): clicks, personas distintas, % del total de la pantalla,
     * clicks cada 100 visitas, reparto táctil/mouse, destino más frecuente y los ítems más tocados.
     * @return array<int, array{comp: string, label: string, layer: string, clicks: int, users: int, share: float,
     *                          per100: ?float, touch: int, mouse: int, dest: string, items: array<int, array{0: string, 1: int}>}>
     */
    public static function components(array $f): array
    {
        [$where, $params] = self::clickWhere($f, false);
        $rows = Database::select(
            "SELECT comp, MAX(layer) AS layer, COUNT(*) AS n, COUNT(DISTINCT user_id) AS users,
                    SUM(device = 'mouse') AS mouse
               FROM ui_clicks WHERE {$where} GROUP BY comp ORDER BY n DESC LIMIT 80",
            $params
        );
        $total = array_sum(array_map(static fn (array $r) => (int) $r['n'], $rows));
        $views = self::screenViews($f);

        $dests = $items = [];
        foreach (Database::select(
            "SELECT comp, dest, COUNT(*) AS n FROM ui_clicks WHERE {$where} AND dest <> '' GROUP BY comp, dest ORDER BY n DESC",
            $params
        ) as $r) {
            $dests[$r['comp']] ??= $r['dest']; // el primero es el más frecuente
        }
        foreach (Database::select(
            "SELECT comp, item, COUNT(*) AS n FROM ui_clicks WHERE {$where} AND item <> '' GROUP BY comp, item ORDER BY n DESC",
            $params
        ) as $r) {
            if (count($items[$r['comp']] ?? []) < 4) {
                $items[$r['comp']][] = [$r['item'], (int) $r['n']];
            }
        }

        $labels = self::config()['components'];
        return array_map(static fn (array $r) => [
            'comp'   => $r['comp'],
            'label'  => $labels[$r['comp']] ?? (str_starts_with($r['comp'], 'auto:') ? 'Sin código: ' . substr($r['comp'], 5) : $r['comp']),
            'layer'  => $r['layer'],
            'clicks' => (int) $r['n'],
            'users'  => (int) $r['users'],
            'share'  => $total > 0 ? round((int) $r['n'] / $total * 100, 1) : 0.0,
            'per100' => $views > 0 ? round((int) $r['n'] / $views * 100, 1) : null,
            'mouse'  => (int) $r['mouse'],
            'touch'  => (int) $r['n'] - (int) $r['mouse'],
            'dest'   => $dests[$r['comp']] ?? '',
            'items'  => $items[$r['comp']] ?? [],
        ], $rows);
    }

    /** Visitas a la pantalla del filtro en el período (para "clicks cada 100 visitas"). */
    private static function screenViews(array $f): int
    {
        return (int) (Database::selectOne(
            'SELECT COALESCE(SUM(views), 0) AS n FROM ui_views WHERE screen = ? AND day >= ?',
            [$f['pantalla'], substr($f['from'], 0, 10)]
        )['n'] ?? 0);
    }

    /**
     * Resumen por pantalla en el período: visitas, personas que la abrieron, clicks, personas que tocaron algo,
     * clicks muertos (comp 'fondo') y muestras de mouse. Pantallas sin datos salen en 0 (en el orden del catálogo).
     * @return array<string, array{label: string, views: int, viewers: int, clicks: int, clickers: int, dead: int, mouse: int}>
     */
    public static function screens(string $from): array
    {
        $out = [];
        foreach (self::config()['screens'] as $key => $label) {
            $out[$key] = ['label' => $label, 'views' => 0, 'viewers' => 0, 'clicks' => 0, 'clickers' => 0, 'dead' => 0, 'mouse' => 0];
        }
        $day = substr($from, 0, 10);
        foreach (Database::select(
            'SELECT screen, SUM(views) AS views, COUNT(DISTINCT user_id) AS viewers FROM ui_views WHERE day >= ? GROUP BY screen',
            [$day]
        ) as $r) {
            if (isset($out[$r['screen']])) {
                $out[$r['screen']]['views'] = (int) $r['views'];
                $out[$r['screen']]['viewers'] = (int) $r['viewers'];
            }
        }
        foreach (Database::select(
            "SELECT screen, COUNT(*) AS clicks, COUNT(DISTINCT user_id) AS clickers, SUM(comp = 'fondo') AS dead
               FROM ui_clicks WHERE created_at >= ? GROUP BY screen",
            [$from]
        ) as $r) {
            if (isset($out[$r['screen']])) {
                $out[$r['screen']]['clicks'] = (int) $r['clicks'];
                $out[$r['screen']]['clickers'] = (int) $r['clickers'];
                $out[$r['screen']]['dead'] = (int) $r['dead'];
            }
        }
        foreach (Database::select('SELECT screen, SUM(samples) AS n FROM ui_moves WHERE day >= ? GROUP BY screen', [$day]) as $r) {
            if (isset($out[$r['screen']])) {
                $out[$r['screen']]['mouse'] = (int) $r['n'];
            }
        }
        return $out;
    }

    /**
     * Totales del período para las tarjetas: clicks, personas distintas, % táctil y clicks muertos.
     * @return array{clicks: int, users: int, touch: int, dead: int}
     */
    public static function totals(string $from): array
    {
        $r = Database::selectOne(
            "SELECT COUNT(*) AS clicks, COUNT(DISTINCT user_id) AS users, COALESCE(SUM(device <> 'mouse'), 0) AS touch,
                    COALESCE(SUM(comp = 'fondo'), 0) AS dead
               FROM ui_clicks WHERE created_at >= ?",
            [$from]
        ) ?? [];
        return ['clicks' => (int) ($r['clicks'] ?? 0), 'users' => (int) ($r['users'] ?? 0),
                'touch' => (int) ($r['touch'] ?? 0), 'dead' => (int) ($r['dead'] ?? 0)];
    }

    /**
     * Borra lo más viejo que `$days` días de las tres tablas. Devuelve cuántas filas se fueron en total.
     */
    public static function purgeOlderThan(int $days): int
    {
        $from = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
        return Database::execute('DELETE FROM ui_clicks WHERE created_at < ?', [$from])
             + Database::execute('DELETE FROM ui_moves WHERE day < ?', [substr($from, 0, 10)])
             + Database::execute('DELETE FROM ui_views WHERE day < ?', [substr($from, 0, 10)]);
    }

    /** Cuántas filas hay guardadas y desde cuándo (para la sección de retención). */
    public static function size(): array
    {
        $r = Database::selectOne('SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM ui_clicks') ?? [];
        $m = Database::selectOne('SELECT COUNT(*) AS n FROM ui_moves') ?? [];
        return ['clicks' => (int) ($r['n'] ?? 0), 'moves' => (int) ($m['n'] ?? 0), 'oldest' => $r['oldest'] ?? null];
    }

    /**
     * Código de componente o ítem: minúsculas, dígitos y . _ : - (hasta 60). Cualquier otro carácter se descarta, así
     * un código armado a mano no puede meter texto libre (ni datos personales) en la base.
     */
    private static function code(mixed $v): string
    {
        return is_string($v) ? substr(preg_replace('/[^a-z0-9._:\-]/', '', strtolower($v)) ?? '', 0, 60) : '';
    }

    /**
     * Destino de un click: una ruta interna ("/admin") o un #hash ("#reservar"). Se corta la query y todo lo que no sea
     * ruta (un enlace como /fila?c=… no deja su código en la base) y se descartan los destinos externos.
     */
    private static function dest(mixed $v): string
    {
        if (!is_string($v) || !preg_match('~^(/(?![/\\\\])[a-z0-9/_\-]*|#[a-z0-9_\-]+)~i', $v, $m)) {
            return '';
        }
        return substr($m[1], 0, 120);
    }

    /** ¿$key es texto y es una de las claves de $list? (un arreglo como clave haría fallar a isset). */
    private static function known(array $list, mixed $key): bool
    {
        return is_string($key) && isset($list[$key]);
    }

    /** Entero dentro de [$min, $max]; lo que no sea número vale $min. */
    private static function int(mixed $v, int $min, int $max): int
    {
        return is_numeric($v) ? max($min, min($max, (int) round((float) $v))) : $min;
    }
}
