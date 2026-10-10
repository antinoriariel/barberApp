<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

/**
 * Horario semanal de cada barbero (tabla barber_hours, se edita en /admin/barberos).
 *
 * Un día sin fila cargada vale como "atiende todo el horario del local" (del primer horario de la grilla al cierre),
 * así un barbero nuevo toma turnos sin configurar nada. Con driver files no hay horarios propios: siempre ese default.
 */
final class BarberHours
{
    /**
     * Franja por defecto: del primer horario de la grilla (slot_templates) al cierre del local (booking.closing_time).
     * @return array{works: bool, from: string, to: string}
     */
    public static function defaultDay(): array
    {
        $times = Schedule::slotTimes();
        return ['works' => true, 'from' => $times[0] ?? '09:00', 'to' => (string) Config::get('booking.closing_time')];
    }

    /**
     * Semana de un barbero: [weekday (0 = domingo) => franja]. Los días sin fila salen con defaultDay().
     * @return array<int, array{works: bool, from: string, to: string}>
     */
    public static function week(int $barberKey): array
    {
        $week = array_fill(0, 7, self::defaultDay());
        if (!Database::enabled()) {
            return $week;
        }
        foreach (Database::select('SELECT weekday, is_working, start_time, end_time FROM barber_hours WHERE barber_id = ?', [$barberKey]) as $r) {
            $week[(int) $r['weekday']] = [
                'works' => (bool) $r['is_working'],
                'from'  => substr($r['start_time'], 0, 5),
                'to'    => substr($r['end_time'], 0, 5),
            ];
        }
        return $week;
    }

    /**
     * Franja de un barbero en una fecha como timestamps [inicio, fin], o null si ese día no atiende.
     * Se usa al calcular horarios libres y al validar una reserva.
     * @return array{0: int, 1: int}|null
     */
    public static function window(int $barberKey, string $date): ?array
    {
        $day = self::week($barberKey)[(int) date('w', strtotime($date))];
        return $day['works'] ? [strtotime("{$date} {$day['from']}"), strtotime("{$date} {$day['to']}")] : null;
    }

    /** ¿El barbero puede atender un turno de $start a $end? (ese día trabaja y el turno entra completo en su franja). */
    public static function covers(int $barberKey, string $date, int $start, int $end): bool
    {
        $window = self::window($barberKey, $date);
        return $window !== null && $start >= $window[0] && $end <= $window[1];
    }

    /**
     * Valida lo que manda el formulario: [weekday => ['works' => '1'|null, 'from' => 'HH:MM', 'to' => 'HH:MM']].
     * Solo se aceptan días 0–6; si atiende, "desde" tiene que ser anterior a "hasta". Un día que no atiende guarda
     * igual sus horas (las del form o las por defecto) para que al volver a activarlo no haya que tipearlas.
     *
     * @return array{0: array<int, array{works: bool, from: string, to: string}>, 1: ?string} [datos limpios, error o null]
     */
    public static function validate(mixed $input): array
    {
        if (!is_array($input)) {
            return [[], 'No llegó ningún horario.'];
        }
        $names = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $default = self::defaultDay();
        $clean = [];
        foreach ($input as $weekday => $day) {
            if (!is_int($weekday) || $weekday < 0 || $weekday > 6 || !is_array($day)) {
                return [[], 'Día de la semana inválido.'];
            }
            $works = ($day['works'] ?? '') === '1';
            $from = self::time($day['from'] ?? null);
            $to = self::time($day['to'] ?? null);
            if ($works && ($from === null || $to === null)) {
                return [[], "Completá el horario del {$names[$weekday]} (HH:MM)."];
            }
            if ($works && $from >= $to) {
                return [[], "El {$names[$weekday]} la hora de inicio tiene que ser anterior a la de fin."];
            }
            $clean[$weekday] = ['works' => $works, 'from' => $from ?? $default['from'], 'to' => $to ?? $default['to']];
        }
        return [$clean, null];
    }

    /** "9:30" o "09:30" → "09:30"; cualquier otra cosa → null. */
    private static function time(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', $value, $m)) {
            return null;
        }
        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    /**
     * Guarda los días validados de un barbero (upsert por barbero + día; los días no enviados no se tocan).
     * @param array<int, array{works: bool, from: string, to: string}> $days salida de validate()
     */
    public static function save(int $barberKey, array $days): void
    {
        Database::transaction(function () use ($barberKey, $days) {
            foreach ($days as $weekday => $d) {
                Database::execute(
                    'INSERT INTO barber_hours (barber_id, weekday, is_working, start_time, end_time) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE is_working = VALUES(is_working), start_time = VALUES(start_time), end_time = VALUES(end_time)',
                    [$barberKey, $weekday, $d['works'] ? 1 : 0, $d['from'] . ':00', $d['to'] . ':00']
                );
            }
        });
    }
}
