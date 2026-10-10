<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use App\Models\Barber;
use App\Models\User;
use App\Services\Auth;
use App\Support\Password;

/** Gestión de usuarios desde /admin/usuarios: validación, roles, secciones del panel del barbero y baja. */
final class UserAdminTest extends DbTestCase
{
    /** Formulario de alta válido (rol cliente). */
    private function form(array $over = []): array
    {
        return $over + [
            'dni' => '99.700.001', 'full_name' => '  Juana   Pérez ', 'email' => ' Juana@Test.Local ',
            'role' => 'cliente', 'barber' => '', 'sections' => [], 'password' => 'clave-de-prueba',
        ];
    }

    public function testValidaAltaYNormalizaDatos(): void
    {
        [$data, $error] = User::validate($this->form(), true);
        $this->assertNull($error);
        $this->assertSame('99700001', $data['dni']);
        $this->assertSame('Juana Pérez', $data['full_name']);
        $this->assertSame('Juana', $data['name']);
        $this->assertSame('juana@test.local', $data['email']);
        $this->assertSame(0, $data['is_admin']);
        $this->assertNull($data['barber_id']);
        $this->assertNull($data['panel_access']);

        $this->assertNull(User::validate($this->form(['email' => '']), true)[0]['email'], 'el email es opcional');
        $this->assertNotNull(User::validate($this->form(['dni' => '12']), true)[1]);
        $this->assertNotNull(User::validate($this->form(['full_name' => 'Juana']), true)[1], 'falta el apellido');
        $this->assertNotNull(User::validate($this->form(['email' => 'no-es-mail']), true)[1]);
        $this->assertNotNull(User::validate($this->form(['role' => 'superadmin']), true)[1]);
        $this->assertNotNull(User::validate($this->form(['password' => 'corta']), true)[1]);
        $this->assertNotNull(User::validate($this->form(['full_name' => ['a', 'b']]), true)[1], 'arreglo en vez de texto');
        $this->assertNull(User::validate($this->form(['dni' => '', 'password' => '']), false)[1], 'al editar no se piden DNI ni contraseña');
    }

    public function testBarberoNecesitaFichaYSoloGuardaSeccionesValidas(): void
    {
        $barber = Barber::all()[0];
        $this->assertNotNull(User::validate($this->form(['role' => 'barbero']), true)[1], 'sin ficha del equipo');

        [$data, $error] = User::validate($this->form([
            'role' => 'barbero', 'barber' => $barber->id, 'sections' => ['fila', 'usuarios', 'turnos', 'inventada', ['x']],
        ]), true);
        $this->assertNull($error);
        $this->assertSame($barber->key, $data['barber_id']);
        $this->assertSame('turnos,fila', $data['panel_access'], 'en el orden de las pestañas, sin "usuarios" ni desconocidas');

        // Con otro rol las secciones se ignoran aunque vengan en el formulario.
        $this->assertNull(User::validate($this->form(['role' => 'admin', 'sections' => ['fila']]), true)[0]['panel_access']);
    }

    public function testPermisosDelPanelSegunRol(): void
    {
        $barber = Barber::all()[0];
        $this->track('99700002');
        [$data] = User::validate($this->form(['dni' => '99700002', 'email' => '', 'role' => 'barbero', 'barber' => $barber->id, 'sections' => ['turnos', 'fila']]), true);
        $u = User::findById(User::createFromAdmin($data, Password::hash($data['password'])));

        $this->assertSame('barbero', $u->role());
        $this->assertTrue($u->canSee('turnos'));
        $this->assertFalse($u->canSee('finanzas'));
        $this->assertFalse($u->canSee('usuarios'));
        $this->assertSame(['semana', 'turnos', 'fila'], $u->panelSections(), '"Mi semana" siempre primero');
        $this->assertTrue($u->hasPanel());

        [$data] = User::validate($this->form(['email' => '', 'role' => 'barbero', 'barber' => $barber->id, 'sections' => []]), false);
        User::updateFromAdmin($u->id, $data);
        $this->assertSame(['semana'], User::findById($u->id)->panelSections(), 'sin secciones extra ve solo su semana');

        [$data] = User::validate($this->form(['email' => '', 'role' => 'admin']), false);
        User::updateFromAdmin($u->id, $data);
        $admin = User::findById($u->id);
        $this->assertTrue($admin->canSee('usuarios'));
        $this->assertFalse($admin->canSee('semana'), 'el admin no es barbero: no tiene "Mi semana"');
        $this->assertSame('usuarios', $admin->panelSections()[8]);

        [$data] = User::validate($this->form(['email' => '', 'role' => 'cliente']), false);
        User::updateFromAdmin($u->id, $data);
        $this->assertFalse(User::findById($u->id)->hasPanel());
        $this->assertFalse(User::findById($u->id)->canSee('semana'));
    }

    public function testMiSemanaCuentaSoloLosCortesAtendidosDelBarbero(): void
    {
        $barber = Barber::all()[0];
        $client = $this->makeUser('99700008');
        $service = \App\Core\Database::selectOne('SELECT id, price FROM services WHERE active = 1 ORDER BY id LIMIT 1');
        $monday = '2031-06-02'; // lunes lejano, sin datos de la semilla
        $insert = static function (string $start, string $status) use ($client, $barber, $service): void {
            \App\Core\Database::execute(
                'INSERT INTO appointments (user_id, barber_id, service_id, starts_at, ends_at, price, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$client->id, $barber->key, $service['id'], $start, date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')), $service['price'], $status]
            );
        };
        $insert('2031-06-02 10:00:00', 'done');    // lunes
        $insert('2031-06-02 11:00:00', 'done');    // lunes
        $insert('2031-06-04 10:00:00', 'done');    // miércoles
        $insert('2031-06-05 10:00:00', 'no_show'); // no cuenta
        $insert('2031-06-06 10:00:00', 'booked');  // no cuenta

        $week = \App\Models\Dashboard::week('2031-06-05');
        $this->assertSame($monday, $week['from']);
        $mine = array_values(array_filter($week['barbers'], static fn (array $b) => $b['id'] === $barber->key))[0];
        $this->assertSame([2, 0, 1, 0, 0, 0, 0], $mine['perDay']);
        $this->assertSame(3, $mine['total']);
        $this->assertSame(3 * (int) $service['price'], $mine['revenue']);
    }

    public function testDniOEmailRepetidosChocanConLosIndicesUnicos(): void
    {
        $this->makeUser('99700003', 'repetido@test.local');
        [$data] = User::validate($this->form(['dni' => '99700004', 'email' => 'repetido@test.local']), true);
        $this->track('99700004');
        try {
            User::createFromAdmin($data, Password::hash('clave-de-prueba'));
            $this->fail('debía chocar con el email repetido');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
    }

    public function testFichaDeBarberoEnUso(): void
    {
        // Una ficha que ninguna cuenta de la semilla use (la de Nico ya está vinculada).
        $free = array_values(array_filter(Barber::all(), static fn (Barber $b) => !User::barberTaken($b->key)));
        $this->assertNotEmpty($free, 'la semilla trae fichas sin cuenta');
        $barber = $free[0];

        $u = $this->makeUser('99700005');
        [$data] = User::validate($this->form(['email' => '', 'role' => 'barbero', 'barber' => $barber->id]), false);
        User::updateFromAdmin($u->id, $data);
        $this->assertFalse(User::barberTaken($barber->key, $u->id), 'su propia ficha no cuenta como ocupada');
        $this->assertTrue(User::barberTaken($barber->key), 'para cualquier otra cuenta sí');

        User::setActive($u->id, false);
        $this->assertFalse(User::barberTaken($barber->key), 'una cuenta dada de baja no la ocupa');
    }

    public function testSoloSeEliminanCuentasSinHistorial(): void
    {
        $fresh = $this->makeUser('99700007');
        $this->assertContains($fresh->id, User::deletableIds());
        $this->assertTrue(User::delete($fresh->id));
        $this->assertNull(User::findById($fresh->id));
        $this->assertFalse(User::delete($fresh->id), 'ya no existe');

        // Mateo (semilla) tiene turnos: no se puede borrar, solo suspender.
        $mateo = User::find('40123456');
        $this->assertNotContains($mateo->id, User::deletableIds());
        $this->assertFalse(User::delete($mateo->id));
        $this->assertNotNull(User::find('40123456'));
    }

    public function testLaSemillaTraeUnAdministradorActivo(): void
    {
        $this->assertGreaterThanOrEqual(1, User::activeAdminCount());
    }

    public function testCuentaDadaDeBajaNoEntraYPierdeLaSesion(): void
    {
        // Sesión real como en AuthTest (sin cookies: lo configura tests/bootstrap.php).
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Session::start();
        }
        $u = $this->makeUser('99700006');
        $this->assertNull(Auth::attempt('99700006', 'clave-de-prueba', '10.0.0.6'));
        $this->assertSame($u->id, Auth::user()?->id);

        User::setActive($u->id, false);
        $this->assertNull(Auth::user(), 'la sesión viva deja de valer');
        $this->assertStringStartsWith(Auth::ERROR_CREDENTIALS, (string) Auth::attempt('99700006', 'clave-de-prueba', '10.0.0.6'), 'mismo error que una contraseña mala');

        User::setActive($u->id, true);
        $this->assertNull(Auth::attempt('99700006', 'clave-de-prueba', '10.0.0.6'));
        $_SESSION = [];
    }
}
