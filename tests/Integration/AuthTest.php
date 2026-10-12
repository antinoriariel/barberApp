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
    protected function setUp(): void
    {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Session::start();
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_COOKIE[Device::COOKIE]);
        Device::reset();
        parent::tearDown();
    }

    public function testLoginCorrectoEIncorrectoConElMismoMensaje(): void
    {
        $this->makeUser('99000021');

        // Cada DNI arranca con 5 intentos; la IP (10) todavía no es la que limita.
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 4 intentos.', Auth::attempt('99000021', 'mala', '10.0.0.1'));
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 4 intentos.', Auth::attempt('99999999', 'clave-de-prueba', '10.0.0.1'), 'DNI inexistente: mismo mensaje');
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 7 intentos.', Auth::attempt('abc', 'clave-de-prueba', '10.0.0.1'), 'formato inválido: solo cuenta la IP');
        $this->assertNull(Session::get('dni'));

        $this->assertNull(Auth::attempt('99.000.021', 'clave-de-prueba', '10.0.0.1'));
        $this->assertSame('99000021', Session::get('dni'));
        $this->assertSame('99000021', Auth::user()?->dni);
    }

    public function testBloqueoPorIntentosFallidos(): void
    {
        $this->makeUser('99000022');
        for ($left = 4; $left >= 2; $left--) {
            $this->assertSame(Auth::ERROR_CREDENTIALS . " Te quedan $left intentos.", Auth::attempt('99000022', 'mala', '10.0.0.2'));
        }
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te queda 1 intento.', Auth::attempt('99000022', 'mala', '10.0.0.2'));
        // El quinto fallo ya avisa el bloqueo con el tiempo de espera (la ventana es de 15 minutos).
        $this->assertSame(Auth::THROTTLED_PREFIX . ' Podés volver a intentar en 15 minutos.', Auth::attempt('99000022', 'mala', '10.0.0.2'));
        // Bloqueado: ni siquiera la contraseña correcta entra, aunque cambie la IP.
        $error = Auth::attempt('99000022', 'clave-de-prueba', '10.0.0.3');
        $this->assertStringStartsWith(Auth::THROTTLED_PREFIX . ' Podés volver a intentar en ', $error);
        $this->assertGreaterThan(0, Auth::retryAfter());
    }

    public function testContraseniasRarasCuentanComoFalloSinRomper(): void
    {
        $this->makeUser('99000025');
        $ip = '10.0.0.8';
        // Vacía, con byte nulo (truco para cortar bcrypt) o gigante: nunca se verifican, pero suman intento.
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 4 intentos.', Auth::attempt('99000025', '', $ip));
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 3 intentos.', Auth::attempt('99000025', "clave-de-prueba\0x", $ip));
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 2 intentos.', Auth::attempt('99000025', str_repeat('a', 5000), $ip));
        // Un DNI con basura alrededor se normaliza, pero sin pasar de 32 caracteres.
        $this->assertStringStartsWith(Auth::ERROR_CREDENTIALS, Auth::attempt(str_repeat('9', 40), 'x', $ip));
        $this->assertNull(Auth::attempt('99000025', 'clave-de-prueba', $ip));
    }

    public function testLoginCorrectoNoGastaElCupoDeLaIp(): void
    {
        $this->makeUser('99000026');
        // 15 logins correctos seguidos desde la misma IP (el límite por IP es 10 fallos): ninguno cuenta.
        for ($i = 0; $i < 15; $i++) {
            $this->assertNull(Auth::attempt('99000026', 'clave-de-prueba', '10.0.0.9'));
        }
    }

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

    public function testRegistroValidaYRechazaDuplicados(): void
    {
        $this->track('99000023');
        $ip = '10.0.0.4';
        $this->browser();
        $this->assertNotNull(Auth::register('99000023', 'Ana', '3781 40-1440', 'clave-larga-1', 'clave-larga-1', $ip), 'falta apellido');
        $this->assertSame(Phone::ERROR, Auth::register('99000023', 'Ana Pérez', 'ana@test.local', 'clave-larga-1', 'clave-larga-1', $ip));
        $this->assertSame(Phone::ERROR, Auth::register('99000023', 'Ana Pérez', '+598 99 123 456', 'clave-larga-1', 'clave-larga-1', $ip), 'Uruguay: no');
        $this->assertNotNull(Auth::register('99000023', 'Ana Pérez', '3781 40-1440', 'corta', 'corta', $ip));
        $this->assertNull(Auth::register('99000023', 'Ana Pérez', '03781 15 40-1440', 'clave-larga-1', 'clave-larga-1', $ip));
        $this->assertSame('99000023', Session::get('dni'));
        $user = User::find('99000023');
        $this->assertSame('Ana', $user->name);
        $this->assertSame('3781401440', $user->phone, 'se guarda normalizado');
        $this->assertNotSame('clave-larga-1', User::passwordHash('99000023'), 'la contraseña se guarda hasheada');

        // Otro navegador: mismo DNI, o mismo celular escrito distinto → ya existe.
        $this->browser();
        $this->assertStringContainsString('Ya existe', (string) Auth::register('99000023', 'Otra Persona', '3794 11-0000', 'clave-larga-1', 'clave-larga-1', '10.0.0.5'));
        $this->track('99000027');
        $this->assertStringContainsString('Ya existe', (string) Auth::register('99000027', 'Otra Persona', '+54 9 3781 401440', 'clave-larga-1', 'clave-larga-1', '10.0.0.5'));
        $this->assertNull(User::find('99000027'), 'no quedó nada a medias');
    }

    public function testUnaSolaCuentaPorNavegador(): void
    {
        $this->track('99000031');
        $this->track('99000032');
        $device = $this->browser();
        $this->assertTrue(Auth::canRegisterHere());
        $this->assertNull(Auth::register('99000031', 'Primera Cuenta', '3794 22-0031', 'clave-larga-1', 'clave-larga-1', '10.0.0.10'));
        $this->assertFalse(Auth::canRegisterHere(), 'apenas se crea la cuenta, ese navegador ya no puede registrar otra');

        // Aunque cambie de IP, el mismo navegador no puede crear una segunda cuenta; el intento queda auditado.
        $this->assertSame(Auth::ERROR_DEVICE_USED, Auth::register('99000032', 'Segunda Cuenta', '3794 22-0032', 'clave-larga-1', 'clave-larga-1', '10.0.0.11'));
        $this->assertNull(User::find('99000032'));
        $this->assertNotNull(Database::selectOne("SELECT id FROM audit_log WHERE event = 'register_blocked_device' AND detail LIKE ?", ['DNI 99000032%']));

        // El alta queda registrada para el superusuario, con los datos y el navegador.
        $row = Database::selectOne('SELECT * FROM device_registrations WHERE dni = ?', ['99000031']);
        $this->assertSame($device, $row['device_hash']);
        $this->assertSame('3794220031', $row['phone']);
        $this->assertSame('10.0.0.10', $row['ip']);
        $this->assertSame(1, Registration::search(Registration::filters(['q' => '3794 22-0031']))['total'], 'se busca por teléfono');

        // Si el superusuario libera el navegador, puede crear otra; el alta liberada queda en la lista.
        $this->assertTrue(Registration::release((int) $row['id']));
        $this->assertFalse(Registration::release((int) $row['id']), 'ya estaba liberado');
        $this->assertTrue(Auth::canRegisterHere());
        $this->assertNull(Auth::register('99000032', 'Segunda Cuenta', '3794 22-0032', 'clave-larga-1', 'clave-larga-1', '10.0.0.11'));
        $this->assertFalse(Auth::canRegisterHere());
        $this->assertSame(2, (int) Database::selectOne('SELECT COUNT(*) AS n FROM device_registrations WHERE device_hash = ?', [$device])['n']);
    }

    public function testBorrarLaCuentaNoLiberaElNavegador(): void
    {
        $this->track('99000033');
        $this->browser();
        $this->assertNull(Auth::register('99000033', 'Cuenta Borrada', '3794 22-0033', 'clave-larga-1', 'clave-larga-1', '10.0.0.12'));
        User::purge(User::find('99000033')->id);
        $this->assertFalse(Auth::canRegisterHere(), 'borrar una cuenta spam no habilita otra desde el mismo navegador');
        $this->assertNull(Database::selectOne('SELECT user_id FROM device_registrations WHERE dni = ?', ['99000033'])['user_id']);
    }

    public function testSinCookieNoSeRegistra(): void
    {
        $this->track('99000034');
        unset($_COOKIE[Device::COOKIE]);
        Device::reset();
        $this->assertStringContainsString('cookies', (string) Auth::register('99000034', 'Sin Cookie', '3794 22-0034', 'clave-larga-1', 'clave-larga-1', '10.0.0.13'));
        $this->assertNull(User::find('99000034'));
    }

    public function testTopeDeAltasPorIp(): void
    {
        $max = (int) Config::get('security.register_max_ip_day');
        for ($i = 0; $i < $max; $i++) {
            $dni = '9900004' . $i;
            $this->track($dni);
            $this->browser(); // otro navegador (o la cookie borrada), misma conexión
            $this->assertNull(Auth::register($dni, 'Cuenta Ip', '3794 33-004' . $i, 'clave-larga-1', 'clave-larga-1', '10.0.0.14'));
        }
        $this->track('99000049');
        $this->browser();
        $error = Auth::register('99000049', 'Cuenta Ip', '3794 33-0049', 'clave-larga-1', 'clave-larga-1', '10.0.0.14');
        $this->assertStringContainsString('varias cuentas', (string) $error);
        $this->assertNull(User::find('99000049'));
        $this->assertNotNull(Database::selectOne("SELECT id FROM audit_log WHERE event = 'register_blocked_ip' AND detail = ?", ['DNI 99000049']));
    }

    public function testOlvideMiContrasenaAbreWhatsapp(): void
    {
        $url = Auth::supportWhatsappUrl();
        $this->assertStringStartsWith('https://wa.me/5493781401440?text=', $url);
        $this->assertSame('Olvidé mi contraseña de la app de barbería, solicito asistencia', rawurldecode(explode('?text=', $url)[1]));
    }
}
