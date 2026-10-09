<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Data;
use App\Core\Database;

/** Agenda del local: días abiertos y horarios disponibles. */
final class Schedule
{
    private const DAY_NAMES = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']; // índice = date('w')

    /** ¿Es una fecha ISO real (YYYY-MM-DD)? */
    public static function isDate(string $iso): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $iso, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Días de la semana (date('w')) en que el local abre. Con base de datos sale de `opening_hours`;
     * con driver files, de data/schedule.php (closed_weekdays).
     * @return int[]
     */
    private static function openWeekdays(): array
    {
        if (Database::enabled()) {
            return array_map('intval', array_column(Database::select('SELECT weekday FROM opening_hours WHERE is_open = 1'), 'weekday'));
        }
        return array_values(array_diff(range(0, 6), Data::get('schedule')['closed_weekdays']));
    }

    /** Fechas ISO de cierre especial (feriados) entre dos fechas ISO, ambas inclusive. Vacío sin base de datos. */
    private static function closures(string $from, string $to): array
    {
        if (!Database::enabled()) {
            return [];
        }
        return array_column(Database::select('SELECT DATE_FORMAT(day, "%Y-%m-%d") AS day FROM closures WHERE day BETWEEN ? AND ?', [$from, $to]), 'day');
    }

    /** ¿El local abre ese día? (día de semana abierto y sin cierre especial). */
    public static function isOpen(string $iso): bool
    {
        if (!self::isDate($iso)) {
            return false;
        }
        return in_array((int) date('w', strtotime($iso)), self::openWeekdays(), true)
            && !self::closures($iso, $iso);
    }

    /**
     * ¿Ese día se atiende solo por orden de llegada? El local abre pero no se toman turnos (booking.walk_in_weekdays):
     * los clientes escanean el QR de la fila en el local.
     */
    public static function isWalkInOnly(string $iso): bool
    {
        return self::isDate($iso)
            && in_array((int) date('w', strtotime($iso)), (array) Config::get('booking.walk_in_weekdays'), true);
    }

    /**
     * Próximos $count días reservables desde hoy, saltando los días de cierre, los feriados y los días por orden de
     * llegada. Avanza día por día, solo cuenta los reservables y no pasa de la ventana de reserva (booking.window_days).
     *
     * @return array<int, array{label: string, num: string, iso: string}> label = "Mar", num = "9", iso = "2026-10-09"
     */
    public static function openDays(int $count = 7): array
    {
        $window = (int) Config::get('booking.window_days');
        $open = self::openWeekdays();
        $closures = self::closures(date('Y-m-d'), date('Y-m-d', strtotime("+{$window} days")));
        $days = [];
        $d = new \DateTime('today');
        for ($i = 0; $i <= $window && count($days) < $count; $i++, $d->modify('+1 day')) {
            $w = (int) $d->format('w');
            if (!in_array($w, $open, true) || in_array($d->format('Y-m-d'), $closures, true) || self::isWalkInOnly($d->format('Y-m-d'))) {
                continue;
            }
            $days[] = ['label' => self::DAY_NAMES[$w], 'num' => $d->format('j'), 'iso' => $d->format('Y-m-d')];
        }
        return $days;
    }

    /**
     * Horarios de inicio posibles ("10:00", "10:45"…), en orden.
     * @return string[]
     */
    public static function slotTimes(): array
    {
        if (Database::enabled()) {
            return array_map(fn (array $r) => substr($r['slot_time'], 0, 5),
                Database::select('SELECT slot_time FROM slot_templates ORDER BY slot_time'));
        }
        return array_map(fn (array $s) => $s[0], Data::get('schedule')['slots']);
    }

    /**
     * Horarios de un día con su disponibilidad real.
     *
     * Un horario está libre si el local abre ese día, todavía no pasó, el servicio (de $minutes) termina antes
     * del cierre y hay al menos un barbero sin turnos que se superpongan: el indicado en $barberSlug o, si es
     * null / 'any', cualquiera del equipo. Con driver files devuelve los horarios fijos de data/schedule.php.
     *
     * @return array<int, array{time: string, free: bool}>
     */
    public static function slots(string $date, ?string $barberSlug = null, int $minutes = 30): array
    {
        if (!Database::enabled()) {
            return array_map(fn (array $s) => ['time' => $s[0], 'free' => $s[1]], Data::get('schedule')['slots']);
        }

        $times = self::slotTimes();
        $open = self::isOpen($date) && !self::isWalkInOnly($date); // por orden de llegada: ningún horario reservable
        $busy = $open ? Appointment::bookedOn($date) : [];
        $barbers = array_map(fn (Barber $b) => $b->key, Barber::all());
        if ($barberSlug !== null && $barberSlug !== 'any') {
            $one = Barber::find($barberSlug);
            $barbers = $one ? [$one->key] : [];
        }

        $now = time();
        $closing = $open ? strtotime($date . ' ' . Config::get('booking.closing_time')) : 0;
        $slots = [];
        foreach ($times as $time) {
            $start = strtotime("{$date} {$time}");
            $end = $start + $minutes * 60;
            $free = false;
            if ($open && $start > $now && $end <= $closing) {
                foreach ($barbers as $barberKey) {
                    $taken = array_filter($busy, fn (array $b) => $b['barber_id'] === $barberKey && $b['start'] < $end && $b['end'] > $start);
                    if (!$taken) {
                        $free = true;
                        break;
                    }
                }
            }
            $slots[] = ['time' => $time, 'free' => $free];
        }
        return $slots;
    }
}
