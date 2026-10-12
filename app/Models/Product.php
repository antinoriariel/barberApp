<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Data;
use App\Core\Database;

/**
 * Producto a la venta en el local (cera, gel, polvo de peinar…). No se reserva ni se compra online: se muestra en
 * la pestaña Productos de Servicios y se paga en el mostrador.
 *
 * Con base de datos sale de la tabla `products` (el administrador los agrega, edita y oculta en /admin/servicios);
 * con driver files, de data/products.php (solo lectura).
 */
final class Product
{
    /**
     * Objeto de valor inmutable con lo que ve el cliente (slug, nombre, descripción y precio en pesos). Lo arman las
     * consultas de este modelo o data/products.php; la validación de lo que carga el admin está en validate().
     */
    public function __construct(
        public readonly string $id,          // slug único
        public readonly string $name,
        public readonly string $description,
        public readonly int $price,          // en pesos
    ) {
    }

    /**
     * Productos visibles para el cliente, en el orden configurado (o el de data/products.php).
     * @return self[]
     */
    public static function all(): array
    {
        if (Database::enabled()) {
            return array_map(
                fn (array $r) => new self($r['slug'], $r['name'], $r['description'], (int) $r['price']),
                Database::select('SELECT slug, name, description, price FROM products WHERE active = 1 ORDER BY sort_order, id')
            );
        }
        return array_map(
            fn (array $r) => new self($r['id'], $r['name'], $r['description'], (int) $r['price']),
            Data::get('products')
        );
    }

    /**
     * Todos los productos, visibles u ocultos, para el panel del administrador. Solo con base de datos.
     * @return array<int, array{id: int, slug: string, name: string, description: string, price: int, active: bool}>
     */
    public static function allForAdmin(): array
    {
        return array_map(fn (array $r) => [
            'id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'description' => $r['description'],
            'price' => (int) $r['price'], 'active' => (bool) $r['active'],
        ], Database::select('SELECT id, slug, name, description, price, active FROM products ORDER BY active DESC, sort_order, id'));
    }

    /**
     * Valida y normaliza lo que carga el administrador (mismos límites que Service::validate, sin duración).
     * @return array{0: array{name: string, description: string, price: int}|null, 1: string|null} [datos, error]
     */
    public static function validate(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $desc = trim((string) ($in['description'] ?? ''));
        $price = filter_var($in['price'] ?? '', FILTER_VALIDATE_INT);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return [null, 'El nombre del producto debe tener entre 2 y 80 caracteres.'];
        }
        if (mb_strlen($desc) > 190) {
            return [null, 'La descripción admite hasta 190 caracteres.'];
        }
        if ($price === false || $price < 0 || $price > 10_000_000) {
            return [null, 'El precio debe ser un número entero de pesos.'];
        }
        return [['name' => $name, 'description' => $desc, 'price' => $price], null];
    }

    /** Actualiza nombre, descripción y precio; false si el producto no existe. */
    public static function update(int $id, array $d): bool
    {
        if (Database::selectOne('SELECT id FROM products WHERE id = ?', [$id]) === null) {
            return false;
        }
        Database::execute('UPDATE products SET name = ?, description = ?, price = ? WHERE id = ?',
            [$d['name'], $d['description'], $d['price'], $id]);
        return true;
    }

    /** Crea un producto visible al final de la lista; el slug sale del nombre y se hace único con un sufijo. */
    public static function create(array $d): int
    {
        $base = slugify($d['name'], 'producto');
        $slug = $base;
        for ($n = 2; Database::selectOne('SELECT id FROM products WHERE slug = ?', [$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        $order = (int) (Database::selectOne('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM products')['n'] ?? 1);
        Database::execute('INSERT INTO products (slug, name, description, price, sort_order) VALUES (?, ?, ?, ?, ?)',
            [$slug, $d['name'], $d['description'], $d['price'], $order]);
        return Database::lastId();
    }

    /** Muestra u oculta un producto en la app (se oculta en vez de borrarlo, igual que los servicios). */
    public static function setActive(int $id, bool $active): void
    {
        Database::execute('UPDATE products SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }
}
