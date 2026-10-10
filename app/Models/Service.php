<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Data;
use App\Core\Database;

/** Servicio del catálogo (corte, barba, combo…). */
final class Service
{
    public function __construct(
        public readonly string $id,          // slug: lo que usan las vistas (data-book-service)
        public readonly string $name,
        public readonly string $description,
        public readonly int $minutes,
        public readonly int $price,
        public readonly int $key = 0,        // clave primaria en la base (0 con driver files)
    ) {
    }

    /** Arma un Service desde una fila de la tabla `services`. */
    private static function fromRow(array $r): self
    {
        return new self($r['slug'], $r['name'], $r['description'], (int) $r['minutes'], (int) $r['price'], (int) $r['id']);
    }

    /**
     * Catálogo completo (solo servicios activos), en el orden configurado.
     * @return self[]
     */
    public static function all(): array
    {
        if (Database::enabled()) {
            return array_map(self::fromRow(...), Database::select(
                'SELECT id, slug, name, description, minutes, price FROM services WHERE active = 1 ORDER BY sort_order, id'
            ));
        }
        return array_map(
            fn (array $r) => new self($r['id'], $r['name'], $r['desc'], $r['min'], $r['price']),
            Data::get('services')
        );
    }

    /** Servicio activo por slug ('corte', 'barba'…) o null. Solo con base de datos. */
    public static function find(string $slug): ?self
    {
        $r = Database::selectOne(
            'SELECT id, slug, name, description, minutes, price FROM services WHERE slug = ? AND active = 1', [$slug]
        );
        return $r ? self::fromRow($r) : null;
    }

    /**
     * Varios servicios activos a partir de una lista de slugs separada por comas ("corte,cejas"), en el orden
     * pedido y sin repetidos. Devuelve null si la lista está vacía, supera $max o alguno no existe.
     * @return self[]|null
     */
    public static function findMany(string $slugs, int $max = 4): ?array
    {
        $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $slugs)), 'strlen')));
        if (!$list || count($list) > $max) {
            return null;
        }
        $found = [];
        foreach ($list as $slug) {
            $service = self::find($slug);
            if ($service === null) {
                return null;
            }
            $found[] = $service;
        }
        return $found;
    }

    /**
     * Los $n servicios "más pedidos" del Inicio: con base de datos, los que aparecen en más turnos (reservados o hechos,
     * contando cada servicio de los turnos con varios);
     * con driver files, los primeros del catálogo.
     * @return self[]
     */
    public static function popular(int $n = 4): array
    {
        if (!Database::enabled()) {
            return array_slice(self::all(), 0, $n);
        }
        return array_map(self::fromRow(...), Database::select(
            "SELECT s.id, s.slug, s.name, s.description, s.minutes, s.price
               FROM services s
               LEFT JOIN appointment_services x ON x.service_id = s.id
               LEFT JOIN appointments a ON a.id = x.appointment_id AND a.status IN ('booked', 'done')
              WHERE s.active = 1
              GROUP BY s.id
              ORDER BY COUNT(a.id) DESC, s.sort_order, s.id
              LIMIT " . (int) $n
        ));
    }

    /**
     * Todos los servicios, activos o no, para el panel del administrador. Solo con base de datos.
     * @return array<int, array{id: int, slug: string, name: string, description: string, minutes: int, price: int, active: bool}>
     */
    public static function allForAdmin(): array
    {
        return array_map(fn (array $r) => [
            'id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'description' => $r['description'],
            'minutes' => (int) $r['minutes'], 'price' => (int) $r['price'], 'active' => (bool) $r['active'],
        ], Database::select('SELECT id, slug, name, description, minutes, price, active FROM services ORDER BY active DESC, sort_order, id'));
    }

    /**
     * Valida y normaliza los campos que edita el administrador.
     * @return array{0: array{name: string, description: string, price: int, minutes: int}|null, 1: string|null} [datos, error]
     */
    public static function validate(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $desc = trim((string) ($in['description'] ?? ''));
        $price = filter_var($in['price'] ?? '', FILTER_VALIDATE_INT);
        $minutes = filter_var($in['minutes'] ?? '', FILTER_VALIDATE_INT);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return [null, 'El nombre debe tener entre 2 y 80 caracteres.'];
        }
        if (mb_strlen($desc) > 190) {
            return [null, 'La descripción admite hasta 190 caracteres.'];
        }
        if ($price === false || $price < 0 || $price > 10_000_000) {
            return [null, 'El precio debe ser un número entero de pesos.'];
        }
        if ($minutes === false || $minutes < 5 || $minutes > 480) {
            return [null, 'La duración (para la agenda) debe estar entre 5 y 480 minutos.'];
        }
        return [['name' => $name, 'description' => $desc, 'price' => $price, 'minutes' => $minutes], null];
    }

    /** Actualiza nombre, descripción, precio y duración; false si el servicio no existe. Los turnos ya hechos conservan su precio. */
    public static function update(int $id, array $d): bool
    {
        if (Database::selectOne('SELECT id FROM services WHERE id = ?', [$id]) === null) {
            return false;
        }
        Database::execute(
            'UPDATE services SET name = ?, description = ?, price = ?, minutes = ? WHERE id = ?',
            [$d['name'], $d['description'], $d['price'], $d['minutes'], $id]
        );
        return true;
    }

    /** Crea un servicio activo al final del catálogo; el slug sale del nombre y se hace único con un sufijo. */
    public static function create(array $d): int
    {
        $base = slugify($d['name'], 'servicio');
        $slug = $base;
        for ($n = 2; Database::selectOne('SELECT id FROM services WHERE slug = ?', [$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        $order = (int) (Database::selectOne('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM services')['n'] ?? 1);
        Database::execute(
            'INSERT INTO services (slug, name, description, minutes, price, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            [$slug, $d['name'], $d['description'], $d['minutes'], $d['price'], $order]
        );
        return Database::lastId();
    }

    /** Muestra u oculta un servicio del catálogo (no se borra: los turnos históricos lo referencian). */
    public static function setActive(int $id, bool $active): void
    {
        Database::execute('UPDATE services SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }
}
