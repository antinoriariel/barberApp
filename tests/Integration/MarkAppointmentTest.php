<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Dashboard;
use App\Services\Booking;

/** Cerrar turnos desde el panel: si lo atiende otro barbero, el corte pasa a su nombre con la observación. */
final class MarkAppointmentTest extends DbTestCase
{
    /** Id (barbers.id) de una ficha por su slug. */
    private function barberId(string $slug): int
    {
        return (int) Database::selectOne('SELECT id FROM barbers WHERE slug = ?', [$slug])['id'];
    }

    /** Cortes atendidos de un barbero en el día (según Dashboard::day). */
    private function served(string $day, int $barberId): int
    {
        foreach (Dashboard::day($day)['barbers'] as $b) {
            if ($b['id'] === $barberId) {
                return $b['served'];
            }
        }
        return 0;
    }

    public function testOtroBarberoLoAtiendeYSeLoCuenta(): void
    {
        $u = $this->makeUser('99000001');
        $day = $this->futureOpenDay();
        $id = (int) Booking::create($u, 'corte', 'nico', $day, '10:00')['id'];
        $nico = $this->barberId('nico');
        $other = (int) Database::selectOne('SELECT id FROM barbers WHERE id <> ? AND active = 1 ORDER BY id LIMIT 1', [$nico])['id'];
        $before = [$this->served($day, $nico), $this->served($day, $other)];

        $this->assertTrue(Dashboard::mark($id, 'done', $other));

        $row = Database::selectOne('SELECT a.status, a.barber_id, a.notes, b.name FROM appointments a JOIN barbers b ON b.id = ? WHERE a.id = ?', [$nico, $id]);
        $this->assertSame('done', $row['status']);
        $this->assertSame($other, (int) $row['barber_id'], 'el turno pasa al barbero que lo atendió');
        $this->assertSame('Reservado con ' . $row['name'], $row['notes']);
        $this->assertSame([$before[0], $before[1] + 1], [$this->served($day, $nico), $this->served($day, $other)]);
        $this->assertFalse(Dashboard::mark($id, 'done', $other), 'ya no estaba reservado');
    }

    public function testElMismoBarberoOElAdminNoCambianNada(): void
    {
        $u = $this->makeUser('99000001');
        $day = $this->futureOpenDay();
        $nico = $this->barberId('nico');
        $mine = (int) Booking::create($u, 'corte', 'nico', $day, '10:00')['id'];
        $admin = (int) Booking::create($u, 'corte', 'nico', $day, '10:45')['id'];
        $absent = (int) Booking::create($u, 'corte', 'nico', $day, '14:00')['id'];
        $other = (int) Database::selectOne('SELECT id FROM barbers WHERE id <> ? AND active = 1 ORDER BY id LIMIT 1', [$nico])['id'];

        $this->assertTrue(Dashboard::mark($mine, 'done', $nico));
        $this->assertTrue(Dashboard::mark($admin, 'done', null));
        $this->assertTrue(Dashboard::mark($absent, 'no_show', $other), 'un ausente no se reasigna');

        foreach ([$mine, $admin, $absent] as $id) {
            $row = Database::selectOne('SELECT barber_id, notes FROM appointments WHERE id = ?', [$id]);
            $this->assertSame([$nico, ''], [(int) $row['barber_id'], $row['notes']]);
        }
    }
}
