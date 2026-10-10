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

    /**
     * Valida los datos de una ficha nueva (panel de administración). Devuelve [datos normalizados, null]
     * o [[], mensaje de error]. Límites: nombre 2–80, especialidad hasta 80, años 0–60, valoración 1,0–5,0
     * (acepta coma o punto) y color "#RRGGBB" (lo que manda <input type="color">).
     * @return array{0: array, 1: ?string}
     */
    public static function validate(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $role = trim((string) ($in['role'] ?? ''));
        $years = filter_var($in['years'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 60]]);
        $rating = filter_var(str_replace(',', '.', (string) ($in['rating'] ?? '')), FILTER_VALIDATE_FLOAT);
        $tone = strtoupper(trim((string) ($in['tone'] ?? '')));

        $error = match (true) {
            mb_strlen($name) < 2 || mb_strlen($name) > 80 => 'El nombre tiene que tener entre 2 y 80 caracteres.',
            $role === '' || mb_strlen($role) > 80         => 'Escribí la especialidad (hasta 80 caracteres).',
            $years === false                               => 'Los años de oficio van de 0 a 60.',
            $rating === false || $rating < 1 || $rating > 5 => 'La valoración va de 1,0 a 5,0.',
            !preg_match('/^#[0-9A-F]{6}$/', $tone)          => 'Elegí un color válido.',
            default                                        => null,
        };
        if ($error !== null) {
            return [[], $error];
        }
        return [['name' => $name, 'role' => $role, 'years' => $years, 'rating' => round($rating, 1), 'tone' => $tone], null];
    }

    /**
     * Da de alta un barbero (ya validado con validate) y devuelve su slug. El slug sale del nombre y, si ya
     * existe —aunque sea de un barbero inactivo—, se le agrega "-2", "-3"… La foto se asigna aparte (setPhoto).
     * Sin filas en barber_hours atiende la jornada completa de los días que abre el local.
     */
    public static function create(array $d): string
    {
        $base = slugify($d['name'], 'barbero');
        $slug = $base;
        for ($n = 2; Database::selectOne('SELECT id FROM barbers WHERE slug = ?', [$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        Database::execute(
            'INSERT INTO barbers (slug, name, role, years, rating, tone) VALUES (?, ?, ?, ?, ?, ?)',
            [$slug, $d['name'], $d['role'], $d['years'], $d['rating'], $d['tone']]
        );
        return $slug;
    }

    /** Cambia la imagen del barbero (ruta pública /img/... o null para sacarla). */
    public static function setPhoto(int $key, ?string $photo): void
    {
        Database::execute('UPDATE barbers SET photo = ? WHERE id = ?', [$photo, $key]);
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
