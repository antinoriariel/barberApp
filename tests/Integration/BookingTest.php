<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Appointment;
use App\Models\Schedule;
use App\Services\Booking;
use App\Services\BookingException;

/** Reglas de reservar, cancelar y reprogramar contra la base de pruebas. */
final class BookingTest extends DbTestCase
{
    /** Ejecuta $fn y devuelve el código HTTP de la BookingException que lance (o 0 si no lanza). */
    private function statusOf(callable $fn): int
    {
        try {
            $fn();
            return 0;
        } catch (BookingException $e) {
            return $e->status;
        }
    }

    public function testCrearYLeerElTurno(): void
    {
        $u = $this->makeUser('99000001');
        $day = $this->futureOpenDay();
        $a = Booking::create($u, 'corte', 'nico', $day, '10:00');

        $this->assertSame('corte', $a['service_id']);
        $this->assertSame('nico', $a['barber_id']);
        $this->assertSame('10:00', $a['time']);
        $row = Database::selectOne('SELECT price, status, TIMESTAMPDIFF(MINUTE, starts_at, ends_at) AS mins FROM appointments WHERE id = ?', [$a['id']]);
        $this->assertSame(12000, (int) $row['price'], 'el precio sale del catálogo, no del cliente');
        $this->assertSame(40, (int) $row['mins']);
        $this->assertSame('booked', $row['status']);
    }

    public function testNoSePuedeReservarElMismoBarberoYHorario(): void
    {
        $a = $this->makeUser('99000001');
        $b = $this->makeUser('99000002');
        $day = $this->futureOpenDay();
        Booking::create($a, 'corte', 'nico', $day, '10:00');

        $this->assertSame(409, $this->statusOf(fn () => Booking::create($b, 'barba', 'nico', $day, '10:00')));
        // Un turno que solo se superpone parcialmente (corte de 40' desde 10:00 vs. 10:45 de 30' no se pisan; 60' sí).
        $this->assertSame(409, $this->statusOf(fn () => Booking::create($b, 'color', 'nico', $day, '10:00')));
    }

    public function testCualquieraAsignaElSiguienteBarberoLibre(): void
    {
        $a = $this->makeUser('99000001');
        $b = $this->makeUser('99000002');
        $day = $this->futureOpenDay();
        $first = Booking::create($a, 'corte', 'any', $day, '10:00');
        $second = Booking::create($b, 'corte', 'any', $day, '10:00');
        $this->assertNotSame($first['barber_id'], $second['barber_id']);
    }

    public function testValidacionesDelServidor(): void
    {
        $u = $this->makeUser('99000001');
        $day = $this->futureOpenDay();
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'inexistente', 'any', $day, '10:00')));
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte', 'nadie', $day, '10:00')));
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte', 'any', $day, '10:01')), 'hora fuera de la grilla');
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte', 'any', '2020-01-07', '10:00')), 'pasado');
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte', 'any', date('Y-m-d', strtotime('+200 days')), '10:00')), 'fuera de la ventana');
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte', 'any', 'no-es-fecha', '10:00')));
        $closed = date('Y-m-d', strtotime('next monday'));
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte', 'any', $closed, '10:00')), 'lunes cerrado');
        $wed = date('Y-m-d', strtotime('next wednesday'));
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte', 'any', $wed, '10:00')), 'miércoles por orden de llegada');
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'color', 'any', $day, '19:15')), 'termina después del cierre');
    }

    public function testNoSePuedeSuperponerConUnoPropioNiPasarElLimite(): void
    {
        $u = $this->makeUser('99000001');
        $day = $this->futureOpenDay();
        Booking::create($u, 'corte', 'nico', $day, '10:00');
        $this->assertSame(409, $this->statusOf(fn () => Booking::create($u, 'barba', 'fran', $day, '10:00')), 'superpuesto');

        Booking::create($u, 'cejas', 'fran', $day, '12:15');
        Booking::create($u, 'cejas', 'fran', $day, '14:00');
        $this->assertSame(409, $this->statusOf(fn () => Booking::create($u, 'cejas', 'fran', $day, '15:30')), 'máximo de turnos futuros');
    }

    public function testCancelarSoloLoPropioYUnaVez(): void
    {
        $owner = $this->makeUser('99000001');
        $other = $this->makeUser('99000002');
        $a = Booking::create($owner, 'corte', 'nico', $this->futureOpenDay(), '10:00');

        $this->assertSame(404, $this->statusOf(fn () => Booking::cancel($other, $a['id'])), 'turno ajeno');
        Booking::cancel($owner, $a['id']);
        $this->assertSame(404, $this->statusOf(fn () => Booking::cancel($owner, $a['id'])), 'ya cancelado');
        // Al cancelar, el horario vuelve a estar disponible.
        Booking::create($other, 'corte', 'nico', $this->futureOpenDay(), '10:00');
        $this->addToAssertionCount(1);
    }

    public function testReprogramarEsAtomico(): void
    {
        $u = $this->makeUser('99000001');
        $day = $this->futureOpenDay();
        $old = Booking::create($u, 'corte', 'nico', $day, '10:00');

        $new = Booking::reschedule($u, $old['id'], 'corte', 'fran', $day, '14:00');
        $this->assertSame('14:00', $new['time']);
        $this->assertSame('cancelled', Database::selectOne('SELECT status FROM appointments WHERE id = ?', [$old['id']])['status']);

        // Si el nuevo horario es inválido, el turno que se quería mover sigue reservado.
        $this->assertSame(422, $this->statusOf(fn () => Booking::reschedule($u, $new['id'], 'corte', 'fran', $day, '12:00')));
        $this->assertSame('booked', Database::selectOne('SELECT status FROM appointments WHERE id = ?', [$new['id']])['status']);
    }

    public function testVariosServiciosEnUnTurno(): void
    {
        $u = $this->makeUser('99000001');
        $day = $this->futureOpenDay();
        // corte (40 min, $12.000) + cejas (10 min, $3.500): un solo turno de 50 min por $15.500.
        $a = Booking::create($u, 'corte,cejas', 'nico', $day, '10:00');
        $this->assertSame('corte,cejas', $a['service_id']);
        $this->assertSame('Corte clásico + Perfilado de cejas', $a['service']);

        $row = Database::selectOne('SELECT price, TIMESTAMPDIFF(MINUTE, starts_at, ends_at) AS minutes FROM appointments WHERE id = ?', [$a['id']]);
        $this->assertSame(15500, (int) $row['price']);
        $this->assertSame(50, (int) $row['minutes']);
        $this->assertSame(2, (int) Database::selectOne('SELECT COUNT(*) AS n FROM appointment_services WHERE appointment_id = ?', [$a['id']])['n']);

        $free = array_column(Schedule::slots($day, 'nico', 40), 'free', 'time');
        $this->assertFalse($free['10:45'], 'los 50 min combinados pisan el horario de las 10:45');

        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte,inexistente', 'any', $day, '14:00')), 'uno inválido');
        $this->assertSame(422, $this->statusOf(fn () => Booking::create($u, 'corte,barba,fade,cejas,color', 'any', $day, '14:00')), 'más del máximo');
    }

    public function testDisponibilidadReflejaLosTurnos(): void
    {
        $u = $this->makeUser('99000001');
        $day = $this->futureOpenDay();
        Booking::create($u, 'combo', 'nico', $day, '10:00'); // 60 min: ocupa 10:00 y 10:45

        $free = fn (string $barber) => array_column(Schedule::slots($day, $barber, 40), 'free', 'time');
        $this->assertFalse($free('nico')['10:00']);
        $this->assertFalse($free('nico')['10:45'], 'el combo dura 60 min y pisa el horario de las 10:45');
        $this->assertTrue($free('nico')['11:30']);
        $this->assertTrue($free('fran')['10:00']);
        $this->assertTrue($free('any')['10:00'], 'cualquiera: queda otro barbero libre');
        $closed = array_column(Schedule::slots(date('Y-m-d', strtotime('next monday')), 'any', 40), 'free');
        $this->assertNotContains(true, $closed, 'un día cerrado no tiene horarios libres');
        $wed = array_column(Schedule::slots(date('Y-m-d', strtotime('next wednesday')), 'any', 40), 'free');
        $this->assertNotContains(true, $wed, 'los miércoles no se reservan');
        $this->assertNotContains('Mié', array_column(Schedule::openDays(7), 'label'), 'los miércoles no se ofrecen');
    }

    public function testHistorialYProximoSoloDelCliente(): void
    {
        $a = $this->makeUser('99000001');
        $b = $this->makeUser('99000002');
        $day = $this->futureOpenDay();
        Booking::create($a, 'corte', 'nico', $day, '10:00');

        $this->assertSame('10:00', Appointment::next($a)['time']);
        $this->assertNull(Appointment::next($b));
        $this->assertSame([], Appointment::history($b));
    }
}
