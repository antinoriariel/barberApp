<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Barber;

/** Alta de barberos desde el panel: validación, slug único y foto. */
final class BarberTest extends DbTestCase
{
    /** Datos válidos de una ficha nueva (se pisan campos para probar cada límite). */
    private function input(array $over = []): array
    {
        return $over + ['name' => 'Tomi Prueba', 'role' => 'Diseños', 'years' => '3', 'rating' => '4,7', 'tone' => '#12ab34'];
    }

    protected function tearDown(): void
    {
        Database::execute("DELETE FROM barbers WHERE slug LIKE 'tomi-prueba%'");
        parent::tearDown();
    }

    public function testValidaYNormaliza(): void
    {
        [$data, $error] = Barber::validate($this->input());
        $this->assertNull($error);
        $this->assertSame(['name' => 'Tomi Prueba', 'role' => 'Diseños', 'years' => 3, 'rating' => 4.7, 'tone' => '#12AB34'], $data);
    }

    public function testRechazaDatosInvalidos(): void
    {
        foreach ([['name' => 'T'], ['role' => ''], ['years' => '61'], ['years' => 'x'], ['rating' => '5,5'],
                  ['rating' => '0'], ['tone' => 'red'], ['tone' => '#fff']] as $bad) {
            $this->assertNotNull(Barber::validate($this->input($bad))[1], json_encode($bad));
        }
    }

    public function testCreaConSlugUnicoYApareceEnElEquipo(): void
    {
        [$data] = Barber::validate($this->input());
        $this->assertSame('tomi-prueba', Barber::create($data));
        $this->assertSame('tomi-prueba-2', Barber::create($data));

        $b = Barber::find('tomi-prueba');
        $this->assertSame('Tomi Prueba', $b->name);
        $this->assertSame('4,7', $b->rating);
        $this->assertNull($b->photo);
        $this->assertContains('tomi-prueba', array_map(fn (Barber $x) => $x->id, Barber::all()));

        Barber::setPhoto($b->key, '/img/barberos/subidas/tomi-prueba-0123abcd.webp');
        $this->assertSame('/img/barberos/subidas/tomi-prueba-0123abcd.webp', Barber::find('tomi-prueba')->photo);
        Barber::setPhoto($b->key, null);
        $this->assertNull(Barber::find('tomi-prueba')->photo);
    }
}
