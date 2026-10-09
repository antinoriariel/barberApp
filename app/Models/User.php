<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Data;
use App\Core\Database;
use App\Support\Password;

/** Cuenta de la barbería (cliente, barbero o administrador). Se identifica por DNI. */
final class User
{
    public function __construct(
        public readonly string $dni,
        public readonly string $name,      // nombre de pila
        public readonly string $fullName,
        public readonly string $email,
        public readonly int $visits,       // con base de datos: turnos en estado 'done'
        public readonly int $id = 0,       // clave primaria en la base (0 con driver files)
        public readonly bool $isAdmin = false, // puede entrar al panel /admin (solo con base de datos)
        public readonly ?int $barberId = null, // ficha del equipo (barbers.id) si la cuenta es de un barbero
    ) {
    }

    /** Indica si la cuenta pertenece a un barbero del equipo. */
    public function isBarber(): bool
    {
        return $this->barberId !== null;
    }

    /** Busca por DNI normalizado (solo dígitos); null si no existe. */
    public static function find(string $dni): ?self
    {
        if (Database::enabled()) {
            $r = Database::selectOne(
                "SELECT u.id, u.dni, u.name, u.full_name, u.email, u.is_admin, u.barber_id,
                        (SELECT COUNT(*) FROM appointments a WHERE a.user_id = u.id AND a.status = 'done') AS visits
                   FROM users u WHERE u.dni = ?", [$dni]
            );
            return $r ? new self($r['dni'], $r['name'], $r['full_name'], (string) $r['email'], (int) $r['visits'], (int) $r['id'], (bool) $r['is_admin'], $r['barber_id'] !== null ? (int) $r['barber_id'] : null) : null;
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
     * Hash de la contraseña de un DNI, o null si no existe / no tiene (cuenta sin contraseña).
     * Con driver files (maqueta) todas las cuentas de ejemplo usan la contraseña de demostración de config.
     */
    public static function passwordHash(string $dni): ?string
    {
        if (!Database::enabled()) {
            return isset(Data::get('users')[$dni]) ? Password::hash((string) Config::get('auth.demo_password')) : null;
        }
        return Database::selectOne('SELECT password_hash FROM users WHERE dni = ?', [$dni])['password_hash'] ?? null;
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
}
