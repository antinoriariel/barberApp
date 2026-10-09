<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Qr;
use PHPUnit\Framework\TestCase;

/**
 * Estructura del QR generado (tamaño por versión, patrones fijos) y límite de largo. La lectura real con una cámara
 * se verificó decodificando las imágenes con OpenCV en las versiones 1 a 6.
 */
final class QrTest extends TestCase
{
    public function testVersionSegunElLargo(): void
    {
        $this->assertCount(21, Qr::matrix('hola'), 'versión 1: 21×21');
        $this->assertCount(29, Qr::matrix('http://localhost:8000/fila?c=mostrador-navaja'), 'versión 3: 29×29');
        $this->assertCount(41, Qr::matrix(str_repeat('x', 120)), 'versión 6: 41×41');
    }

    public function testPatronesBuscadoresYTemporizacion(): void
    {
        $m = Qr::matrix('hola');
        $n = count($m);
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$y, $x]) {
            $this->assertTrue($m[$y][$x] && $m[$y + 6][$x + 6], 'esquinas del buscador oscuras');
            $this->assertFalse($m[$y + 1][$x + 1], 'anillo claro del buscador');
            $this->assertTrue($m[$y + 3][$x + 3], 'centro del buscador oscuro');
        }
        for ($i = 8; $i < $n - 8; $i++) {
            $this->assertSame($i % 2 === 0, $m[6][$i], 'fila de temporización alternada');
        }
        $this->assertTrue($m[$n - 8][8], 'módulo oscuro fijo');
    }

    public function testSvgYLimite(): void
    {
        $svg = Qr::svg('hola', 200);
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('viewBox="0 0 29 29"', $svg, '21 módulos + 4 de margen por lado');

        $this->expectException(\InvalidArgumentException::class);
        Qr::matrix(str_repeat('x', 200));
    }
}
