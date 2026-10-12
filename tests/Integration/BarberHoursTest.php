<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Barber;
use App\Models\BarberHours;
use App\Models\Schedule;
use App\Services\Booking;
use App\Services\BookingException;

/** Horario semanal de los barberos: la agenda y las reservas solo usan su franja de cada día. */
final class BarberHoursTest extends DbTestCase
{
    /**
     * Borra todos los horarios cargados (sin filas = cada barbero atiende el día completo) y luego limpia usuarios y
     * turnos de prueba.
     */
    protected function tearDown(): void
    {
        Database::execute('DELETE FROM barber_hours');
        parent::tearDown();
    }

    /** Guarda el horario de un día para Nico (works = false: ese día no atiende). */
    private function setNico(string $day, bool $works, string $from = '10:00', string $to = '20:00'): void
    {
        BarberHours::save(Barber::find('nico')->key, [(int) date('w', strtotime($day)) => ['works' => $works, 'from' => $from, 'to' => $to]]);
    }

    /** Código HTTP de la BookingException que lance $fn (0 si no lanza). */
    private function statusOf(callable $fn): int
    {
        try {
            $fn();
            return 0;
        } catch (BookingException $e) {
            return $e->status;
        }
    }

    /** Sin filas en barber_hours el barbero tiene ventana todo el día y se le puede reservar a primera hora. */
    public function testSinHorarioCargadoAtiendeTodoElDia(): void
    {
        $day = $this->futureOpenDay();
        $this->assertNotNull(BarberHours::window(Barber::find('nico')->key, $day));
        $this->assertSame(0, $this->statusOf(fn () => Booking::create($this->makeUser('99000001'), 'corte', 'nico', $day, '10:00')));
    }

    /**
     * Un día marcado como libre no ofrece horarios para ese barbero y Booking::create responde 422; "Cualquiera" lo
     * saltea y asigna a otro.
     */
    public function testDiaLibreNoOfreceNiAceptaTurnos(): void
    {
        $day = $this->futureOpenDay();
        $this->setNico($day, false);

        $this->assertSame([], array_filter(array_column(Schedule::slots($day, 'nico', 30), 'free')));
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($this->makeUser('99000001'), 'corte', 'nico', $day, '10:00')));
        // "Cualquiera" lo saltea y asigna a otro barbero.
        $a = Booking::create($this->makeUser('99000002'), 'corte', 'any', $day, '10:00');
        $this->assertNotSame('nico', $a['barber_id']);
    }

    /**
     * Con franja 14:00–17:00 solo se ofrecen y aceptan turnos dentro de ella: 10:00 da 422, 14:00 se reserva y 17:00
     * (su hora de salida) no se ofrece.
     */
    public function testSoloDentroDeSuFranja(): void
    {
        $day = $this->futureOpenDay();
        $this->setNico($day, true, '14:00', '17:00');
        $u = $this->makeUser('99000001');

        $free = array_column(Schedule::slots($day, 'nico', 30), 'free', 'time');
        $this->assertFalse($free['10:00']);
        $this->assertTrue($free['14:00']);
        $this->assertFalse($free['17:00'], 'empieza en su hora de salida');
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte', 'nico', $day, '10:00')));
        $this->assertSame(0, $this->statusOf(fn () => Booking::create($u, 'corte', 'nico', $day, '14:00')));
    }

    /**
     * Schedule::saveOpenWeekdays abre el lunes (que la semilla deja cerrado) sin cambiar el miércoles por orden de
     * llegada; al volver a cerrarlo, el turno ya reservado se conserva y countUpcomingOnWeekdays lo cuenta para el
     * aviso. Restaura los días de data/schedule.php al final.
     */
    public function testElAdminEligeLosDiasQueAbreElLocal(): void
    {
        $monday = date('Y-m-d', strtotime('next monday'));
        $this->assertFalse(Schedule::isOpen($monday), 'la semilla deja el lunes cerrado');
        try {
            Schedule::saveOpenWeekdays([1, 2, 3, 4, 5, 6]);
            $this->assertTrue(Schedule::isOpen($monday));
            $this->assertSame('open', Schedule::weekdayStatus()[1]);
            $this->assertSame('walkin', Schedule::weekdayStatus()[3], 'el miércoles sigue por orden de llegada');

            // Un turno el lunes y después se cierra el lunes: el turno queda y se puede contar para avisar.
            Booking::create($this->makeUser('99000001'), 'corte', 'nico', $monday, '10:00');
            Schedule::saveOpenWeekdays([2, 3, 4, 5, 6]);
            $this->assertFalse(Schedule::isOpen($monday));
            $this->assertSame(1, \App\Models\Appointment::countUpcomingOnWeekdays([1]));
        } finally {
            Schedule::saveOpenWeekdays([2, 3, 4, 5, 6]); // como en data/schedule.php
        }
    }

    /**
     * BarberHours::validate rechaza franja invertida, hora con texto y día fuera de 0–6; normaliza "9:30" a "09:30"
     * y un día sin tilde queda con works = false.
     */
    public function testValidacion(): void
    {
        [, $error] = BarberHours::validate([4 => ['works' => '1', 'from' => '18:00', 'to' => '10:00']]);
        $this->assertNotNull($error);
        [, $error] = BarberHours::validate([4 => ['works' => '1', 'from' => 'mañana', 'to' => '10:00']]);
        $this->assertNotNull($error);
        [, $error] = BarberHours::validate([9 => ['works' => '1', 'from' => '10:00', 'to' => '12:00']]);
        $this->assertNotNull($error);
        [$days, $error] = BarberHours::validate([4 => ['from' => '9:30', 'to' => '13:00']]);
        $this->assertNull($error);
        $this->assertSame(['works' => false, 'from' => '09:30', 'to' => '13:00'], $days[4]);
    }
}
