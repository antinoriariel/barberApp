<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Data;
use App\Core\Database;
use App\Support\Dni;
use App\Support\Password;

/** Cuenta de la barbería (cliente, barbero o administrador). Se identifica por DNI. */
final class User
{
    /**
     * Secciones del panel que el administrador puede habilitarle a un barbero (clave => etiqueta), en el orden de
     * las pestañas. "usuarios" no está a propósito: dar de alta cuentas y cambiar contraseñas es solo del administrador.
     */
    public const SECTIONS = [
        'resumen'   => 'Resumen',
        'turnos'    => 'Turnos',
        'fila'      => 'Fila',
        'barberos'  => 'Barberos',
        'finanzas'  => 'Finanzas',
        'ingresos'  => 'Ingreso extra',
        'egresos'   => 'Egresos',
        'servicios' => 'Servicios',
    ];

    /** Roles que se eligen en /admin/usuarios (clave => etiqueta). Son excluyentes. */
    public const ROLES = ['cliente' => 'Cliente', 'barbero' => 'Barbero', 'admin' => 'Administrador'];

    /** Columnas que leen find() y allForAdmin() (visits = turnos atendidos). */
    private const COLUMNS = "u.id, u.dni, u.name, u.full_name, u.email, u.is_admin, u.barber_id, u.active, u.panel_access,
                        (SELECT COUNT(*) FROM appointments a WHERE a.user_id = u.id AND a.status = 'done') AS visits";

    public function __construct(
        public readonly string $dni,
        public readonly string $name,      // nombre de pila
        public readonly string $fullName,
        public readonly string $email,
        public readonly int $visits,       // con base de datos: turnos en estado 'done'
        public readonly int $id = 0,       // clave primaria en la base (0 con driver files)
        public readonly bool $isAdmin = false, // puede entrar al panel /admin (solo con base de datos)
        public readonly ?int $barberId = null, // ficha del equipo (barbers.id) si la cuenta es de un barbero
        public readonly bool $active = true,   // false = dada de baja desde /admin/usuarios (no puede entrar)
        public readonly array $panelAccess = [], // secciones del panel que ve un barbero (claves de SECTIONS)
    ) {
    }

    /** Indica si la cuenta pertenece a un barbero del equipo. */
    public function isBarber(): bool
    {
        return $this->barberId !== null;
    }

    /** Rol de la cuenta como clave de ROLES: admin si tiene is_admin, barbero si está vinculada a una ficha, si no cliente. */
    public function role(): string
    {
        return $this->isAdmin ? 'admin' : ($this->isBarber() ? 'barbero' : 'cliente');
    }

    /**
     * ¿Puede ver esa sección del panel? El administrador ve todas (incluida "usuarios"); un barbero solo las que el
     * administrador le habilitó en panel_access; un cliente, ninguna.
     */
    public function canSee(string $section): bool
    {
        if ($this->isAdmin) {
            return true;
        }
        return $this->isBarber() && isset(self::SECTIONS[$section]) && in_array($section, $this->panelAccess, true);
    }

    /**
     * Secciones del panel visibles para la cuenta, en el orden de las pestañas (con "usuarios" al final si es admin).
     * @return string[]
     */
    public function panelSections(): array
    {
        $keys = array_keys(self::SECTIONS);
        if ($this->isAdmin) {
            $keys[] = 'usuarios';
        }
        return array_values(array_filter($keys, $this->canSee(...)));
    }

    /** ¿Entra al panel /admin? (administrador, o barbero con al menos una sección habilitada). */
    public function hasPanel(): bool
    {
        return $this->panelSections() !== [];
    }

    /** Convierte el texto de users.panel_access ("turnos,fila") en la lista de secciones válidas, sin repetir. */
    private static function parseAccess(?string $raw): array
    {
        $keys = array_map('trim', explode(',', (string) $raw));
        return array_values(array_unique(array_filter($keys, static fn (string $k) => isset(self::SECTIONS[$k]))));
    }

    /** Arma un User desde una fila de `users` leída con COLUMNS. */
    private static function fromRow(array $r): self
    {
        return new self($r['dni'], $r['name'], $r['full_name'], (string) $r['email'], (int) $r['visits'], (int) $r['id'],
            (bool) $r['is_admin'], $r['barber_id'] !== null ? (int) $r['barber_id'] : null,
            (bool) $r['active'], self::parseAccess($r['panel_access']));
    }

    /** Busca por DNI normalizado (solo dígitos); null si no existe. Devuelve también cuentas dadas de baja (ver $active). */
    public static function find(string $dni): ?self
    {
        if (Database::enabled()) {
            $r = Database::selectOne('SELECT ' . self::COLUMNS . ' FROM users u WHERE u.dni = ?', [$dni]);
            return $r ? self::fromRow($r) : null;
        }
        $row = Data::get('users')[$dni] ?? null;
        return $row ? new self($dni, $row['name'], $row['full'], $row['email'], $row['visits']) : null;
    }

    /** Busca por email (sin distinguir mayúsculas); null si no existe. Solo con base de datos. */
    public static function findByEmail(string $email): ?self
    {
        $r = Database::selectOne('SELECT dni FROM users WHERE email = ?', [mb_strtolower($email)]);
        return $r ? self::find($r['dni']) : null;
    }

    /** Busca por clave primaria; null si no existe. Solo con base de datos. */
    public static function findById(int $id): ?self
    {
        $r = Database::selectOne('SELECT dni FROM users WHERE id = ?', [$id]);
        return $r ? self::find($r['dni']) : null;
    }

    /**
     * Hash de la contraseña de un DNI, o null si no existe / no tiene (cuenta sin contraseña) / está dada de baja:
     * así una cuenta dada de baja falla el login con el mismo mensaje que una contraseña incorrecta.
     * Con driver files (maqueta) todas las cuentas de ejemplo usan la contraseña de demostración de config.
     */
    public static function passwordHash(string $dni): ?string
    {
        if (!Database::enabled()) {
            return isset(Data::get('users')[$dni]) ? Password::hash((string) Config::get('auth.demo_password')) : null;
        }
        return Database::selectOne('SELECT password_hash FROM users WHERE dni = ? AND active = 1', [$dni])['password_hash'] ?? null;
    }

    /**
     * Crea una cuenta nueva (el nombre de pila es la primera palabra del nombre completo).
     * @throws \PDOException con código 23000 si el DNI o el email ya existen
     */
    public static function create(string $dni, string $fullName, string $email, string $passwordHash): self
    {
        Database::execute(
            'INSERT INTO users (dni, name, full_name, email, password_hash) VALUES (?, ?, ?, ?, ?)',
            [$dni, explode(' ', $fullName)[0], $fullName, mb_strtolower($email), $passwordHash]
        );
        return self::find($dni);
    }

    /** Cambia el hash de contraseña de un usuario. */
    public static function setPassword(int $id, string $passwordHash): void
    {
        Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [$passwordHash, $id]);
    }

    /**
     * Todos los usuarios (la pantalla de login los usa como DNI de prueba; no exponer con datos reales).
     * @return self[]
     */
    public static function all(): array
    {
        $dnis = Database::enabled()
            ? array_column(Database::select('SELECT dni FROM users ORDER BY id'), 'dni')
            : array_map('strval', array_keys(Data::get('users')));
        return array_map(fn (string $dni) => self::find($dni), $dnis);
    }

    /**
     * Todas las cuentas para /admin/usuarios: primero las activas, el personal antes que los clientes y por nombre.
     * Solo con base de datos.
     * @return self[]
     */
    public static function allForAdmin(): array
    {
        return array_map(self::fromRow(...), Database::select(
            'SELECT ' . self::COLUMNS . ' FROM users u
              ORDER BY u.active DESC, (u.is_admin = 1 OR u.barber_id IS NOT NULL) DESC, u.full_name'
        ));
    }

    /**
     * Valida el formulario de /admin/usuarios. Campos: dni y password (solo en el alta, $isNew), full_name, email
     * (opcional, pero sin él la cuenta no puede recuperar su contraseña), role (clave de ROLES), barber (slug de la
     * ficha del equipo, obligatorio si el rol es barbero) y sections[] (secciones del panel que verá el barbero).
     *
     * Devuelve [datos normalizados, null] o [null, mensaje]. Los datos traen name (nombre de pila), full_name,
     * email (null si vacío), is_admin, barber_id, panel_access ("a,b" o null) y, en el alta, dni y password.
     * Los duplicados de DNI/email no se miran acá: los frenan los índices únicos al guardar.
     *
     * @return array{0: ?array, 1: ?string}
     */
    public static function validate(array $in, bool $isNew): array
    {
        $text = static fn (string $k): string => is_string($in[$k] ?? null) ? $in[$k] : '';
        $data = [];

        if ($isNew) {
            $data['dni'] = Dni::normalize(substr($text('dni'), 0, 32));
            if (!Dni::isValid($data['dni'])) {
                return [null, 'Ingresá un DNI de 7 u 8 números.'];
            }
        }
        $data['full_name'] = trim((string) preg_replace('/\s+/u', ' ', $text('full_name')));
        if (!preg_match("/^[\p{L}\p{M}'.-]+( [\p{L}\p{M}'.-]+)+$/u", $data['full_name']) || mb_strlen($data['full_name']) > 120) {
            return [null, 'Ingresá nombre y apellido.'];
        }
        $data['name'] = mb_substr(explode(' ', $data['full_name'])[0], 0, 60);

        $email = mb_strtolower(trim($text('email')));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            return [null, 'Ingresá un email válido (o dejalo vacío).'];
        }
        $data['email'] = $email !== '' ? $email : null;

        $role = $text('role');
        if (!isset(self::ROLES[$role])) {
            return [null, 'Elegí un rol.'];
        }
        $data['is_admin'] = $role === 'admin' ? 1 : 0;
        $data['barber_id'] = null;
        $data['panel_access'] = null;
        if ($role === 'barbero') {
            $barber = Barber::find($text('barber'));
            if ($barber === null) {
                return [null, 'Elegí la ficha del equipo de ese barbero.'];
            }
            $data['barber_id'] = $barber->key;
            $picked = is_array($in['sections'] ?? null) ? array_filter($in['sections'], 'is_string') : [];
            $sections = array_values(array_intersect(array_keys(self::SECTIONS), $picked));
            $data['panel_access'] = $sections ? implode(',', $sections) : null;
        }

        if ($isNew) {
            $password = $text('password');
            if ($error = Password::validate($password)) {
                return [null, $error];
            }
            $data['password'] = $password;
        }
        return [$data, null];
    }

    /**
     * Alta desde el panel con los datos de validate() (la contraseña llega ya hasheada aparte). Devuelve el id nuevo.
     * @throws \PDOException con código 23000 si el DNI o el email ya existen
     */
    public static function createFromAdmin(array $data, string $passwordHash): int
    {
        Database::execute(
            'INSERT INTO users (dni, name, full_name, email, password_hash, is_admin, barber_id, panel_access) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$data['dni'], $data['name'], $data['full_name'], $data['email'], $passwordHash, $data['is_admin'], $data['barber_id'], $data['panel_access']]
        );
        return Database::lastId();
    }

    /**
     * Edita nombre, email, rol y secciones del panel con los datos de validate() (el DNI no cambia: es con lo que se entra).
     * Devuelve false si la cuenta no existe.
     * @throws \PDOException con código 23000 si el email ya es de otra cuenta
     */
    public static function updateFromAdmin(int $id, array $data): bool
    {
        if (Database::selectOne('SELECT id FROM users WHERE id = ?', [$id]) === null) {
            return false;
        }
        Database::execute(
            'UPDATE users SET name = ?, full_name = ?, email = ?, is_admin = ?, barber_id = ?, panel_access = ? WHERE id = ?',
            [$data['name'], $data['full_name'], $data['email'], $data['is_admin'], $data['barber_id'], $data['panel_access'], $id]
        );
        return true;
    }

    /** Da de baja (false) o reactiva (true) una cuenta. La baja no borra nada: sus turnos y movimientos se conservan. */
    public static function setActive(int $id, bool $active): void
    {
        Database::execute('UPDATE users SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    /** Cantidad de administradores activos (el panel no deja quitarle el rol ni dar de baja al último). */
    public static function activeAdminCount(): int
    {
        return (int) Database::selectOne('SELECT COUNT(*) AS n FROM users WHERE is_admin = 1 AND active = 1')['n'];
    }

    /** ¿Otra cuenta activa (distinta de $exceptId) ya está vinculada a esa ficha de barbero? */
    public static function barberTaken(int $barberId, int $exceptId = 0): bool
    {
        return Database::selectOne('SELECT id FROM users WHERE barber_id = ? AND id <> ? AND active = 1', [$barberId, $exceptId]) !== null;
    }
}
