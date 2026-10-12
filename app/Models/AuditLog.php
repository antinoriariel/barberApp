<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Consulta del registro de auditoría (audit_log) para el superusuario: búsqueda con filtros y paginado, exportación,
 * alertas de seguridad (fuerza bruta, cuentas usadas desde muchas IP, navegadores nuevos, accesos del superusuario) y
 * retención (borrar lo viejo). Escribir eventos sigue siendo cosa de Services\Audit. Solo con base de datos.
 */
final class AuditLog
{
    /** Filas por página en /su/auditoria. */
    public const PER_PAGE = 50;

    /** Tope de filas de una exportación CSV (para no armar archivos gigantes por error). */
    public const EXPORT_MAX = 10000;

    /** Fallos de login en la ventana a partir de los cuales una IP o un DNI aparece como sospechoso. */
    public const ALERT_FAILS = 3;

    /** IP distintas en la ventana a partir de las cuales una cuenta aparece como sospechosa (¿cuenta compartida o robada?). */
    public const ALERT_IPS = 3;

    /** Eventos de login y cuenta (incluye los del superusuario). */
    private const LOGIN_EVENTS = ['login_ok', 'login_fail', 'login_throttled', 'logout', 'logout_all', 'register',
        'register_blocked_device', 'register_blocked_ip', 'password_reset_request', 'password_reset', 'su_login_ok', 'su_logout'];

    /** Grupos del filtro "Tipo" (clave => [etiqueta, condición SQL sobre a.event sin datos del usuario]). */
    public const GROUPS = [
        'fallos'  => ['Logins fallidos y bloqueos', "a.event IN ('login_fail', 'login_throttled')"],
        'login'   => ['Ingresos, salidas y contraseñas', 'LOGIN'],
        'registro' => ['Altas de cuenta y rechazos', "(a.event LIKE 'register%' OR a.event = 'su_registration_release')"],
        'turnos'  => ['Turnos', "(a.event LIKE 'appointment\\_%' OR a.event LIKE 'admin\\_appointment\\_%')"],
        'fila'    => ['Fila', "(a.event LIKE 'queue\\_%' OR a.event LIKE 'admin\\_queue\\_%')"],
        'panel'   => ['Panel de administración', "a.event LIKE 'admin\\_%'"],
        'su'      => ['Acciones del superusuario', "a.event LIKE 'su\\_%'"],
    ];

    /**
     * Normaliza los filtros que llegan por la query string: grupo (clave de GROUPS), usuario (id), ip, q (texto que se
     * busca en nombre, DNI, teléfono y detalle), desde / hasta (fechas Y-m-d reales). Lo que no es válido se descarta.
     * @return array{grupo: string, usuario: int, ip: string, q: string, desde: string, hasta: string}
     */
    public static function filters(array $in): array
    {
        $text = static fn (string $k, int $max): string => is_string($in[$k] ?? null) ? mb_substr(trim($in[$k]), 0, $max) : '';
        $date = static function (string $raw): string {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            return $d && $d->format('Y-m-d') === $raw ? $raw : '';
        };
        $grupo = $text('grupo', 20);
        return [
            'grupo'   => isset(self::GROUPS[$grupo]) ? $grupo : '',
            'usuario' => max(0, (int) $text('usuario', 10)),
            'ip'      => $text('ip', 45),
            'q'       => $text('q', 60),
            'desde'   => $date($text('desde', 10)),
            'hasta'   => $date($text('hasta', 10)),
        ];
    }

    /**
     * Arma el WHERE de los filtros con parámetros (nunca interpola lo que escribió el usuario). El texto libre usa LIKE
     * con %, _ y \ escapados.
     * @return array{0: string, 1: array}
     */
    private static function where(array $f): array
    {
        $sql = ['1 = 1'];
        $params = [];
        if ($f['grupo'] !== '') {
            $cond = self::GROUPS[$f['grupo']][1];
            if ($cond === 'LOGIN') {
                $cond = 'a.event IN (' . implode(', ', array_fill(0, count(self::LOGIN_EVENTS), '?')) . ')';
                array_push($params, ...self::LOGIN_EVENTS);
            }
            $sql[] = $cond;
        }
        if ($f['usuario'] > 0) {
            $sql[] = 'a.user_id = ?';
            $params[] = $f['usuario'];
        }
        if ($f['ip'] !== '') {
            $sql[] = 'a.ip = ?';
            $params[] = $f['ip'];
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $sql[] = '(u.full_name LIKE ? OR u.dni LIKE ? OR u.phone LIKE ? OR a.detail LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if ($f['desde'] !== '') {
            $sql[] = 'a.created_at >= ?';
            $params[] = $f['desde'] . ' 00:00:00';
        }
        if ($f['hasta'] !== '') {
            $sql[] = 'a.created_at < ?';
            $params[] = date('Y-m-d', strtotime($f['hasta'] . ' +1 day')) . ' 00:00:00';
        }
        return [implode(' AND ', $sql), $params];
    }

    /**
     * Página $page (desde 1) del registro con esos filtros, lo más reciente primero, con el nombre y DNI de la cuenta.
     * @return array{rows: array<int, array>, total: int, pages: int}
     */
    public static function search(array $filters, int $page = 1): array
    {
        [$where, $params] = self::where($filters);
        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS n FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE $where", $params
        )['n'];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $offset = (min(max(1, $page), $pages) - 1) * self::PER_PAGE;
        $rows = Database::select(
            "SELECT a.id, a.user_id, a.event, a.ip, a.user_agent, a.detail, a.created_at, u.full_name, u.dni
               FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
              WHERE $where ORDER BY a.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . $offset,
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
    }

    /**
     * Todas las filas de esos filtros (hasta EXPORT_MAX), lo más reciente primero, para el CSV.
     * @return array<int, array>
     */
    public static function export(array $filters): array
    {
        [$where, $params] = self::where($filters);
        return Database::select(
            "SELECT a.created_at, a.event, a.user_id, u.full_name, u.dni, a.ip, a.user_agent, a.detail
               FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
              WHERE $where ORDER BY a.id DESC LIMIT " . self::EXPORT_MAX,
            $params
        );
    }

    /** Quién hizo una acción, para mostrar: la cuenta, "Superusuario" (eventos su_*) o "—" (p. ej. un login fallido). */
    public static function actor(array $row): string
    {
        if (!empty($row['full_name'])) {
            return $row['full_name'];
        }
        if (str_starts_with((string) $row['event'], 'su_')) {
            return 'Superusuario';
        }
        return $row['user_id'] ? 'Cuenta eliminada #' . $row['user_id'] : '—';
    }

    /** Fecha desde la que miran las alertas: hace $hours horas. */
    private static function since(int $hours): string
    {
        return date('Y-m-d H:i:s', time() - $hours * 3600);
    }

    /**
     * Totales de la ventana para las tarjetas de /su/seguridad: logins fallidos, bloqueos por intentos, IP distintas con
     * fallos, logins correctos y navegadores nuevos.
     * @return array{fails: int, throttled: int, fail_ips: int, logins: int, new_devices: int}
     */
    public static function totals(int $hours = 24): array
    {
        $since = self::since($hours);
        $r = Database::selectOne(
            "SELECT SUM(event = 'login_fail') AS fails, SUM(event = 'login_throttled') AS throttled,
                    COUNT(DISTINCT CASE WHEN event = 'login_fail' THEN ip END) AS fail_ips, SUM(event = 'login_ok') AS logins
               FROM audit_log WHERE created_at >= ?",
            [$since]
        );
        $devices = Database::selectOne('SELECT COUNT(*) AS n FROM user_devices WHERE first_seen_at >= ?', [$since]);
        return [
            'fails' => (int) $r['fails'], 'throttled' => (int) $r['throttled'], 'fail_ips' => (int) $r['fail_ips'],
            'logins' => (int) $r['logins'], 'new_devices' => (int) $devices['n'],
        ];
    }

    /**
     * IP con ALERT_FAILS o más logins fallidos (o bloqueados por intentos) en la ventana: posible fuerza bruta.
     * Incluye cuántos DNI distintos probó (muchos = barrido de cuentas) y el último intento.
     * @return array<int, array{ip: string, fails: int, dnis: int, last_at: string}>
     */
    public static function suspiciousIps(int $hours = 24): array
    {
        return Database::select(
            "SELECT ip, COUNT(*) AS fails, COUNT(DISTINCT detail) AS dnis, MAX(created_at) AS last_at
               FROM audit_log WHERE event IN ('login_fail', 'login_throttled') AND created_at >= ?
              GROUP BY ip HAVING fails >= ? ORDER BY fails DESC, last_at DESC LIMIT 30",
            [self::since($hours), self::ALERT_FAILS]
        );
    }

    /**
     * DNI (o "superuser") con ALERT_FAILS o más logins fallidos en la ventana: alguien intenta adivinar esa contraseña.
     * Trae el nombre si la cuenta existe y desde cuántas IP distintas lo intentaron. (users usa utf8mb4_unicode_ci y
     * audit_log utf8mb4_general_ci: el COLLATE explícito evita el error de "mezcla de collations" al unirlas.)
     * @return array<int, array{dni: string, fails: int, ips: int, last_at: string, full_name: ?string, user_id: ?int}>
     */
    public static function targetedAccounts(int $hours = 24): array
    {
        return Database::select(
            "SELECT f.dni, f.fails, f.ips, f.last_at, u.full_name, u.id AS user_id FROM (
                SELECT detail AS dni, COUNT(*) AS fails, COUNT(DISTINCT ip) AS ips, MAX(created_at) AS last_at
                  FROM audit_log WHERE event = 'login_fail' AND created_at >= ? AND detail <> ''
                 GROUP BY detail HAVING fails >= ?
             ) f LEFT JOIN users u ON u.dni = f.dni COLLATE utf8mb4_unicode_ci
             ORDER BY f.fails DESC, f.last_at DESC LIMIT 30",
            [self::since($hours), self::ALERT_FAILS]
        );
    }

    /**
     * Cuentas usadas desde ALERT_IPS o más IP distintas en la ventana (sumando acciones auditadas y conexiones de
     * user_devices): puede ser una cuenta compartida o robada. Un celular que pasa de wifi a datos suma 2.
     * @return array<int, array{user_id: int, full_name: string, ips: int, list: string}>
     */
    public static function multiIpAccounts(int $hours = 24): array
    {
        $since = self::since($hours);
        return Database::select(
            "SELECT t.user_id, u.full_name, COUNT(DISTINCT t.ip) AS ips, GROUP_CONCAT(DISTINCT t.ip ORDER BY t.ip SEPARATOR ', ') AS list
               FROM (
                 SELECT user_id, ip FROM audit_log WHERE user_id IS NOT NULL AND created_at >= ?
                 UNION SELECT user_id, ip FROM user_devices WHERE last_seen_at >= ?
               ) t JOIN users u ON u.id = t.user_id
              GROUP BY t.user_id, u.full_name HAVING ips >= ? ORDER BY ips DESC LIMIT 30",
            [$since, $since, self::ALERT_IPS]
        );
    }

    /**
     * Navegadores que aparecieron por primera vez en la ventana. `known` = la cuenta ya tenía otros navegadores de
     * antes: es el caso a mirar (alguien entró desde un equipo nuevo); si no, es simplemente su primer acceso.
     * @return array<int, array{user_id: int, full_name: string, device_hash: string, ip: string, user_agent: string, first_seen_at: string, known: int}>
     */
    public static function newDevices(int $hours = 24): array
    {
        $since = self::since($hours);
        return Database::select(
            'SELECT d.user_id, u.full_name, d.device_hash, d.ip, d.user_agent, d.first_seen_at,
                    EXISTS (SELECT 1 FROM user_devices o WHERE o.user_id = d.user_id AND o.first_seen_at < ?) AS known
               FROM user_devices d JOIN users u ON u.id = d.user_id
              WHERE d.first_seen_at >= ? ORDER BY known DESC, d.first_seen_at DESC LIMIT 50',
            [$since, $since]
        );
    }

    /**
     * Últimos accesos del superusuario (entradas, salidas y contraseñas equivocadas con "superuser"): si aparece una
     * IP o un navegador que no son los suyos, alguien más conoce o está probando la contraseña.
     * @return array<int, array{event: string, ip: string, user_agent: ?string, created_at: string}>
     */
    public static function superuserAccess(int $limit = 15): array
    {
        return Database::select(
            "SELECT event, ip, user_agent, created_at FROM audit_log
              WHERE event IN ('su_login_ok', 'su_logout') OR (event IN ('login_fail', 'login_throttled') AND detail = 'superuser')
              ORDER BY id DESC LIMIT " . max(1, $limit)
        );
    }

    /**
     * Tamaño del registro: cantidad de filas y fecha de la más vieja (null si está vacío).
     * @return array{rows: int, oldest: ?string}
     */
    public static function size(): array
    {
        $r = Database::selectOne('SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM audit_log');
        return ['rows' => (int) $r['n'], 'oldest' => $r['oldest']];
    }

    /** Borra los registros de más de $days días. Devuelve cuántos borró. */
    public static function purgeOlderThan(int $days): int
    {
        return Database::execute('DELETE FROM audit_log WHERE created_at < ?', [date('Y-m-d H:i:s', time() - $days * 86400)]);
    }
}
