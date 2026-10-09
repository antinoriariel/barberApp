<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;

/**
 * Carrera de doble reserva: dos procesos PHP reales (con su propia conexión y transacción) piden el mismo
 * barbero y horario a la vez. Solo uno puede ganar; el otro recibe 409 y la base queda con un único turno.
 */
final class ConcurrentBookingTest extends DbTestCase
{
    public function testDosProcesosSimultaneosSoloUnoReserva(): void
    {
        $this->makeUser('99000011');
        $this->makeUser('99000012');
        $day = $this->futureOpenDay(1);

        for ($round = 0; $round < 3; $round++) { // varias rondas: una carrera rara vez falla a la primera
            $time = ['10:00', '10:45', '11:30'][$round];
            $startAt = microtime(true) + 1.5;
            $procs = [];
            foreach (['99000011', '99000012'] as $dni) {
                $cmd = [PHP_BINARY, dirname(__DIR__) . '/worker_book.php', $dni, 'nico', $day, $time, sprintf('%.4F', $startAt)];
                $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['DB_NAME' => 'navaja_test']));
                $this->assertIsResource($proc);
                $procs[] = [$proc, $pipes];
            }

            $results = [];
            foreach ($procs as [$proc, $pipes]) {
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                proc_close($proc);
                $results[] = json_decode($out, true) ?? $this->fail("Salida inválida del worker: {$out} {$err}");
            }

            $wins = array_filter($results, fn (array $r) => $r['ok']);
            $this->assertCount(1, $wins, "ronda {$round}: debe ganar exactamente uno. " . json_encode($results));
            $loser = array_values(array_filter($results, fn (array $r) => !$r['ok']))[0];
            $this->assertSame(409, $loser['status']);

            $rows = Database::selectOne(
                "SELECT COUNT(*) AS n FROM appointments a JOIN barbers b ON b.id = a.barber_id
                  WHERE b.slug = 'nico' AND a.status = 'booked' AND a.starts_at = ?", ["{$day} {$time}:00"]
            );
            $this->assertSame(1, (int) $rows['n'], 'un solo turno reservado en ese horario');
        }
    }
}
