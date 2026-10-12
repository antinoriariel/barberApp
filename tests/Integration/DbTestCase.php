<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Models\Schedule;
use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Base de las pruebas de integración (base navaja_test). Crea usuarios descartables con DNI 99xxxxxx y
 * borra sus turnos, sesiones y auditoría al terminar, sin tocar los datos de la semilla.
 */
abstract class DbTestCase extends TestCase
{
    /** @var string[] DNI de los usuarios creados en el test */
    private array $dnis = [];

    /**
     * Borra los usuarios de prueba anotados y todo lo que cuelga de ellos (turnos, números de fila, auditoría,
     * enlaces de recuperación, sesiones), además de los intentos de login y la auditoría anónima. Los datos de la
     * semilla no se tocan.
     */
    protected function tearDown(): void
    {
        foreach ($this->dnis as $dni) {
            $id = Database::selectOne('SELECT id FROM users WHERE dni = ?', [$dni])['id'] ?? null;
            if ($id) {
                foreach (['appointments', 'queue_tickets', 'audit_log', 'password_resets', 'sessions'] as $table) {
                    Database::execute("DELETE FROM {$table} WHERE user_id = ?", [$id]);
                }
                Database::execute('DELETE FROM users WHERE id = ?', [$id]);
            }
        }
        Database::execute('DELETE FROM login_attempts');
        Database::execute('DELETE FROM audit_log WHERE user_id IS NULL');
        $this->dnis = [];
    }

    /** Crea un usuario de prueba (contraseña "clave-de-prueba") y lo marca para limpieza. */
    protected function makeUser(string $dni, string $email = ''): User
    {
        $this->dnis[] = $dni;
        return User::create($dni, 'Prueba ' . $dni, $email ?: "t{$dni}@test.local", \App\Support\Password::hash('clave-de-prueba'));
    }

    /** Un día abierto futuro que no sea hoy (para que ningún horario esté vencido). */
    protected function futureOpenDay(int $offset = 0): string
    {
        $days = array_values(array_filter(Schedule::openDays(10), fn (array $d) => $d['iso'] > date('Y-m-d')));
        return $days[$offset]['iso'];
    }

    /** Anota un DNI para que tearDown lo limpie (usuarios creados por Auth::register, por ejemplo). */
    protected function track(string $dni): void
    {
        $this->dnis[] = $dni;
    }
}
