<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\QueueTicket;

/** Fila por orden de llegada: números seguidos, uno activo por cliente, lugar en la fila y llamado en orden. */
final class QueueTest extends DbTestCase
{
    /** Vacía la fila de hoy para que los números arranquen en 1 en cada prueba. */
    protected function setUp(): void
    {
        Database::execute('DELETE FROM queue_tickets WHERE day = ?', [date('Y-m-d')]); // fila de hoy vacía
    }

    /**
     * Cada cliente recibe el número siguiente, sabe cuántos tiene adelante y después de quién va; volver a escanear
     * devuelve el mismo número.
     */
    public function testNumerosSeguidosYLugarEnLaFila(): void
    {
        [$a, $b, $c] = [$this->makeUser('99000001'), $this->makeUser('99000002'), $this->makeUser('99000003')];

        $ta = QueueTicket::take($a);
        $tb = QueueTicket::take($b);
        $tc = QueueTicket::take($c);
        $this->assertSame([1, 2, 3], [$ta['number'], $tb['number'], $tc['number']], 'cada escaneo recibe el siguiente');

        $this->assertSame(0, $ta['ahead']);
        $this->assertNull($ta['before'], 'el primero no está detrás de nadie');
        $this->assertSame(2, $tc['ahead']);
        $this->assertSame(2, $tc['before'], 'el tercero está después del 2');

        $this->assertSame(3, QueueTicket::take($c)['number'], 'escanear de nuevo no lo manda al final');
    }

    /**
     * callNext llama en orden y marca atendido al anterior, quien deja la fila es salteado, sin nadie esperando
     * devuelve null y quien vuelve saca un número nuevo al final.
     */
    public function testLlamarEnOrdenYDejarLaFila(): void
    {
        [$a, $b, $c] = [$this->makeUser('99000001'), $this->makeUser('99000002'), $this->makeUser('99000003')];
        QueueTicket::take($a);
        QueueTicket::take($b);
        QueueTicket::take($c);

        $this->assertSame(1, QueueTicket::callNext());
        $this->assertSame('called', QueueTicket::currentFor($a)['status']);
        $this->assertSame(1, QueueTicket::currentFor($c)['serving']);

        $this->assertTrue(QueueTicket::leave($b), 'el 2 se va');
        $this->assertSame(0, QueueTicket::currentFor($c)['ahead'], 'el 3 pasa a ser el próximo');

        $this->assertSame(3, QueueTicket::callNext(), 'se saltea al que se fue');
        $this->assertNull(QueueTicket::currentFor($a), 'al llamar al siguiente, el anterior queda atendido');
        $this->assertNull(QueueTicket::callNext(), 'no queda nadie esperando');

        $this->assertSame(4, QueueTicket::take($b)['number'], 'si vuelve, saca un número nuevo al final');
    }
}
