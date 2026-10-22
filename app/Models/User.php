<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Data;
use App\Core\Database;
use App\Support\Dni;
use App\Support\Password;
use App\Support\Phone;

/** Cuenta de la barbería (cliente, barbero o administrador). Se identifica por DNI. */
final class User
{
    /**
     * Secciones del panel que el administrador puede habilitarle a un barbero (clave => etiqueta), en el orden de
     * las pestañas. "usuarios" no está a propósito: dar de alta cuentas y cambiar contraseñas es solo del administrador.
     */
    public const SECTIONS = [
        'resumen'     => 'Resumen',
        'turnos'      => 'Turnos',
        'hora-muerta' => 'Hora muerta',
        'fila'        => 'Fila',
        'barberos'    => 'Barberos',
        'finanzas'    => 'Finanzas',
        'ingresos'    => 'Ingreso extra',
        'egresos'     => 'Egresos',
        'servicios'   => 'Servicios',
    ];

    /** Roles que se eligen en /admin/usuarios (clave => etiqueta). Son excluyentes. */
    public const ROLES = ['cliente' => 'Cliente', 'barbero' => 'Barbero', 'admin' => 'Administrador'];

    /** Columnas que leen find() y allForAdmin() (visits = turnos atendidos). */
    private const COLUMNS = "u.id, u.dni, u.name, u.full_name, u.phone, u.is_admin, u.barber_id, u.active, u.panel_access,
                        (SELECT COUNT(*) FROM appointments a WHERE a.user_id = u.id AND a.status = 'done') AS visits";

    /**
     * Objeto de valor inmutable de una cuenta (nunca lleva el hash de la contraseña: ese se lee aparte con
     * passwordHash() solo al verificar). Lo arman find()/allForAdmin() con COLUMNS o el modo files con data/users.php.
     * El rol se deduce de $isAdmin y $barberId; $panelAccess solo tiene sentido para barberos (ver canSee()).
     */
    public function __construct(
        public readonly string $dni,
        public readonly string $name,      // nombre de pila
        public readonly string $fullName,
        public readonly string $phone,     // celular argentino normalizado (10 dígitos, ver Support\Phone); '' si no tiene
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
     * Sección propia de cada barbero ("Mi semana": sus cortes de la semana). No se habilita a mano ni la ve el
     * administrador: la tiene toda cuenta vinculada a una ficha del equipo, y es donde entra al panel.
     */
    public const MY_WEEK = 'semana';

    /**
     * ¿Puede ver esa sección del panel? "semana" es solo de los barberos (ver MY_WEEK). Fuera de eso, el administrador
     * ve todas (incluida "usuarios"); un barbero solo las que el administrador le habilitó en panel_access; un cliente, ninguna.
     */
    public function canSee(string $section): bool
    {
        if ($section === self::MY_WEEK) {
            return $this->isBarber();
        }
        if ($this->isAdmin) {
            return true;
        }
        return $this->isBarber() && isset(self::SECTIONS[$section]) && in_array($section, $this->panelAccess, true);
    }

    /**
     * Secciones del panel visibles para la cuenta, en el orden de las pestañas: "semana" primero si es barbero (así es
     * donde aterriza), después las habilitadas y "usuarios" al final si es admin.
     * @return string[]
     */
    public function panelSections(): array
    {
        $keys = [self::MY_WEEK, ...array_keys(self::SECTIONS)];
        if ($this->isAdmin) {
            $keys[] = 'usuarios';
        }
        return array_values(array_filter($keys, $this->canSee(...)));
    }

    /** ¿Entra al panel /admin? (administrador, o barbero: siempre tiene al menos "Mi semana"). */
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
        return new self($r['dni'], $r['name'], $r['full_name'], (string) $r['phone'], (int) $r['visits'], (int) $r['id'],
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
        return $row ? new self($dni, $row['name'], $row['full'], $row['phone'] ?? '', $row['visits']) : null;
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
     * Crea una cuenta nueva (el nombre de pila es la primera palabra del nombre completo). $phone ya normalizado
     * (Phone::normalize) o null.
     * @throws \PDOException con código 23000 si el DNI o el teléfono ya existen
     */
    public static function create(string $dni, string $fullName, ?string $phone, string $passwordHash): self
    {
        Database::execute(
            'INSERT INTO users (dni, name, full_name, phone, password_hash) VALUES (?, ?, ?, ?, ?)',
            [$dni, explode(' ', $fullName)[0], $fullName, $phone, $passwordHash]
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
     * Valida el formulario de /admin/usuarios. Campos: dni y password (solo en el alta, $isNew), full_name, phone
     * (opcional; celular argentino, ver Support\Phone), role (clave de ROLES) y sections[]
     * (secciones extra del panel que verá el barbero). La ficha del equipo no se elige: la crea saveFromAdmin.
     *
     * Devuelve [datos normalizados, null] o [null, mensaje]. Los datos traen name (nombre de pila), full_name,
     * phone (10 dígitos o null si vacío), is_admin, is_barber, barber_id (null), panel_access ("a,b" o null) y, en el alta, dni y password.
     * Los duplicados de DNI/teléfono no se miran acá: los frenan los índices únicos al guardar.
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

        $rawPhone = trim($text('phone'));
        $data['phone'] = $rawPhone !== '' ? Phone::normalize($rawPhone) : null;
        if ($rawPhone !== '' && $data['phone'] === null) {
            return [null, Phone::ERROR . ' O dejalo vacío.'];
        }

        $role = $text('role');
        if (!isset(self::ROLES[$role])) {
            return [null, 'Elegí un rol.'];
        }
        $data['is_admin'] = $role === 'admin' ? 1 : 0;
        $data['is_barber'] = $role === 'barbero';
        $data['barber_id'] = null; // la ficha la resuelve saveFromAdmin (se crea con el nombre de la cuenta)
        $data['panel_access'] = null;
        if ($role === 'barbero') {
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
     * @throws \PDOException con código 23000 si el DNI o el teléfono ya existen
     */
    public static function createFromAdmin(array $data, string $passwordHash): int
    {
        Database::execute(
            'INSERT INTO users (dni, name, full_name, phone, password_hash, is_admin, barber_id, panel_access) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$data['dni'], $data['name'], $data['full_name'], $data['phone'], $passwordHash, $data['is_admin'], $data['barber_id'], $data['panel_access']]
        );
        return Database::lastId();
    }

    /**
     * Edita nombre, teléfono, rol y secciones del panel con los datos de validate() (el DNI no cambia: es con lo que se entra).
     * Devuelve false si la cuenta no existe.
     * @throws \PDOException con código 23000 si el teléfono ya es de otra cuenta
     */
    public static function updateFromAdmin(int $id, array $data): bool
    {
        if (Database::selectOne('SELECT id FROM users WHERE id = ?', [$id]) === null) {
            return false;
        }
        Database::execute(
            'UPDATE users SET name = ?, full_name = ?, phone = ?, is_admin = ?, barber_id = ?, panel_access = ? WHERE id = ?',
            [$data['name'], $data['full_name'], $data['phone'], $data['is_admin'], $data['barber_id'], $data['panel_access'], $id]
        );
        return true;
    }

    /**
     * Guarda el formulario de /admin/usuarios (datos de validate()) en una sola transacción, junto con la ficha del
     * equipo si el rol es barbero:
     *  - pasa a barbero (alta o cliente/admin que cambia de rol) → Barber::provision crea su ficha con el nombre completo
     *    (o reactiva la que tenía si vuelve);
     *  - ya era barbero → conserva su ficha y, si cambió el nombre de la cuenta, se lo copia (si no, respeta el que se
     *    le haya puesto en /admin/barberos);
     *  - deja de ser barbero → su ficha se oculta (no toma turnos nuevos; su historial de cortes queda).
     * $target es null en el alta (entonces $passwordHash es obligatorio). Devuelve el id de la cuenta.
     * @throws \PDOException con código 23000 si el DNI o el teléfono ya existen (no queda nada a medias: rollback)
     */
    public static function saveFromAdmin(?self $target, array $data, ?string $passwordHash = null): int
    {
        return (int) Database::transaction(function () use ($target, $data, $passwordHash) {
            if ($data['is_barber']) {
                $data['barber_id'] = $target?->barberId ?? Barber::provision($data['full_name']);
                if ($target?->barberId !== null && $target->fullName !== $data['full_name']) {
                    Barber::rename($target->barberId, $data['full_name']);
                } elseif ($target !== null && !$target->active) {
                    Barber::setActive($data['barber_id'], false); // cuenta suspendida: su ficha aparece recién con el alta
                }
            } elseif ($target?->barberId !== null) {
                Barber::setActive($target->barberId, false);
            }
            if ($target === null) {
                return self::createFromAdmin($data, (string) $passwordHash);
            }
            self::updateFromAdmin($target->id, $data);
            return $target->id;
        });
    }

    /** Da de baja (false) o reactiva (true) una cuenta. La baja no borra nada: sus turnos y movimientos se conservan. */
    public static function setActive(int $id, bool $active): void
    {
        Database::execute('UPDATE users SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    /** Condición SQL "la cuenta u no tiene historial": turnos, premios de juegos, números de la fila ni movimientos cargados. */
    private const NO_HISTORY = 'NOT EXISTS (SELECT 1 FROM appointments x WHERE x.user_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM game_rewards x WHERE x.user_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM queue_tickets x WHERE x.user_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM expenses x WHERE x.created_by = u.id)
        AND NOT EXISTS (SELECT 1 FROM incomes x WHERE x.created_by = u.id)';

    /**
     * Ids de las cuentas que se pueden eliminar del todo (sin historial, ver NO_HISTORY). Las demás solo se suspenden,
     * para no perder turnos, ingresos ni la fila.
     * @return int[]
     */
    public static function deletableIds(): array
    {
        return array_map('intval', array_column(Database::select('SELECT u.id FROM users u WHERE ' . self::NO_HISTORY), 'id'));
    }

    /**
     * Elimina una cuenta sin historial con sus sesiones y enlaces de recuperación (la auditoría se conserva).
     * Devuelve false si no existe o tiene historial; la comprobación y el borrado van en la misma sentencia,
     * así un turno reservado en el medio no queda huérfano (y la clave foránea lo frena igual).
     */
    public static function delete(int $id): bool
    {
        return (bool) Database::transaction(function () use ($id) {
            $deleted = Database::execute('DELETE u FROM users u WHERE u.id = ? AND ' . self::NO_HISTORY, [$id]);
            if ($deleted > 0) {
                Database::execute('DELETE FROM sessions WHERE user_id = ?', [$id]);
                Database::execute('DELETE FROM password_resets WHERE user_id = ?', [$id]);
            }
            return $deleted > 0;
        });
    }

    /**
     * Elimina una cuenta CON todo su historial (solo el superusuario): sus turnos (y sus servicios, por cascada),
     * premios de juegos y números de la fila se borran; los egresos e ingresos que cargó quedan sin autor
     * (created_by = NULL) para no alterar las finanzas. Sesiones, enlaces de recuperación y dispositivos se van con
     * la cuenta; la auditoría y los bloqueos se conservan. Todo en una transacción. Devuelve false si no existía.
     */
    public static function purge(int $id): bool
    {
        return (bool) Database::transaction(function () use ($id) {
            Database::execute('DELETE FROM appointments WHERE user_id = ?', [$id]);
            Database::execute('DELETE FROM game_rewards WHERE user_id = ?', [$id]);
            Database::execute('DELETE FROM queue_tickets WHERE user_id = ?', [$id]);
            Database::execute('UPDATE expenses SET created_by = NULL WHERE created_by = ?', [$id]);
            Database::execute('UPDATE incomes SET created_by = NULL WHERE created_by = ?', [$id]);
            Database::execute('DELETE FROM sessions WHERE user_id = ?', [$id]);
            Database::execute('DELETE FROM password_resets WHERE user_id = ?', [$id]);
            return Database::execute('DELETE FROM users WHERE id = ?', [$id]) > 0;
        });
    }

    /**
     * Cuántos registros arrastraría purge() (turnos, premios y números de la fila), para avisar antes de borrar.
     * @return array<int, int> id de cuenta => cantidad (solo las que tienen alguno)
     */
    public static function historyCounts(): array
    {
        $out = [];
        foreach (Database::select(
            'SELECT user_id, COUNT(*) AS n FROM (
                SELECT user_id FROM appointments UNION ALL SELECT user_id FROM game_rewards UNION ALL SELECT user_id FROM queue_tickets
             ) t GROUP BY user_id'
        ) as $r) {
            $out[(int) $r['user_id']] = (int) $r['n'];
        }
        return $out;
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
