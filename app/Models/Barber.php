<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Data;
use App\Core\Database;

/** Barbero del equipo. */
final class Barber
{
    /**
     * Objeto de valor inmutable (todas las propiedades son readonly): lo arman fromRow() con una fila de `barbers`
     * o el modo files con data/barbers.php. No valida nada; los datos ya vienen validados del panel o de la semilla.
     */
    public function __construct(
        public readonly string $id,          // slug: lo que usan las vistas (data-book-barber)
        public readonly string $name,
        public readonly string $role,
        public readonly int $years,
        public readonly string $rating,
        public readonly string $tone, // color del avatar (se valida con css_color() al imprimirlo)
        public readonly int $key = 0,        // clave primaria en la base (0 con driver files)
        public readonly ?string $photo = null, // ruta pública de su imagen (/img/...) o null si no tiene
        public readonly bool $enabled = true,  // false = deshabilitado en /admin/barberos: no figura para los clientes
    ) {
    }

    /** Columnas que lee fromRow(). */
    private const COLUMNS = 'id, slug, name, role, years, rating, tone, photo, enabled';

    /** Arma un Barber desde una fila de `barbers` (la valoración 4.9 se muestra como "4,9"). */
    private static function fromRow(array $r): self
    {
        return new self($r['slug'], $r['name'], $r['role'], (int) $r['years'],
            number_format((float) $r['rating'], 1, ',', ''), $r['tone'], (int) $r['id'], $r['photo'] ?? null,
            (bool) $r['enabled']);
    }

    /**
     * El equipo que ven los clientes: activo y habilitado, en orden de alta (o en el orden de data/barbers.php).
     * Lo usan la app, la agenda y las reservas; los deshabilitados en /admin/barberos quedan afuera.
     * @return self[]
     */
    public static function all(): array
    {
        if (Database::enabled()) {
            return array_map(self::fromRow(...), Database::select(
                'SELECT ' . self::COLUMNS . ' FROM barbers WHERE active = 1 AND enabled = 1 ORDER BY id'
            ));
        }
        return array_map(
            fn (array $r) => new self($r['id'], $r['name'], $r['role'], $r['years'], $r['rating'], $r['tone'], photo: $r['photo'] ?? null),
            Data::get('barbers')
        );
    }

    /**
     * Todo el equipo activo, también los deshabilitados (para el panel: horarios, habilitar/deshabilitar y nombres de
     * las fichas de las cuentas). Con driver files es lo mismo que all().
     * @return self[]
     */
    public static function team(): array
    {
        if (!Database::enabled()) {
            return self::all();
        }
        return array_map(self::fromRow(...), Database::select(
            'SELECT ' . self::COLUMNS . ' FROM barbers WHERE active = 1 ORDER BY id'
        ));
    }

    /**
     * Barbero activo y habilitado por slug, o null. Con $withDisabled también devuelve los deshabilitados (el panel
     * les puede editar el horario). Solo con base de datos.
     */
    public static function find(string $slug, bool $withDisabled = false): ?self
    {
        $r = Database::selectOne(
            'SELECT ' . self::COLUMNS . ' FROM barbers WHERE slug = ? AND active = 1' . ($withDisabled ? '' : ' AND enabled = 1'),
            [$slug]
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

    /**
     * ¿Es una ficha recién creada desde /admin/usuarios (sin valoración todavía)? Las vistas muestran "Nuevo" en lugar
     * de estrellas y años inventados.
     */
    public function isNew(): bool
    {
        return (float) str_replace(',', '.', $this->rating) <= 0.0;
    }

    /** Colores de avatar para las fichas nuevas (se elige el primero que no use otro barbero activo). */
    private const TONES = ['#1E5AA8', '#2E7D32', '#6A1B9A', '#E07A10', '#00838F', '#AD1457', '#5D4037', '#455A64'];

    /**
     * Ficha del equipo para una cuenta que pasa a ser barbero (alta desde /admin/usuarios), con su nombre completo.
     * Si existe una ficha inactiva con ese mismo nombre que ninguna cuenta usa (la de alguien que dejó de ser barbero
     * y vuelve), se reactiva (y habilita) para conservar su historial de cortes y horario; si no, se crea una nueva: especialidad
     * "Barbero", 0 años y sin valoración (se muestra como "Nuevo"), color libre y slug único sacado del nombre.
     * Devuelve barbers.id. Solo con base de datos; pensado para usarse dentro de la transacción del alta.
     */
    public static function provision(string $fullName): int
    {
        $old = Database::selectOne(
            'SELECT b.id FROM barbers b
              WHERE b.active = 0 AND b.name = ? AND NOT EXISTS (SELECT 1 FROM users u WHERE u.barber_id = b.id)
              ORDER BY b.id DESC LIMIT 1', [$fullName]
        );
        if ($old !== null) {
            Database::execute('UPDATE barbers SET active = 1, enabled = 1 WHERE id = ?', [$old['id']]);
            return (int) $old['id'];
        }

        $used = array_map('strtoupper', array_column(Database::select('SELECT tone FROM barbers WHERE active = 1'), 'tone'));
        $free = array_values(array_diff(self::TONES, $used));
        $count = (int) Database::selectOne('SELECT COUNT(*) AS n FROM barbers')['n'];
        $tone = $free[0] ?? self::TONES[$count % count(self::TONES)];

        Database::execute(
            'INSERT INTO barbers (slug, name, role, years, rating, tone) VALUES (?, ?, ?, 0, 0, ?)',
            [self::uniqueSlug($fullName), mb_substr($fullName, 0, 80), 'Barbero', $tone]
        );
        return Database::lastId();
    }

    /**
     * Slug único (máx. 30) a partir del nombre: minúsculas, sin tildes, palabras unidas con guion ("José Pérez" →
     * "jose-perez"); si ya existe se agrega -2, -3…
     */
    private static function uniqueSlug(string $name): string
    {
        $ascii = strtr(mb_strtolower($name), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        $base = substr($base !== '' ? $base : 'barbero', 0, 24);
        $slug = $base;
        for ($n = 2; Database::selectOne('SELECT id FROM barbers WHERE slug = ?', [$slug]) !== null; $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /** Cambia el nombre de la ficha (cuando se edita el nombre de la cuenta del barbero). */
    public static function rename(int $key, string $fullName): void
    {
        Database::execute('UPDATE barbers SET name = ? WHERE id = ?', [mb_substr($fullName, 0, 80), $key]);
    }

    /**
     * Muestra (true) u oculta (false) la ficha en la app: oculta, no aparece en el equipo ni toma turnos nuevos.
     * Los turnos ya reservados con ella no se cancelan.
     */
    public static function setActive(int $key, bool $active): void
    {
        Database::execute('UPDATE barbers SET active = ? WHERE id = ?', [$active ? 1 : 0, $key]);
    }

    /**
     * Habilita (true) o deshabilita (false) al barbero desde /admin/barberos. Deshabilitado no figura en el equipo de
     * la app ni toma turnos nuevos; los ya reservados no se cancelan y sigue en el panel. No toca `active`.
     */
    public static function setEnabled(int $key, bool $enabled): void
    {
        Database::execute('UPDATE barbers SET enabled = ? WHERE id = ?', [$enabled ? 1 : 0, $key]);
    }

    /** Borra la ficha si nunca tuvo turnos (su horario se borra en cascada); si los tuvo, solo la oculta. */
    public static function retire(int $key): void
    {
        $used = Database::selectOne('SELECT 1 AS x FROM appointments WHERE barber_id = ? LIMIT 1', [$key]) !== null;
        if ($used) {
            self::setActive($key, false);
        } else {
            Database::execute('DELETE FROM barbers WHERE id = ?', [$key]);
        }
    }

    /** Turnos reservados a futuro con esa ficha (para avisar antes de ocultarla). */
    public static function upcomingCount(int $key): int
    {
        return (int) Database::selectOne(
            "SELECT COUNT(*) AS n FROM appointments WHERE barber_id = ? AND status = 'booked' AND starts_at >= ?",
            [$key, date('Y-m-d H:i:s')]
        )['n'];
    }
}
