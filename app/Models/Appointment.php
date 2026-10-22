<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Data;
use App\Core\Database;

/**
 * Turnos. Con base de datos cada cliente ve solo los suyos y las escrituras filtran siempre por el usuario
 * de la sesión; con driver files todos ven los mismos datos de ejemplo y no se puede escribir.
 *
 * Las reglas de negocio de reservar/cancelar/reprogramar viven en Services\Booking; acá solo hay consultas.
 */
final class Appointment
{
    private const DAYS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado']; // índice = date('w')
    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * SQL de los servicios de un turno (alias `a` = appointments, `s` = su servicio principal): nombres unidos con " + "
     * y slugs separados por coma, en el orden en que se eligieron (tabla appointment_services, migración 008).
     * Si un turno no tiene detalle cae al servicio principal. También lo usa Models\Dashboard.
     */
    public const SERVICE_NAMES_SQL = "COALESCE((SELECT GROUP_CONCAT(sx.name ORDER BY ax.position SEPARATOR ' + ') FROM appointment_services ax JOIN services sx ON sx.id = ax.service_id WHERE ax.appointment_id = a.id), s.name)";
    public const SERVICE_SLUGS_SQL = "COALESCE((SELECT GROUP_CONCAT(sx.slug ORDER BY ax.position SEPARATOR ',') FROM appointment_services ax JOIN services sx ON sx.id = ax.service_id WHERE ax.appointment_id = a.id), s.slug)";

    /** Fecha/hora SQL ("2026-10-09 18:30:00") de un timestamp. */
    private static function sql(int $ts): string
    {
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Próximo turno reservado y futuro del cliente.
     * @return array{id: int, service: string, service_id: string, barber: string, barber_id: string, day: string, time: string}|null
     *         null si no tiene ninguno; "day" es "Jueves 9"
     */
    public static function next(User $user): ?array
    {
        if (!Database::enabled()) {
            return Data::get('appointments')['next'] ?? null;
        }
        $r = Database::selectOne(
            "SELECT a.id, a.starts_at, " . self::SERVICE_SLUGS_SQL . " AS service_slug, " . self::SERVICE_NAMES_SQL . " AS service, b.slug AS barber_slug, b.name AS barber, b.photo AS barber_photo
               FROM appointments a
               JOIN services s ON s.id = a.service_id
               JOIN barbers b ON b.id = a.barber_id
              WHERE a.user_id = ? AND a.status = 'booked' AND a.starts_at > ?
              ORDER BY a.starts_at LIMIT 1",
            [$user->id, self::sql(time())]
        );
        return $r ? self::present($r) : null;
    }

    /**
     * Visitas anteriores del cliente (turnos 'done'), de la más reciente a la más antigua.
     * @return array<int, array{service: string, barber: string, date: string, price: int}> date es "12 sep"
     */
    public static function history(User $user): array
    {
        if (!Database::enabled()) {
            return Data::get('appointments')['history'] ?? [];
        }
        return array_map(fn (array $r) => [
            'service' => $r['service'],
            'barber'  => $r['barber'],
            'date'    => date('j', strtotime($r['starts_at'])) . ' ' . self::MONTHS[(int) date('n', strtotime($r['starts_at'])) - 1],
            'price'   => (int) $r['price'],
        ], Database::select(
            "SELECT a.starts_at, a.price, " . self::SERVICE_NAMES_SQL . " AS service, b.name AS barber
               FROM appointments a
               JOIN services s ON s.id = a.service_id
               JOIN barbers b ON b.id = a.barber_id
              WHERE a.user_id = ? AND a.status = 'done'
              ORDER BY a.starts_at DESC LIMIT 20",
            [$user->id]
        ));
    }

    /** Turno reservado y futuro de un cliente por id (para cancelar/reprogramar), con la forma de next(); null si no aplica. */
    public static function findUpcoming(User $user, int $id): ?array
    {
        $r = Database::selectOne(
            "SELECT a.id, a.starts_at, " . self::SERVICE_SLUGS_SQL . " AS service_slug, " . self::SERVICE_NAMES_SQL . " AS service, b.slug AS barber_slug, b.name AS barber, b.photo AS barber_photo
               FROM appointments a
               JOIN services s ON s.id = a.service_id
               JOIN barbers b ON b.id = a.barber_id
              WHERE a.id = ? AND a.user_id = ? AND a.status = 'booked' AND a.starts_at > ?",
            [$id, $user->id, self::sql(time())]
        );
        return $r ? self::present($r) : null;
    }

    /** Da a una fila de turno la forma que esperan las vistas y el JS. */
    private static function present(array $r): array
    {
        $ts = strtotime($r['starts_at']);
        return [
            'id'         => (int) $r['id'],
            'service'    => $r['service'],
            'service_id' => $r['service_slug'],
            'barber'     => $r['barber'],
            'barber_id'  => $r['barber_slug'],
            'barber_photo' => $r['barber_photo'] ?? null, // imagen del barbero (/img/...) o null
            'day'       => self::DAYS[(int) date('w', $ts)] . ' ' . date('j', $ts),
            'time'       => date('H:i', $ts),
        ];
    }

    /**
     * Turnos reservados de un día (de todos los barberos), para calcular disponibilidad.
     * @return array<int, array{barber_id: int, start: int, end: int}> start/end son timestamps
     */
    public static function bookedOn(string $date): array
    {
        return array_map(fn (array $r) => [
            'barber_id' => (int) $r['barber_id'],
            'start'     => strtotime($r['starts_at']),
            'end'       => strtotime($r['ends_at']),
        ], Database::select(
            "SELECT barber_id, starts_at, ends_at FROM appointments
              WHERE status = 'booked' AND starts_at >= ? AND starts_at < ?",
            ["{$date} 00:00:00", date('Y-m-d 00:00:00', strtotime("{$date} +1 day"))]
        ));
    }

    /**
     * Cantidad de turnos reservados y futuros (de todos los clientes) que caen en esos días de semana (0 = domingo).
     * Sirve para avisar al admin que cerró un día que ya tenía reservas (los turnos no se cancelan solos).
     * @param int[] $weekdays
     */
    public static function countUpcomingOnWeekdays(array $weekdays): int
    {
        if (!$weekdays) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($weekdays), '?'));
        return (int) (Database::selectOne(
            "SELECT COUNT(*) AS n FROM appointments WHERE status = 'booked' AND starts_at > ? AND DAYOFWEEK(starts_at) - 1 IN ({$in})",
            [self::sql(time()), ...array_values($weekdays)]
        )['n'] ?? 0);
    }

    /** Cantidad de turnos reservados y futuros del cliente. */
    public static function countUpcoming(User $user): int
    {
        return (int) (Database::selectOne(
            "SELECT COUNT(*) AS n FROM appointments WHERE user_id = ? AND status = 'booked' AND starts_at > ?",
            [$user->id, self::sql(time())]
        )['n'] ?? 0);
    }

    /** ¿El cliente tiene otro turno reservado que se superponga con [$start, $end)? */
    public static function userHasOverlap(User $user, int $start, int $end): bool
    {
        return Database::selectOne(
            "SELECT id FROM appointments WHERE user_id = ? AND status = 'booked' AND starts_at < ? AND ends_at > ? LIMIT 1",
            [$user->id, self::sql($end), self::sql($start)]
        ) !== null;
    }

    /**
     * ¿El barbero tiene un turno reservado que se superponga con [$start, $end)?
     * Bloquea las filas leídas (FOR UPDATE): debe llamarse dentro de una transacción.
     */
    public static function barberIsBusy(int $barberKey, int $start, int $end): bool
    {
        return Database::selectOne(
            "SELECT id FROM appointments WHERE barber_id = ? AND status = 'booked' AND starts_at < ? AND ends_at > ? LIMIT 1 FOR UPDATE",
            [$barberKey, self::sql($end), self::sql($start)]
        ) !== null;
    }

    /**
     * Inserta un turno reservado con uno o más servicios seguidos y devuelve su id. El primero queda como servicio
     * principal; la duración y el precio son la suma (precios congelados al reservar) y cada servicio se guarda
     * en appointment_services. Debe correr dentro de una transacción.
     * $discount (0–100, "Hora muerta") se descuenta del total redondeado al peso y queda en discount_pct; los precios
     * por servicio de appointment_services siguen siendo los de lista.
     * @param Service[] $services
     */
    public static function insert(User $user, Barber $barber, array $services, int $start, int $discount = 0): int
    {
        $minutes = array_sum(array_map(static fn (Service $s) => $s->minutes, $services));
        $discount = max(0, min(100, $discount));
        $price = (int) round(array_sum(array_map(static fn (Service $s) => $s->price, $services)) * (100 - $discount) / 100);
        Database::execute(
            "INSERT INTO appointments (user_id, barber_id, service_id, starts_at, ends_at, price, discount_pct, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'booked')",
            [$user->id, $barber->key, $services[0]->key, self::sql($start), self::sql($start + $minutes * 60), $price, $discount]
        );
        $id = Database::lastId();
        foreach ($services as $position => $service) {
            Database::execute(
                'INSERT INTO appointment_services (appointment_id, service_id, price, position) VALUES (?, ?, ?, ?)',
                [$id, $service->key, $service->price, $position]
            );
        }
        return $id;
    }

    /**
     * Cancela un turno futuro y reservado del cliente. Devuelve false si no existe, no es suyo o ya no se puede cancelar.
     * El filtro por user_id evita que alguien cancele turnos ajenos conociendo el id.
     */
    public static function cancel(User $user, int $id): bool
    {
        return Database::execute(
            "UPDATE appointments SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'booked' AND starts_at > ?",
            [$id, $user->id, self::sql(time())]
        ) === 1;
    }
}
