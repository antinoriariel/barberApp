<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Product;

/** Productos del local: el administrador los agrega, les cambia el precio y los oculta. */
final class ProductTest extends DbTestCase
{
    /** Borra los productos de prueba (slug "prueba…") y luego limpia usuarios de prueba. */
    protected function tearDown(): void
    {
        Database::execute("DELETE FROM products WHERE slug LIKE 'prueba%'");
        parent::tearDown();
    }

    /**
     * Alta con slug sin acentos ni eñes, edición de precio, update de un id inexistente (false) y ocultar: deja de
     * verse en la app pero el admin lo sigue viendo.
     */
    public function testAgregarEditarYOcultar(): void
    {
        [$data] = Product::validate(['name' => 'Prueba Ñandú', 'description' => 'x', 'price' => '4200']);
        $id = Product::create($data);
        $this->assertSame('prueba-nandu', Database::selectOne('SELECT slug FROM products WHERE id = ?', [$id])['slug']);
        $this->assertContains('prueba-nandu', array_map(fn (Product $p) => $p->id, Product::all()));

        $this->assertTrue(Product::update($id, ['name' => 'Prueba Ñandú', 'description' => 'y', 'price' => 5000]));
        $this->assertSame(5000, (int) Database::selectOne('SELECT price FROM products WHERE id = ?', [$id])['price']);
        $this->assertFalse(Product::update(999999, $data), 'producto inexistente');

        Product::setActive($id, false);
        $this->assertNotContains('prueba-nandu', array_map(fn (Product $p) => $p->id, Product::all()), 'oculto no se ve en la app');
        $this->assertContains($id, array_column(Product::allForAdmin(), 'id'), 'el admin lo sigue viendo');
    }

    /**
     * Product::validate rechaza nombre corto, precio negativo o con decimales; precio 0 y descripción vacía son
     * válidos.
     */
    public function testValidacion(): void
    {
        $this->assertNotNull(Product::validate(['name' => 'X', 'price' => '100'])[1], 'nombre corto');
        $this->assertNotNull(Product::validate(['name' => 'Cera', 'price' => '-5'])[1], 'precio negativo');
        $this->assertNotNull(Product::validate(['name' => 'Cera', 'price' => '12,5'])[1], 'precio no entero');
        $this->assertNull(Product::validate(['name' => 'Cera', 'description' => '', 'price' => '0'])[1]);
    }
}
