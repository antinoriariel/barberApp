<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Fila por orden de llegada ("tome su número"). Cada día los números arrancan en 1; el cliente saca el siguiente
 * escaneando el QR del mostrador y el administrador los va llamando en orden desde el panel.
 *
 * Estados: waiting (esperando) → called (lo están atendiendo) → done / no_show; cancelled si el cliente se va.
 * Solo funciona con base de datos (con driver files no hay fila).
 */
final class QueueTicket
{
    /** Fecha SQL de hoy. */
    private static function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Número activo de hoy del cliente (esperando o siendo atendido) con su lugar en la fila, o null si no tiene.
     * @return array{id: int, number: int, status: string, ahead: int, before: ?int, serving: ?int}|null
     *         ahead = cuántos esperan antes que él; before = número inmediatamente anterior que sigue esperando
     *         (detrás de quién está); serving = número que se está atendiendo ahora (el último llamado)
     */
    public static function currentFor(User $user): ?array
    {
        if (!Database::enabled()) {
            return null;
        }
        $t = Database::selectOne(
            "SELECT id, number, status FROM queue_tickets
              WHERE user_id = ? AND day = ? AND status IN ('waiting', 'called') ORDER BY number DESC LIMIT 1",
            [$user->id, self::today()]
        );
        return $t ? self::withPosition($t) : null;
    }

    /** Agrega a un número su lugar en la fila de hoy (ver currentFor()). */
    private static function withPosition(array $t): array
    {
        $day = self::today();
        $number = (int) $t['number'];
        $ahead = Database::selectOne(
            "SELECT COUNT(*) AS n, MAX(number) AS prev FROM queue_tickets WHERE day = ? AND status = 'waiting' AND number < ?",
            [$day, $number]
        );
        return [
            'id'      => (int) $t['id'],
            'number'  => $number,
            'status'  => $t['status'],
            'ahead'   => $t['status'] === 'waiting' ? (int) $ahead['n'] : 0,
            'before'  => $t['status'] === 'waiting' && $ahead['prev'] !== null ? (int) $ahead['prev'] : null,
            'serving' => self::serving(),
        ];
    }

    /** Número que se está atendiendo ahora (el último llamado de hoy), o null si todavía no se llamó a nadie. */
    public static function serving(): ?int
    {
        $r = Database::selectOne(
            "SELECT number FROM queue_tickets WHERE day = ? AND status = 'called' ORDER BY called_at DESC, number DESC LIMIT 1",
            [self::today()]
        );
        return $r ? (int) $r['number'] : null;
    }

    /**
     * Le da al cliente el número siguiente al último entregado hoy. Si ya tiene uno activo, devuelve ese (escanear
     * dos veces no lo manda al final de la fila). Bloquea la fila del usuario y reintenta si dos personas escanean a
     * la vez y chocan con la clave única (día, número).
     * @return array misma forma que currentFor()
     */
    public static function take(User $user): array
    {
        return Database::transaction(function () use ($user) {
            Database::selectOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->id]);
            if ($current = self::currentFor($user)) {
                return $current;
            }
            for ($try = 0; $try < 5; $try++) {
                $next = (int) Database::selectOne(
                    'SELECT COALESCE(MAX(number), 0) + 1 AS n FROM queue_tickets WHERE day = ?', [self::today()]
                )['n'];
                try {
                    Database::execute('INSERT INTO queue_tickets (user_id, day, number) VALUES (?, ?, ?)', [$user->id, self::today(), $next]);
                    return self::currentFor($user) ?? throw new \RuntimeException('No se pudo leer el número recién creado.');
                } catch (\PDOException $e) {
                    if ($e->getCode() !== '23000') { // otro cliente se llevó ese número en el mismo instante: se prueba el siguiente
                        throw $e;
                    }
                }
            }
            throw new \RuntimeException('No se pudo asignar un número; volvé a escanear.');
        });
    }

    /** El cliente deja la fila (cancela su número activo de hoy). Devuelve si había uno. */
    public static function leave(User $user): bool
    {
        return Database::execute(
            "UPDATE queue_tickets SET status = 'cancelled' WHERE user_id = ? AND day = ? AND status IN ('waiting', 'called')",
            [$user->id, self::today()]
        ) > 0;
    }

    /**
     * Fila de hoy para el panel, en orden de número, con el nombre del cliente.
     * @return array<int, array{id: int, number: int, client: string, status: string, time: string}>
     */
    public static function todayList(): array
    {
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'number' => (int) $r['number'], 'client' => $r['client'],
            'status' => $r['status'], 'time' => date('H:i', strtotime($r['created_at'])),
        ], Database::select(
            'SELECT q.id, q.number, q.status, q.created_at, u.full_name AS client
               FROM queue_tickets q JOIN users u ON u.id = q.user_id
              WHERE q.day = ? ORDER BY q.number',
            [self::today()]
        ));
    }

    /**
     * Llama al siguiente que espera: el que estaba siendo atendido pasa a atendido (done) y el primer número en
     * espera pasa a "called". Devuelve el número llamado, o null si no queda nadie esperando.
     */
    public static function callNext(): ?int
    {
        return Database::transaction(function () {
            $day = self::today();
            Database::execute("UPDATE queue_tickets SET status = 'done' WHERE day = ? AND status = 'called'", [$day]);
            $next = Database::selectOne(
                "SELECT id, number FROM queue_tickets WHERE day = ? AND status = 'waiting' ORDER BY number LIMIT 1 FOR UPDATE", [$day]
            );
            if ($next === null) {
                return null;
            }
            Database::execute("UPDATE queue_tickets SET status = 'called', called_at = NOW() WHERE id = ?", [$next['id']]);
            return (int) $next['number'];
        });
    }

    /** Marca un número de hoy como no presentado (no_show), p. ej. si lo llamaron y no estaba. Devuelve si cambió. */
    public static function markNoShow(int $id): bool
    {
        return Database::execute(
            "UPDATE queue_tickets SET status = 'no_show' WHERE id = ? AND day = ? AND status IN ('waiting', 'called')",
            [$id, self::today()]
        ) === 1;
    }
}
