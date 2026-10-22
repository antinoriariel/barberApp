<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * "Hora muerta" (tabla dead_hours, solo con base de datos): descuento de un X% durante N minutos que el administrador
 * activa con un botón cuando el local está sin clientes. Hay a lo sumo una vigente: activar otra corta la anterior.
 * Mientras dura, la app de clientes la muestra con una cuenta regresiva (Inicio y Perfil) y Booking aplica el
 * descuento a los turnos de ese mismo día que se reservan en ese lapso.
 */
final class DeadHour
{
    /** Porcentajes que ofrece el formulario (el validador acepta cualquiera entre MIN_PERCENT y MAX_PERCENT). */
    public const PERCENTS = [10, 15, 20, 25, 30, 40, 50];

    /** Duraciones en minutos que ofrece el formulario. */
    public const MINUTES = [15, 30, 45, 60, 90, 120];

    private const MIN_PERCENT = 5;
    private const MAX_PERCENT = 90;
    private const MAX_MINUTES = 240;

    /**
     * Hora muerta vigente o null (sin base de datos, nunca hay). Vigente = empezó, no llegó a ends_at y no se cortó a mano.
     * @return ?array{id: int, percent: int, starts: int, ends: int} starts/ends en timestamp Unix
     */
    public static function current(): ?array
    {
        if (!Database::enabled()) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        $r = Database::selectOne(
            'SELECT id, percent, starts_at, ends_at FROM dead_hours
              WHERE ended_at IS NULL AND starts_at <= ? AND ends_at > ? ORDER BY id DESC LIMIT 1',
            [$now, $now]
        );
        return $r === null ? null : [
            'id'      => (int) $r['id'],
            'percent' => (int) $r['percent'],
            'starts'  => strtotime($r['starts_at']),
            'ends'    => strtotime($r['ends_at']),
        ];
    }

    /**
     * Descuento (en %) para un turno del día $date reservado ahora: el de la hora muerta vigente si el turno es para hoy,
     * 0 en cualquier otro caso (así no se reservan días futuros con descuento).
     */
    public static function percentFor(string $date): int
    {
        return $date === date('Y-m-d') ? (self::current()['percent'] ?? 0) : 0;
    }

    /**
     * Valida el formulario de /admin/hora-muerta: percent entero 5–90 y minutes entero 5–240.
     * @return array{0: ?array{percent: int, minutes: int}, 1: ?string} [datos, error]
     */
    public static function validate(array $in): array
    {
        $percent = filter_var($in['percent'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_PERCENT, 'max_range' => self::MAX_PERCENT]]);
        $minutes = filter_var($in['minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => self::MAX_MINUTES]]);
        return match (true) {
            $percent === false => [null, 'El descuento va de ' . self::MIN_PERCENT . '% a ' . self::MAX_PERCENT . '%.'],
            $minutes === false => [null, 'La duración va de 5 a ' . self::MAX_MINUTES . ' minutos.'],
            default            => [['percent' => $percent, 'minutes' => $minutes], null],
        };
    }

    /**
     * Activa una hora muerta desde ahora por $minutes minutos (datos ya validados). Si había otra vigente la corta
     * primero, así nunca hay dos a la vez. Devuelve el id nuevo.
     */
    public static function start(int $percent, int $minutes, int $userId): int
    {
        return (int) Database::transaction(function () use ($percent, $minutes, $userId) {
            self::stop();
            $now = time();
            Database::execute(
                'INSERT INTO dead_hours (percent, starts_at, ends_at, created_by) VALUES (?, ?, ?, ?)',
                [$percent, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + $minutes * 60), $userId ?: null]
            );
            return Database::lastId();
        });
    }

    /** Corta la hora muerta vigente ("Terminar ahora"). Devuelve false si no había ninguna. */
    public static function stop(): bool
    {
        $now = date('Y-m-d H:i:s');
        return Database::execute(
            'UPDATE dead_hours SET ended_at = ? WHERE ended_at IS NULL AND starts_at <= ? AND ends_at > ?',
            [$now, $now, $now]
        ) > 0;
    }

    /**
     * Últimas horas muertas (las más nuevas primero) con cuántos turnos se reservaron con su descuento: los de ese
     * porcentaje creados entre que empezó y que terminó (o se cortó).
     * @return array<int, array{percent: int, starts: int, ends: int, cut: bool, bookings: int}>
     */
    public static function recent(int $limit = 10): array
    {
        $rows = Database::select(
            "SELECT d.percent, d.starts_at, d.ends_at, d.ended_at,
                    (SELECT COUNT(*) FROM appointments a
                      WHERE a.discount_pct = d.percent AND a.created_at >= d.starts_at
                        AND a.created_at < COALESCE(d.ended_at, d.ends_at)) AS bookings
               FROM dead_hours d ORDER BY d.id DESC LIMIT " . max(1, min($limit, 50))
        );
        return array_map(static fn (array $r) => [
            'percent'  => (int) $r['percent'],
            'starts'   => strtotime($r['starts_at']),
            'ends'     => strtotime($r['ended_at'] ?? $r['ends_at']),
            'cut'      => $r['ended_at'] !== null,
            'bookings' => (int) $r['bookings'],
        ], $rows);
    }
}
