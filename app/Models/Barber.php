<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Data;
use App\Core\Database;

/** Barbero del equipo. */
final class Barber
{
    public function __construct(
        public readonly string $id,          // slug: lo que usan las vistas (data-book-barber)
        public readonly string $name,
        public readonly string $role,
        public readonly int $years,
        public readonly string $rating,
        public readonly string $tone, // color del avatar (se valida con css_color() al imprimirlo)
        public readonly int $key = 0,        // clave primaria en la base (0 con driver files)
        public readonly ?string $photo = null, // ruta pública de su imagen (/img/...) o null si no tiene
    ) {
    }

    /** Arma un Barber desde una fila de `barbers` (la valoración 4.9 se muestra como "4,9"). */
    private static function fromRow(array $r): self
    {
        return new self($r['slug'], $r['name'], $r['role'], (int) $r['years'],
            number_format((float) $r['rating'], 1, ',', ''), $r['tone'], (int) $r['id'], $r['photo'] ?? null);
    }

    /**
     * Todo el equipo activo, en orden de alta (o en el orden de data/barbers.php).
     * @return self[]
     */
    public static function all(): array
    {
        if (Database::enabled()) {
            return array_map(self::fromRow(...), Database::select(
                'SELECT id, slug, name, role, years, rating, tone, photo FROM barbers WHERE active = 1 ORDER BY id'
            ));
        }
        return array_map(
            fn (array $r) => new self($r['id'], $r['name'], $r['role'], $r['years'], $r['rating'], $r['tone'], photo: $r['photo'] ?? null),
            Data::get('barbers')
        );
    }

    /** Barbero activo por slug o null. Solo con base de datos. */
    public static function find(string $slug): ?self
    {
        $r = Database::selectOne(
            'SELECT id, slug, name, role, years, rating, tone, photo FROM barbers WHERE slug = ? AND active = 1', [$slug]
        );
        return $r ? self::fromRow($r) : null;
    }

    /** True si tiene la valoración máxima (5,0): su tarjeta se destaca con el fondo de su color. */
    public function isTopRated(): bool
    {
        return (float) str_replace(',', '.', $this->rating) >= 5.0;
    }

    /** Nombre de pila: "Nico Ledesma" → "Nico". */
    public function firstName(): string
    {
        return explode(' ', $this->name)[0];
    }
}
