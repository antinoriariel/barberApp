<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Data;

/**
 * Producto a la venta en el local (cera, gel, polvo de peinar…).
 *
 * Es un catálogo estático: sale siempre de data/products.php, con cualquier driver,
 * porque no participa de reservas ni de la contabilidad. Si algún día se venden
 * online, pasaría a una tabla como el resto de los modelos.
 */
final class Product
{
    public function __construct(
        public readonly string $id,          // slug único
        public readonly string $name,
        public readonly string $description,
        public readonly int $price,          // en pesos
    ) {
    }

    /**
     * Todos los productos, en el orden de data/products.php.
     * @return self[]
     */
    public static function all(): array
    {
        return array_map(
            fn (array $r) => new self($r['id'], $r['name'], $r['description'], (int) $r['price']),
            Data::get('products')
        );
    }
}
