<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Barber;
use App\Models\Dashboard;

/** Dashboard::clients: nuevos contra recurrentes del mes y top de clientes por lo gastado. */
final class DashboardClientsTest extends DbTestCase
{
    /**
     * Un cliente que ya vino antes del mes es recurrente; uno cuyo primer turno atendido cae en el mes es nuevo.
     * Ausentes y reservados no cuentan. El top va de mayor a menor gasto en el mes.
     */
    public function testSeparaNuevosDeRecurrentesYOrdenaElTop(): void
    {
        $barber = Barber::all()[0];
        $service = Database::selectOne('SELECT id FROM services WHERE active = 1 ORDER BY id LIMIT 1');
        /** Inserta un turno de 30 minutos de $userId en $start con $price y $status. */
        $insert = static function (int $userId, string $start, int $price, string $status = 'done') use ($barber, $service): void {
            Database::execute(
                'INSERT INTO appointments (user_id, barber_id, service_id, starts_at, ends_at, price, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $barber->key, $service['id'], $start, date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')), $price, $status]
            );
        };
        $old = $this->makeUser('99710001');
        $new = $this->makeUser('99710002');
        $other = $this->makeUser('99710003');
        $ghost = $this->makeUser('99710004');

        $insert($old->id, '2032-01-10 10:00:00', 9000);   // vino el mes anterior → recurrente
        $insert($old->id, '2032-02-03 10:00:00', 10000);
        $insert($new->id, '2032-02-05 10:00:00', 12000);
        $insert($new->id, '2032-02-20 10:00:00', 12000);
        $insert($other->id, '2032-02-07 10:00:00', 5000);
        $insert($ghost->id, '2032-02-08 10:00:00', 50000, 'no_show'); // no cuenta
        $insert($ghost->id, '2032-02-28 10:00:00', 50000, 'booked');  // no cuenta

        $c = Dashboard::clients('2032-02-15');
        $this->assertSame('2032-02', $c['month']);
        $this->assertSame(['new' => 2, 'returning' => 1, 'total' => 3], ['new' => $c['new'], 'returning' => $c['returning'], 'total' => $c['total']]);
        $this->assertSame(['Prueba 99710002', 'Prueba 99710001', 'Prueba 99710003'], array_column($c['top'], 'name'));
        $this->assertSame([24000, 10000, 5000], array_column($c['top'], 'spent'));
        $this->assertSame([2, 1, 1], array_column($c['top'], 'visits'));
        $this->assertSame([true, false, true], array_column($c['top'], 'isNew'));

        $this->assertSame(0, Dashboard::clients('2032-04-01')['total'], 'mes sin turnos atendidos');
    }
}
