<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Dashboard;
use App\Models\Income;

/** Ingresos extra: validación del formulario, totales por día y mes, y suma al resultado de finanzas. */
final class IncomeTest extends DbTestCase
{
    /** Mes lejano para no mezclarse con datos de la semilla. */
    private const MONTH = '2031-03';

    /** Borra los ingresos del mes de prueba (2031-03) y luego limpia usuarios de prueba. */
    protected function tearDown(): void
    {
        Database::execute('DELETE FROM incomes WHERE received_on >= ? AND received_on < ?', [self::MONTH . '-01', '2031-04-01']);
        parent::tearDown();
    }

    /**
     * Income::validate acepta montos con punto de miles y recorta la observación; rechaza fecha inexistente,
     * categoría fuera de la lista, monto cero, negativo o arreglo, y observación de más de 255 caracteres.
     */
    public function testValidaFechaCategoriaMontoYObservacion(): void
    {
        $ok = ['received_on' => self::MONTH . '-05', 'category' => 'Corte extra', 'amount' => '15.000', 'notes' => ' sin turno '];
        [$data, $error] = Income::validate($ok);
        $this->assertNull($error);
        $this->assertSame(['received_on' => self::MONTH . '-05', 'category' => 'Corte extra', 'notes' => 'sin turno', 'amount' => 15000], $data);

        $this->assertNotNull(Income::validate(['received_on' => '2031-02-30'] + $ok)[1], 'fecha inexistente');
        $this->assertNotNull(Income::validate(['category' => 'Alquiler'] + $ok)[1], 'categoría fuera de la lista');
        $this->assertNotNull(Income::validate(['amount' => '0'] + $ok)[1]);
        $this->assertNotNull(Income::validate(['amount' => '-5'] + $ok)[1]);
        $this->assertNotNull(Income::validate(['amount' => ['1', '2']] + $ok)[1], 'arreglo en vez de texto');
        $this->assertNotNull(Income::validate(['notes' => str_repeat('a', 256)] + $ok)[1]);
    }

    /**
     * Los ingresos se suman por día, se listan del más nuevo al más viejo y Dashboard::finance los agrega como
     * "extra" al resultado del mes; borrar uno lo descuenta y borrarlo dos veces devuelve false.
     */
    public function testTotalesYResultadoDelMes(): void
    {
        $before = Dashboard::finance(self::MONTH . '-01', 1)[0];
        Income::create(['received_on' => self::MONTH . '-05', 'category' => 'Corte extra', 'notes' => '', 'amount' => 10000], null);
        Income::create(['received_on' => self::MONTH . '-05', 'category' => 'Otros', 'notes' => 'propina', 'amount' => 2500], null);
        $id = Income::create(['received_on' => self::MONTH . '-20', 'category' => 'Otros', 'notes' => '', 'amount' => 1000], null);

        $this->assertSame(12500, Income::totalForDay(self::MONTH . '-05'));
        $this->assertCount(3, Income::forMonth(self::MONTH));
        $this->assertSame(self::MONTH . '-20', Income::forMonth(self::MONTH)[0]['received_on'], 'el más nuevo primero');

        $after = Dashboard::finance(self::MONTH . '-01', 1)[0];
        $this->assertSame(13500, $after['extra']);
        $this->assertSame($before['net'] + 13500, $after['net'], 'el ingreso extra se suma al resultado');

        $this->assertTrue(Income::delete($id));
        $this->assertFalse(Income::delete($id));
        $this->assertSame(12500, Dashboard::finance(self::MONTH . '-01', 1)[0]['extra']);
    }
}
