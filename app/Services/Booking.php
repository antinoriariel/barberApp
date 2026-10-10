<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\BarberHours;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;

/**
 * Reglas de reservar, cancelar y reprogramar turnos.
 *
 * Nunca se confía en lo que manda el cliente: servicio, barbero, fecha y hora se revalidan contra la base,
 * y el precio y la duración salen de `services` (si el turno combina varios servicios, se suman). Todo corre dentro de una transacción; la restricción única
 * `uq_barber_slot` es la defensa final contra dos reservas simultáneas del mismo horario.
 * Los errores esperables se lanzan como BookingException (mensaje apto para el cliente + código HTTP).
 */
final class Booking
{
    /** Máximo de servicios que se pueden combinar en un mismo turno. */
    public const MAX_SERVICES = 4;

    /**
     * Crea un turno y devuelve cómo quedó (misma forma que Appointment::next()).
     *
     * @param string $barberSlug slug del barbero o 'any' (se asigna el primero libre en ese horario)
     * @throws BookingException 422 datos inválidos, 409 horario ocupado/superpuesto, 503 sin base de datos
     */
    public static function create(User $user, string $serviceSlug, string $barberSlug, string $date, string $time): array
    {
        self::requireDatabase();
        return Database::transaction(function () use ($user, $serviceSlug, $barberSlug, $date, $time) {
            $appointment = self::place($user, $serviceSlug, $barberSlug, $date, $time);
            Audit::log('appointment_create', $user->id, "#{$appointment['id']}");
            return $appointment;
        });
    }

    /**
     * Cancela un turno futuro del cliente.
     * @throws BookingException 404 si no existe, no es suyo o ya pasó
     */
    public static function cancel(User $user, int $id): void
    {
        self::requireDatabase();
        if (!Appointment::cancel($user, $id)) {
            throw new BookingException('No encontramos ese turno o ya no se puede cancelar.', 404);
        }
        Audit::log('appointment_cancel', $user->id, "#{$id}");
    }

    /**
     * Reprograma: cancela el turno viejo y crea el nuevo en una sola transacción (si el nuevo falla,
     * el viejo sigue en pie).
     * @throws BookingException igual que create(), más 404 si el turno original no es válido
     */
    public static function reschedule(User $user, int $id, string $serviceSlug, string $barberSlug, string $date, string $time): array
    {
        self::requireDatabase();
        return Database::transaction(function () use ($user, $id, $serviceSlug, $barberSlug, $date, $time) {
            self::lockUser($user);
            if (!Appointment::cancel($user, $id)) {
                throw new BookingException('No encontramos ese turno o ya no se puede reprogramar.', 404);
            }
            $appointment = self::place($user, $serviceSlug, $barberSlug, $date, $time);
            Audit::log('appointment_reschedule', $user->id, "#{$id} -> #{$appointment['id']}");
            return $appointment;
        });
    }

    /** Sin driver db no hay dónde guardar: se avisa en lugar de simular. */
    private static function requireDatabase(): void
    {
        if (!Database::enabled()) {
            throw new BookingException('Las reservas no están disponibles en este momento.', 503);
        }
    }

    /** Bloquea la fila del cliente: serializa sus reservas simultáneas para que los chequeos por usuario no se esquiven. */
    private static function lockUser(User $user): void
    {
        Database::selectOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->id]);
    }

    /** Valida todo y inserta el turno. Debe correr dentro de una transacción. */
    private static function place(User $user, string $serviceSlug, string $barberSlug, string $date, string $time): array
    {
        self::lockUser($user);

        // $serviceSlug puede traer varios servicios separados por coma ("corte,cejas"): se hacen seguidos con el mismo barbero.
        $services = Service::findMany($serviceSlug, self::MAX_SERVICES)
            ?? throw new BookingException('Elegí entre 1 y ' . self::MAX_SERVICES . ' servicios válidos.');
        $minutes = array_sum(array_map(static fn (Service $s) => $s->minutes, $services));
        $candidates = $barberSlug === 'any'
            ? Barber::all()
            : [Barber::find($barberSlug) ?? throw new BookingException('Elegí un barbero válido.')];

        $window = (int) Config::get('booking.window_days');
        if (!Schedule::isDate($date) || $date < date('Y-m-d') || $date > date('Y-m-d', strtotime("+{$window} days"))) {
            throw new BookingException("Elegí un día dentro de los próximos {$window} días.");
        }
        if (Schedule::isWalkInOnly($date)) {
            throw new BookingException('Los miércoles no se dan turnos: atendemos por orden de llegada (escaneá el QR de la fila en el local).');
        }
        if (!Schedule::isOpen($date)) {
            throw new BookingException('Ese día el local está cerrado.');
        }
        if (!in_array($time, Schedule::slotTimes(), true)) {
            throw new BookingException('Elegí un horario válido.');
        }

        $start = strtotime("{$date} {$time}");
        $end = $start + $minutes * 60;
        if ($start <= time()) {
            throw new BookingException('Ese horario ya pasó.');
        }
        if ($end > strtotime($date . ' ' . Config::get('booking.closing_time'))) {
            throw new BookingException('Lo que elegiste no termina antes del cierre. Elegí un horario más temprano.');
        }

        $max = (int) Config::get('booking.max_active');
        if (Appointment::countUpcoming($user) >= $max) {
            throw new BookingException("Ya tenés {$max} turnos reservados. Cancelá o esperá a uno para pedir otro.", 409);
        }
        if (Appointment::userHasOverlap($user, $start, $end)) {
            throw new BookingException('Ya tenés un turno que se superpone con ese horario.', 409);
        }

        // Solo cuentan los barberos que ese día atienden en esa franja (horario semanal de /admin/barberos).
        $candidates = array_filter($candidates, static fn (Barber $b) => BarberHours::covers($b->key, $date, $start, $end));
        if (!$candidates) {
            throw new BookingException($barberSlug === 'any'
                ? 'No hay barberos atendiendo en ese horario. Elegí otro.'
                : 'Ese barbero no atiende en ese horario. Elegí otro día u horario.');
        }

        $barber = null;
        foreach ($candidates as $candidate) {
            if (!Appointment::barberIsBusy($candidate->key, $start, $end)) {
                $barber = $candidate;
                break;
            }
        }
        if ($barber === null) {
            throw new BookingException('Ese horario se acaba de ocupar. Elegí otro.', 409);
        }

        try {
            $id = Appointment::insert($user, $barber, $services, $start);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') { // uq_barber_slot: otro cliente reservó el mismo horario en el mismo instante
                throw new BookingException('Ese horario se acaba de ocupar. Elegí otro.', 409);
            }
            throw $e;
        }

        return Appointment::findUpcoming($user, $id) ?? throw new \RuntimeException('No se pudo leer el turno recién creado.');
    }
}
