<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Session;
use App\Core\Config;
use App\Models\Registration;
use App\Models\User;
use App\Services\Auth;
use App\Services\Device;
use App\Support\Phone;

/** Login, límite de intentos, alta (celular argentino, una por navegador) y "Olvidé mi contraseña" contra la base de pruebas. */
final class AuthTest extends DbTestCase
{
    /**
     * Arranca cada prueba con una sesión PHP vacía y activa (Auth guarda el DNI y la marca de superusuario en
     * $_SESSION).
     */
    protected function setUp(): void
    {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Session::start();
        }
    }

    /**
     * Vacía la sesión para que el login de una prueba no se filtre a la siguiente y luego limpia usuarios, intentos y
     * auditoría (DbTestCase).
     */
    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_COOKIE[Device::COOKIE]);
        Device::reset();
        parent::tearDown();
    }

    /**
     * Contraseña mala, DNI inexistente y DNI con formato inválido devuelven el mismo mensaje (no se puede averiguar
     * qué cuentas existen); el formato inválido solo descuenta del cupo de la IP. El login correcto acepta el DNI con
     * puntos y deja la sesión iniciada.
     */
    public function testLoginCorrectoEIncorrectoConElMismoMensaje(): void
    {
        $this->makeUser('69990021');

        // Cada DNI arranca con 5 intentos; la IP (10) todavía no es la que limita.
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 4 intentos.', Auth::attempt('69990021', 'mala', '10.0.0.1'));
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 4 intentos.', Auth::attempt('99999999', 'clave-de-prueba', '10.0.0.1'), 'DNI inexistente: mismo mensaje');
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 7 intentos.', Auth::attempt('abc', 'clave-de-prueba', '10.0.0.1'), 'formato inválido: solo cuenta la IP');
        $this->assertNull(Session::get('dni'));

        $this->assertNull(Auth::attempt('69.990.021', 'clave-de-prueba', '10.0.0.1'));
        $this->assertSame('69990021', Session::get('dni'));
        $this->assertSame('69990021', Auth::user()?->dni);
    }

    /**
     * Cuenta regresiva de intentos por DNI (4, 3, 2, 1), bloqueo al quinto fallo con el tiempo de espera, y que
     * bloqueado no entre ni con la contraseña correcta desde otra IP (Auth::retryAfter() > 0 para el encabezado
     * Retry-After).
     */
    public function testBloqueoPorIntentosFallidos(): void
    {
        $this->makeUser('69990022');
        for ($left = 4; $left >= 2; $left--) {
            $this->assertSame(Auth::ERROR_CREDENTIALS . " Te quedan $left intentos.", Auth::attempt('69990022', 'mala', '10.0.0.2'));
        }
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te queda 1 intento.', Auth::attempt('69990022', 'mala', '10.0.0.2'));
        // El quinto fallo ya avisa el bloqueo con el tiempo de espera (la ventana es de 15 minutos).
        $this->assertSame(Auth::THROTTLED_PREFIX . ' Podés volver a intentar en 15 minutos.', Auth::attempt('69990022', 'mala', '10.0.0.2'));
        // Bloqueado: ni siquiera la contraseña correcta entra, aunque cambie la IP.
        $error = Auth::attempt('69990022', 'clave-de-prueba', '10.0.0.3');
        $this->assertStringStartsWith(Auth::THROTTLED_PREFIX . ' Podés volver a intentar en ', $error);
        $this->assertGreaterThan(0, Auth::retryAfter());
    }

    /**
     * Contraseña vacía, con byte nulo o de 5000 caracteres nunca se verifican pero suman intento; un DNI de 40
     * dígitos se recorta sin romper. Al final la contraseña correcta sigue entrando.
     */
    public function testContraseniasRarasCuentanComoFalloSinRomper(): void
    {
        $this->makeUser('69990025');
        $ip = '10.0.0.8';
        // Vacía, con byte nulo (truco para cortar bcrypt) o gigante: nunca se verifican, pero suman intento.
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 4 intentos.', Auth::attempt('69990025', '', $ip));
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 3 intentos.', Auth::attempt('69990025', "clave-de-prueba\0x", $ip));
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 2 intentos.', Auth::attempt('69990025', str_repeat('a', 5000), $ip));
        // Un DNI con basura alrededor se normaliza, pero sin pasar de 32 caracteres.
        $this->assertStringStartsWith(Auth::ERROR_CREDENTIALS, Auth::attempt(str_repeat('9', 40), 'x', $ip));
        $this->assertNull(Auth::attempt('69990025', 'clave-de-prueba', $ip));
    }

    /**
     * 15 logins correctos seguidos desde la misma IP (límite: 10 fallos) no la bloquean: un acierto devuelve la
     * reserva del intento.
     */
    public function testLoginCorrectoNoGastaElCupoDeLaIp(): void
    {
        $this->makeUser('69990026');
        // 15 logins correctos seguidos desde la misma IP (el límite por IP es 10 fallos): ninguno cuenta.
        for ($i = 0; $i < 15; $i++) {
            $this->assertNull(Auth::attempt('69990026', 'clave-de-prueba', '10.0.0.9'));
        }
    }

    /**
     * Rotar direcciones IPv6 dentro del mismo /64 no esquiva el límite por IP: al décimo fallo el bloqueo alcanza a
     * toda la red.
     */
    public function testIpv6SeAgrupaPorPrefijo64(): void
    {
        // Rotando direcciones dentro del mismo /64 no se esquiva el límite por IP (10 fallos).
        for ($i = 1; $i <= 9; $i++) {
            $this->assertStringStartsWith(Auth::ERROR_CREDENTIALS, Auth::attempt('abc', 'x', "2001:db8:1:2::$i"));
        }
        $this->assertStringStartsWith(Auth::THROTTLED_PREFIX, Auth::attempt('abc', 'x', '2001:db8:1:2::ffff'));
    }

    /** Simula un navegador que ya trae su cookie navaja_dev (como después de abrir /registro). */
    private function browser(): string
    {
        $_COOKIE[Device::COOKIE] = bin2hex(random_bytes(32));
        Device::reset();
        return Device::hash();
    }

    /**
     * Auth::register rechaza un DNI fuera del rango del alta, nombre sin apellido, celular que no es argentino y
     * contraseña corta; con datos buenos crea la cuenta (celular normalizado, contraseña hasheada) e inicia sesión.
     * Desde otro navegador, el mismo DNI o el mismo celular escrito distinto se rechazan sin dejar nada a medias.
     */
    public function testRegistroValidaYRechazaDuplicados(): void
    {
        $this->track('69990023');
        $ip = '10.0.0.4';
        $this->browser();
        // Fuera del rango 16.500.000–70.000.000 no se puede crear cuenta (otra IP: el alta admite 5 intentos por IP).
        foreach (['16499999', '70000001', '99000023', '1234567'] as $fuera) {
            $this->assertSame(Auth::dniRangeError(), Auth::register($fuera, 'Ana Pérez', '3781 40-1440', 'clave-larga-1', 'clave-larga-1', '10.0.0.6'));
        }
        $this->assertNotNull(Auth::register('69990023', 'Ana', '3781 40-1440', 'clave-larga-1', 'clave-larga-1', $ip), 'falta apellido');
        $this->assertSame(Phone::ERROR, Auth::register('69990023', 'Ana Pérez', 'ana@test.local', 'clave-larga-1', 'clave-larga-1', $ip));
        $this->assertSame(Phone::ERROR, Auth::register('69990023', 'Ana Pérez', '+598 99 123 456', 'clave-larga-1', 'clave-larga-1', $ip), 'Uruguay: no');
        $this->assertNotNull(Auth::register('69990023', 'Ana Pérez', '3781 40-1440', 'corta', 'corta', $ip));
        $this->assertNull(Auth::register('69990023', 'Ana Pérez', '03781 15 40-1440', 'clave-larga-1', 'clave-larga-1', $ip));
        $this->assertSame('69990023', Session::get('dni'));
        $user = User::find('69990023');
        $this->assertSame('Ana', $user->name);
        $this->assertSame('3781401440', $user->phone, 'se guarda normalizado');
        $this->assertNotSame('clave-larga-1', User::passwordHash('69990023'), 'la contraseña se guarda hasheada');

        // Otro navegador: mismo DNI, o mismo celular escrito distinto → ya existe.
        $this->browser();
        $this->assertStringContainsString('Ya existe', (string) Auth::register('69990023', 'Otra Persona', '3794 11-0000', 'clave-larga-1', 'clave-larga-1', '10.0.0.5'));
        $this->track('69990027');
        $this->assertStringContainsString('Ya existe', (string) Auth::register('69990027', 'Otra Persona', '+54 9 3781 401440', 'clave-larga-1', 'clave-larga-1', '10.0.0.5'));
        $this->assertNull(User::find('69990027'), 'no quedó nada a medias');
    }

    /**
     * Un navegador crea una sola cuenta aunque cambie de IP (el intento queda auditado y el alta en
     * device_registrations); si el superusuario lo libera (Registration::release) puede crear otra.
     */
    public function testUnaSolaCuentaPorNavegador(): void
    {
        $this->track('69990031');
        $this->track('69990032');
        $device = $this->browser();
        $this->assertTrue(Auth::canRegisterHere());
        $this->assertNull(Auth::register('69990031', 'Primera Cuenta', '3794 22-0031', 'clave-larga-1', 'clave-larga-1', '10.0.0.10'));
        $this->assertFalse(Auth::canRegisterHere(), 'apenas se crea la cuenta, ese navegador ya no puede registrar otra');

        // Aunque cambie de IP, el mismo navegador no puede crear una segunda cuenta; el intento queda auditado.
        $this->assertSame(Auth::ERROR_DEVICE_USED, Auth::register('69990032', 'Segunda Cuenta', '3794 22-0032', 'clave-larga-1', 'clave-larga-1', '10.0.0.11'));
        $this->assertNull(User::find('69990032'));
        $this->assertNotNull(Database::selectOne("SELECT id FROM audit_log WHERE event = 'register_blocked_device' AND detail LIKE ?", ['DNI 69990032%']));

        // El alta queda registrada para el superusuario, con los datos y el navegador.
        $row = Database::selectOne('SELECT * FROM device_registrations WHERE dni = ?', ['69990031']);
        $this->assertSame($device, $row['device_hash']);
        $this->assertSame('3794220031', $row['phone']);
        $this->assertSame('10.0.0.10', $row['ip']);
        $this->assertSame(1, Registration::search(Registration::filters(['q' => '3794 22-0031']))['total'], 'se busca por teléfono');

        // Si el superusuario libera el navegador, puede crear otra; el alta liberada queda en la lista.
        $this->assertTrue(Registration::release((int) $row['id']));
        $this->assertFalse(Registration::release((int) $row['id']), 'ya estaba liberado');
        $this->assertTrue(Auth::canRegisterHere());
        $this->assertNull(Auth::register('69990032', 'Segunda Cuenta', '3794 22-0032', 'clave-larga-1', 'clave-larga-1', '10.0.0.11'));
        $this->assertFalse(Auth::canRegisterHere());
        $this->assertSame(2, (int) Database::selectOne('SELECT COUNT(*) AS n FROM device_registrations WHERE device_hash = ?', [$device])['n']);
    }

    /** Borrar la cuenta (User::purge) deja el alta sin user_id pero el navegador sigue sin poder registrar otra. */
    public function testBorrarLaCuentaNoLiberaElNavegador(): void
    {
        $this->track('69990033');
        $this->browser();
        $this->assertNull(Auth::register('69990033', 'Cuenta Borrada', '3794 22-0033', 'clave-larga-1', 'clave-larga-1', '10.0.0.12'));
        User::purge(User::find('69990033')->id);
        $this->assertFalse(Auth::canRegisterHere(), 'borrar una cuenta spam no habilita otra desde el mismo navegador');
        $this->assertNull(Database::selectOne('SELECT user_id FROM device_registrations WHERE dni = ?', ['69990033'])['user_id']);
    }

    /** Sin la cookie navaja_dev (cookies bloqueadas o incógnito) no se crea la cuenta y el error lo explica. */
    public function testSinCookieNoSeRegistra(): void
    {
        $this->track('69990034');
        unset($_COOKIE[Device::COOKIE]);
        Device::reset();
        $this->assertStringContainsString('cookies', (string) Auth::register('69990034', 'Sin Cookie', '3794 22-0034', 'clave-larga-1', 'clave-larga-1', '10.0.0.13'));
        $this->assertNull(User::find('69990034'));
    }

    /**
     * Desde una misma IP se crean hasta security.register_max_ip_day cuentas por día (aun con navegadores distintos);
     * la siguiente se rechaza y queda auditada.
     */
    public function testTopeDeAltasPorIp(): void
    {
        $max = (int) Config::get('security.register_max_ip_day');
        for ($i = 0; $i < $max; $i++) {
            $dni = '6999004' . $i;
            $this->track($dni);
            $this->browser(); // otro navegador (o la cookie borrada), misma conexión
            $this->assertNull(Auth::register($dni, 'Cuenta Ip', '3794 33-004' . $i, 'clave-larga-1', 'clave-larga-1', '10.0.0.14'));
        }
        $this->track('69990049');
        $this->browser();
        $error = Auth::register('69990049', 'Cuenta Ip', '3794 33-0049', 'clave-larga-1', 'clave-larga-1', '10.0.0.14');
        $this->assertStringContainsString('varias cuentas', (string) $error);
        $this->assertNull(User::find('69990049'));
        $this->assertNotNull(Database::selectOne("SELECT id FROM audit_log WHERE event = 'register_blocked_ip' AND detail = ?", ['DNI 69990049']));
    }

    /**
     * Recuperar con DNI + celular del registro: el celular se acepta escrito de otra forma, cambia la contraseña,
     * cierra las otras sesiones de la cuenta y deja la sesión iniciada; un celular distinto o un DNI inexistente dan
     * el mismo mensaje, y las contraseñas que no coinciden no gastan intentos.
     */
    public function testRecuperarConDniYCelular(): void
    {
        $user = $this->makeUser('69990061', '3794330061');
        Database::execute("INSERT INTO sessions (id, user_id, data, updated_at) VALUES ('recover-test-sess', ?, '', NOW())", [$user->id]);

        $this->assertSame('Las contraseñas no coinciden.', Auth::recover('69990061', '3794330061', 'nueva-clave-1', 'otra-clave-1', '10.0.0.20'));
        $this->assertSame(Auth::ERROR_RECOVER . ' Te quedan 2 intentos.', Auth::recover('69990061', '3794330099', 'nueva-clave-1', 'nueva-clave-1', '10.0.0.20'));
        $this->assertSame(Auth::ERROR_RECOVER . ' Te quedan 2 intentos.', Auth::recover('99999998', '3794330061', 'nueva-clave-1', 'nueva-clave-1', '10.0.0.20'), 'DNI inexistente: mismo mensaje');
        $this->assertNull(Session::get('dni'));

        $this->assertNull(Auth::recover('69.990.061', '+54 9 379 433-0061', 'nueva-clave-1', 'nueva-clave-1', '10.0.0.20'));
        $this->assertSame('69990061', Session::get('dni'));
        $this->assertNull(Database::selectOne("SELECT id FROM sessions WHERE id = 'recover-test-sess'"), 'cierra las otras sesiones');
        $this->assertTrue(\App\Support\Password::verify('nueva-clave-1', (string) User::passwordHash('69990061')));
        $this->assertNotNull(Database::selectOne("SELECT id FROM audit_log WHERE event = 'password_reset' AND user_id = ?", [$user->id]));
    }

    /** Sin celular cargado o con la cuenta suspendida no se recupera; al tercer fallo por DNI queda bloqueado. */
    public function testRecuperarRechazaCuentasSinCelularOSuspendidasYBloquea(): void
    {
        $this->makeUser('69990062');
        $suspended = $this->makeUser('69990063', '3794330063');
        User::setActive($suspended->id, false);

        $this->assertStringStartsWith(Auth::ERROR_RECOVER, (string) Auth::recover('69990062', '3794330062', 'nueva-clave-1', 'nueva-clave-1', '10.0.0.21'));
        $this->assertStringStartsWith(Auth::ERROR_RECOVER, (string) Auth::recover('69990063', '3794330063', 'nueva-clave-1', 'nueva-clave-1', '10.0.0.22'));

        // El intento con la cuenta suspendida ya contó para ese DNI (3 por DNI).
        $this->assertSame(Auth::ERROR_RECOVER . ' Te queda 1 intento.', Auth::recover('69990063', '3794330000', 'nueva-clave-1', 'nueva-clave-1', '10.0.0.23'));
        $this->assertStringStartsWith(Auth::THROTTLED_PREFIX, (string) Auth::recover('69990063', '3794330001', 'nueva-clave-1', 'nueva-clave-1', '10.0.0.23'));
        $this->assertStringStartsWith(Auth::THROTTLED_PREFIX, (string) Auth::recover('69990063', '3794330063', 'nueva-clave-1', 'nueva-clave-1', '10.0.0.24'));
    }

    /** "Olvidé mi contraseña" arma el enlace wa.me al soporte con el mensaje ya escrito. */
    public function testOlvideMiContrasenaAbreWhatsapp(): void
    {
        $url = Auth::supportWhatsappUrl();
        $this->assertStringStartsWith('https://wa.me/5493781401440?text=', $url);
        $this->assertSame('Olvidé mi contraseña de la app de barbería, solicito asistencia', rawurldecode(explode('?text=', $url)[1]));
    }
}
