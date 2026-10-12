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

    /**
     * User::validate normaliza DNI con puntos, espacios del nombre y mayúsculas del email; deriva el nombre de pila;
     * el email es opcional. Rechaza DNI corto, nombre sin apellido, email inválido, rol desconocido, contraseña corta
     * y arreglos; al editar no pide DNI ni contraseña.
     */
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

    /** @var int[] fichas de barbero creadas por el test (se borran después de las cuentas) */
    private array $barbers = [];

    /**
     * Junta las fichas de barbero creadas (las anotadas y las de las cuentas conocidas), borra primero las cuentas
     * (users.barber_id apunta a la ficha) y después las fichas.
     */
    protected function tearDown(): void
    {
        $ids = array_filter(array_map(static fn (string $dni) => User::find($dni)?->barberId, ['99700002', '99700005', '99700009']));
        $this->barbers = array_unique([...$this->barbers, ...$ids]);
        parent::tearDown(); // primero las cuentas (users.barber_id apunta a la ficha)
        foreach ($this->barbers as $id) {
            \App\Core\Database::execute('DELETE FROM barbers WHERE id = ?', [$id]);
        }
        $this->barbers = [];
    }

    /** Alta de barbero por el mismo camino que el panel (validate + saveFromAdmin). */
    private function createBarber(string $dni, string $fullName, array $sections = []): User
    {
        $this->track($dni);
        [$data, $error] = User::validate($this->form(['dni' => $dni, 'full_name' => $fullName, 'email' => '', 'role' => 'barbero', 'sections' => $sections]), true);
        $this->assertNull($error);
        $user = User::findById(User::saveFromAdmin(null, $data, Password::hash($data['password'])));
        $this->barbers[] = $user->barberId;
        return $user;
    }

    /** Edita una cuenta por el mismo camino que el panel. */
    private function edit(User $u, array $over): User
    {
        [$data, $error] = User::validate($this->form($over + ['email' => '', 'full_name' => $u->fullName]), false);
        $this->assertNull($error);
        return User::findById(User::saveFromAdmin($u, $data));
    }

    /**
     * Un barbero no elige ficha (la que venga en el formulario se ignora) y solo se guardan secciones válidas y
     * concedibles, en el orden de las pestañas; con otro rol las secciones se descartan.
     */
    public function testBarberoNoEligeFichaYSoloGuardaSeccionesValidas(): void
    {
        [$data, $error] = User::validate($this->form([
            'role' => 'barbero', 'barber' => 'nico', 'sections' => ['fila', 'usuarios', 'turnos', 'inventada', ['x']],
        ]), true);
        $this->assertNull($error, 'no hace falta elegir ficha');
        $this->assertTrue($data['is_barber']);
        $this->assertNull($data['barber_id'], 'una ficha existente que venga en el formulario se ignora');
        $this->assertSame('turnos,fila', $data['panel_access'], 'en el orden de las pestañas, sin "usuarios" ni desconocidas');

        // Con otro rol las secciones se ignoran aunque vengan en el formulario.
        $this->assertNull(User::validate($this->form(['role' => 'admin', 'sections' => ['fila']]), true)[0]['panel_access']);
    }

    /**
     * Dar de alta un barbero crea su ficha con su nombre, slug sin acentos, rol "Barbero", activa, como "Nuevo" y con
     * un color libre; un homónimo recibe otro slug; renombrar la cuenta renombra la ficha; cambiar de rol la oculta y
     * volver a barbero la reactiva.
     */
    public function testAltaDeBarberoCreaSuFichaConElNombre(): void
    {
        $before = count(Barber::all());
        $u = $this->createBarber('99700005', 'Tomás Ñúñez', ['turnos']);
        $profile = \App\Core\Database::selectOne('SELECT * FROM barbers WHERE id = ?', [$u->barberId]);
        $this->assertSame('Tomás Ñúñez', $profile['name']);
        $this->assertSame('tomas-nunez', $profile['slug']);
        $this->assertSame('Barbero', $profile['role']);
        $this->assertSame(1, (int) $profile['active']);
        $this->assertCount($before + 1, Barber::all(), 'aparece en el equipo de la app');
        $this->assertTrue(Barber::find('tomas-nunez')->isNew(), 'sin valoración: se muestra como "Nuevo"');
        $this->assertNotContains($profile['tone'], array_map(static fn (Barber $b) => $b->tone, array_filter(Barber::all(), static fn (Barber $b) => $b->key !== $u->barberId)), 'color libre');

        // Otro con el mismo nombre: slug distinto, ficha distinta.
        $twin = $this->createBarber('99700009', 'Tomás Ñúñez');
        $this->assertNotSame($u->barberId, $twin->barberId);
        $this->assertSame('tomas-nunez-2', Barber::find('tomas-nunez-2')?->id);

        // Renombrar la cuenta renombra la ficha.
        $u = $this->edit($u, ['full_name' => 'Tomás Núñez Paz', 'role' => 'barbero']);
        $this->assertSame('Tomás Núñez Paz', \App\Core\Database::selectOne('SELECT name FROM barbers WHERE id = ?', [$u->barberId])['name']);

        // Deja de ser barbero: la ficha se oculta (no se borra); vuelve a serlo: recupera la misma ficha.
        $profileId = $u->barberId;
        $u = $this->edit($u, ['role' => 'cliente']);
        $this->assertNull($u->barberId);
        $this->assertSame(0, (int) \App\Core\Database::selectOne('SELECT active FROM barbers WHERE id = ?', [$profileId])['active']);
        $u = $this->edit($u, ['role' => 'barbero']);
        $this->assertSame($profileId, $u->barberId, 'reactiva su ficha anterior');
        $this->assertSame(1, (int) \App\Core\Database::selectOne('SELECT active FROM barbers WHERE id = ?', [$profileId])['active']);
    }

    /**
     * Si el alta de un barbero choca con un DNI existente, la transacción se revierte y no queda una ficha creada sin
     * cuenta.
     */
    public function testAltaConDniRepetidoNoDejaFichaHuerfana(): void
    {
        $this->makeUser('99700003');
        $before = (int) \App\Core\Database::selectOne('SELECT COUNT(*) AS n FROM barbers')['n'];
        [$data] = User::validate($this->form(['dni' => '99700003', 'email' => '', 'role' => 'barbero']), true);
        try {
            User::saveFromAdmin(null, $data, Password::hash('clave-de-prueba'));
            $this->fail('debía chocar con el DNI repetido');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        $this->assertSame($before, (int) \App\Core\Database::selectOne('SELECT COUNT(*) AS n FROM barbers')['n'], 'rollback: no queda la ficha creada');
    }

    /** Barber::retire borra físicamente la ficha de una cuenta eliminada si no tiene turnos. */
    public function testRetirarFichaSinTurnosLaBorra(): void
    {
        $u = $this->createBarber('99700009', 'Ficha Descartable');
        $profileId = $u->barberId;
        $this->assertTrue(User::delete($u->id));
        Barber::retire($profileId);
        $this->assertNull(\App\Core\Database::selectOne('SELECT id FROM barbers WHERE id = ?', [$profileId]));
    }

    /**
     * Un barbero ve "Mi semana" más las secciones concedidas (nunca Usuarios); sin secciones ve solo su semana. Un
     * admin ve todo menos "Mi semana" y un cliente no tiene panel.
     */
    public function testPermisosDelPanelSegunRol(): void
    {
        $u = $this->createBarber('99700002', 'Juana Pérez', ['turnos', 'fila']);

        $this->assertSame('barbero', $u->role());
        $this->assertTrue($u->canSee('turnos'));
        $this->assertFalse($u->canSee('finanzas'));
        $this->assertFalse($u->canSee('usuarios'));
        $this->assertSame(['semana', 'turnos', 'fila'], $u->panelSections(), '"Mi semana" siempre primero');
        $this->assertTrue($u->hasPanel());

        $u = $this->edit($u, ['role' => 'barbero', 'sections' => []]);
        $this->assertSame(['semana'], $u->panelSections(), 'sin secciones extra ve solo su semana');

        $admin = $this->edit($u, ['role' => 'admin']);
        $this->assertTrue($admin->canSee('usuarios'));
        $this->assertFalse($admin->canSee('semana'), 'el admin no es barbero: no tiene "Mi semana"');
        $this->assertSame('usuarios', $admin->panelSections()[8]);

        $client = $this->edit($admin, ['role' => 'cliente']);
        $this->assertFalse($client->hasPanel());
        $this->assertFalse($client->canSee('semana'));
    }

    /**
     * Dashboard::week cuenta por día solo los turnos atendidos (done) del barbero; ausentes y reservados no suman, ni
     * al total ni a la recaudación.
     */
    public function testMiSemanaCuentaSoloLosCortesAtendidosDelBarbero(): void
    {
        $barber = Barber::all()[0];
        $client = $this->makeUser('99700008');
        $service = \App\Core\Database::selectOne('SELECT id, price FROM services WHERE active = 1 ORDER BY id LIMIT 1');
        $monday = '2031-06-02'; // lunes lejano, sin datos de la semilla
        /** Inserta directo en la base un turno de 30 minutos de $client con $barber en $start y estado $status. */
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

    /**
     * Un email repetido choca con el índice único de la base (SQLSTATE 23000) aunque la validación lo haya dejado
     * pasar.
     */
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

    /**
     * Solo las cuentas sin historial figuran como eliminables y se borran; una cuenta de la semilla con turnos no se
     * puede borrar.
     */
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

    /** La semilla deja al menos un administrador activo (el panel nunca queda sin nadie que lo gestione). */
    public function testLaSemillaTraeUnAdministradorActivo(): void
    {
        $this->assertGreaterThanOrEqual(1, User::activeAdminCount());
    }

    /**
     * Suspender una cuenta invalida su sesión abierta y su login falla con el mismo error que una contraseña mala; al
     * reactivarla vuelve a entrar.
     */
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
