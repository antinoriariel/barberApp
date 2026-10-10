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

    public function testSinHorarioCargadoAtiendeTodoElDia(): void
    {
        $day = $this->futureOpenDay();
        $this->assertNotNull(BarberHours::window(Barber::find('nico')->key, $day));
        $this->assertSame(0, $this->statusOf(fn () => Booking::create($this->makeUser('99000001'), 'corte', 'nico', $day, '10:00')));
    }

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
