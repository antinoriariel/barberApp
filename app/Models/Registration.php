<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Altas de cuenta hechas desde /registro (tabla device_registrations), una por navegador: después de crear una
 * cuenta, ese navegador ya no puede crear otra hasta que el superusuario la libere (release). Guarda una copia de
 * DNI, nombre y teléfono para que la alta se pueda auditar aunque la cuenta se borre. Solo con base de datos.
 */
final class Registration
{
    /** Filas por página en /su/registros. */
    public const PER_PAGE = 50;

    /** ¿Este navegador (sha256 de su cookie) ya creó una cuenta que sigue sin liberar? */
    public static function deviceUsed(string $deviceHash): bool
    {
        return Database::selectOne('SELECT 1 AS x FROM device_registrations WHERE active_device = ?', [$deviceHash]) !== null;
    }

    /** Altas hechas desde esa IP en las últimas $hours horas (liberadas o no: cuentan igual para el límite por IP). */
    public static function countFromIp(string $ip, int $hours = 24): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS n FROM device_registrations WHERE ip = ? AND created_at >= ?',
            [$ip, date('Y-m-d H:i:s', time() - $hours * 3600)]
        )['n'];
    }

    /**
     * Anota el alta (va dentro de la transacción que crea la cuenta).
     * @throws \PDOException con código 23000 si ese navegador ya tenía un alta sin liberar (dos pedidos en paralelo)
     */
    public static function record(string $deviceHash, int $userId, string $dni, string $fullName, string $phone, string $ip, string $userAgent): void
    {
        Database::execute(
            'INSERT INTO device_registrations (device_hash, user_id, dni, full_name, phone, ip, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$deviceHash, $userId, $dni, mb_substr($fullName, 0, 120), $phone, $ip, mb_substr($userAgent, 0, 255), date('Y-m-d H:i:s')]
        );
    }

    /** Busca un alta por id (para liberarla), o null. */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM device_registrations WHERE id = ?', [$id]);
    }

    /**
     * Libera el navegador de un alta: desde ahí puede crear otra cuenta. El alta queda en la lista (con la fecha de
     * liberación) para no perder el rastro. Devuelve false si no existía o ya estaba liberada.
     */
    public static function release(int $id): bool
    {
        return Database::execute(
            'UPDATE device_registrations SET released_at = ? WHERE id = ? AND released_at IS NULL', [date('Y-m-d H:i:s'), $id]
        ) > 0;
    }

    /**
     * Normaliza los filtros de /su/registros: q (nombre, DNI o teléfono), ip, desde / hasta (Y-m-d reales).
     * @return array{q: string, ip: string, desde: string, hasta: string}
     */
    public static function filters(array $in): array
    {
        $text = static fn (string $k, int $max): string => is_string($in[$k] ?? null) ? mb_substr(trim($in[$k]), 0, $max) : '';
        $date = static function (string $raw): string {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            return $d && $d->format('Y-m-d') === $raw ? $raw : '';
        };
        return [
            'q'     => $text('q', 60),
            'ip'    => $text('ip', 45),
            'desde' => $date($text('desde', 10)),
            'hasta' => $date($text('hasta', 10)),
        ];
    }

    /**
     * Página $page de las altas con esos filtros, la más reciente primero. Cada fila trae el estado actual de la
     * cuenta (account_active: null si se eliminó), cuántas altas salieron de la misma IP (ip_count) y si ese navegador
     * tiene Permaban (banned). El texto busca en nombre, DNI y teléfono (en el teléfono, solo por sus dígitos).
     * device_bans es utf8mb4_general_ci y esta tabla utf8mb4_unicode_ci (como users): la comparación lleva COLLATE.
     * @return array{rows: array<int, array>, total: int, pages: int}
     */
    public static function search(array $f, int $page = 1): array
    {
        $sql = ['1 = 1'];
        $params = [];
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $digits = (string) preg_replace('/\D+/', '', $f['q']);
            $cond = 'r.full_name LIKE ? OR r.dni LIKE ?';
            array_push($params, $like, $like);
            if ($digits !== '') {
                $cond .= ' OR r.phone LIKE ? OR r.dni LIKE ?';
                array_push($params, '%' . $digits . '%', '%' . $digits . '%');
            }
            $sql[] = '(' . $cond . ')';
        }
        if ($f['ip'] !== '') {
            $sql[] = 'r.ip = ?';
            $params[] = $f['ip'];
        }
        if ($f['desde'] !== '') {
            $sql[] = 'r.created_at >= ?';
            $params[] = $f['desde'] . ' 00:00:00';
        }
        if ($f['hasta'] !== '') {
            $sql[] = 'r.created_at < ?';
            $params[] = date('Y-m-d', strtotime($f['hasta'] . ' +1 day')) . ' 00:00:00';
        }
        $where = implode(' AND ', $sql);

        $total = (int) Database::selectOne("SELECT COUNT(*) AS n FROM device_registrations r WHERE $where", $params)['n'];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $offset = (min(max(1, $page), $pages) - 1) * self::PER_PAGE;
        $rows = Database::select(
            "SELECT r.*, u.active AS account_active, u.phone AS account_phone,
                    (SELECT COUNT(*) FROM device_registrations x WHERE x.ip = r.ip) AS ip_count,
                    EXISTS (SELECT 1 FROM device_bans b WHERE b.device_hash = r.device_hash COLLATE utf8mb4_general_ci) AS banned
               FROM device_registrations r LEFT JOIN users u ON u.id = r.user_id
              WHERE $where ORDER BY r.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . $offset,
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
    }

    /**
     * Números de las tarjetas de /su/registros: altas en las últimas 24 h y 7 días, intentos de registrar una segunda
     * cuenta desde un navegador ya usado (o desde una IP que llegó al tope) en 7 días, y navegadores liberados.
     * @return array{day: int, week: int, blocked: int, released: int}
     */
    public static function totals(): array
    {
        $day = date('Y-m-d H:i:s', time() - 86400);
        $week = date('Y-m-d H:i:s', time() - 7 * 86400);
        $r = Database::selectOne(
            'SELECT SUM(created_at >= ?) AS day, SUM(created_at >= ?) AS week, SUM(released_at IS NOT NULL) AS released
               FROM device_registrations',
            [$day, $week]
        );
        $blocked = Database::selectOne(
            "SELECT COUNT(*) AS n FROM audit_log WHERE event IN ('register_blocked_device', 'register_blocked_ip') AND created_at >= ?",
            [$week]
        );
        return [
            'day'      => (int) ($r['day'] ?? 0),
            'week'     => (int) ($r['week'] ?? 0),
            'blocked'  => (int) $blocked['n'],
            'released' => (int) ($r['released'] ?? 0),
        ];
    }
}
