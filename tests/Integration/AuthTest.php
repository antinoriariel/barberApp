<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Session;
use App\Models\User;
use App\Services\Auth;

/** Login, límite de intentos, alta y recuperación de contraseña contra la base de pruebas. */
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
        parent::tearDown();
    }

    /**
     * Contraseña mala, DNI inexistente y DNI con formato inválido devuelven el mismo mensaje (no se puede averiguar
     * qué cuentas existen); el formato inválido solo descuenta del cupo de la IP. El login correcto acepta el DNI con
     * puntos y deja la sesión iniciada.
     */
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

    /**
     * Cuenta regresiva de intentos por DNI (4, 3, 2, 1), bloqueo al quinto fallo con el tiempo de espera, y que
     * bloqueado no entre ni con la contraseña correcta desde otra IP (Auth::retryAfter() > 0 para el encabezado
     * Retry-After).
     */
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

    /**
     * Contraseña vacía, con byte nulo o de 5000 caracteres nunca se verifican pero suman intento; un DNI de 40
     * dígitos se recorta sin romper. Al final la contraseña correcta sigue entrando.
     */
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

    /**
     * 15 logins correctos seguidos desde la misma IP (límite: 10 fallos) no la bloquean: un acierto devuelve la
     * reserva del intento.
     */
    public function testLoginCorrectoNoGastaElCupoDeLaIp(): void
    {
        $this->makeUser('99000026');
        // 15 logins correctos seguidos desde la misma IP (el límite por IP es 10 fallos): ninguno cuenta.
        for ($i = 0; $i < 15; $i++) {
            $this->assertNull(Auth::attempt('99000026', 'clave-de-prueba', '10.0.0.9'));
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

    /**
     * Auth::register rechaza nombre sin apellido, email inválido, contraseña corta y confirmación distinta; con
     * datos buenos crea la cuenta e inicia sesión. Un segundo alta con el mismo DNI se rechaza, y la contraseña queda
     * guardada como hash.
     */
    public function testRegistroValidaYRechazaDuplicados(): void
    {
        $this->track('99000023');
        $ip = '10.0.0.4';
        $this->assertNotNull(Auth::register('99000023', 'Ana', 'ana23@test.local', 'clave-larga-1', 'clave-larga-1', $ip), 'falta apellido');
        $this->assertNotNull(Auth::register('99000023', 'Ana Pérez', 'no-es-email', 'clave-larga-1', 'clave-larga-1', $ip));
        $this->assertNotNull(Auth::register('99000023', 'Ana Pérez', 'ana23@test.local', 'corta', 'corta', $ip));
        $this->assertNotNull(Auth::register('99000023', 'Ana Pérez', 'ana23@test.local', 'clave-larga-1', 'distinta-1234', $ip));
        $this->assertNull(Auth::register('99000023', 'Ana Pérez', 'ana23@test.local', 'clave-larga-1', 'clave-larga-1', $ip));
        $this->assertSame('99000023', Session::get('dni'));

        $this->assertStringContainsString('Ya existe', (string) Auth::register('99000023', 'Otra Persona', 'otra23@test.local', 'clave-larga-1', 'clave-larga-1', '10.0.0.5'));
        $user = User::find('99000023');
        $this->assertSame('Ana', $user->name);
        $this->assertNotSame('clave-larga-1', User::passwordHash('99000023'), 'la contraseña se guarda hasheada');
    }

    /**
     * Flujo completo de /recuperar con MAIL_DRIVER=log: misma respuesta exista o no el email, el token viaja en el
     * enlace pero se guarda hasheado, la contraseña nueva se valida, el token es de un solo uso y después solo entra
     * la contraseña nueva.
     */
    public function testRecuperacionDeContrasena(): void
    {
        $user = $this->makeUser('99000024', 'r24@test.local');
        @unlink(BASE_PATH . '/storage/mail.log');

        $this->assertTrue(Auth::requestReset('r24@test.local', '10.0.0.6'));
        $this->assertTrue(Auth::requestReset('nadie@test.local', '10.0.0.6'), 'misma respuesta aunque no exista');
        preg_match('/token=([a-f0-9]{64})/', (string) file_get_contents(BASE_PATH . '/storage/mail.log'), $m);
        $token = $m[1] ?? $this->fail('El email de recuperación no trae el enlace.');

        $this->assertNotSame($token, Database::selectOne('SELECT token_hash FROM password_resets WHERE user_id = ?', [$user->id])['token_hash'], 'se guarda hasheado');
        $this->assertTrue(Auth::resetTokenValid($token));
        $this->assertNotNull(Auth::resetPassword($token, 'corta', 'corta'));
        $this->assertNull(Auth::resetPassword($token, 'clave-nueva-123', 'clave-nueva-123'));
        $this->assertFalse(Auth::resetTokenValid($token), 'el token es de un solo uso');
        $this->assertSame(Auth::ERROR_RESET_INVALID, Auth::resetPassword($token, 'otra-clave-123', 'otra-clave-123'));

        $this->assertStringStartsWith(Auth::ERROR_CREDENTIALS, Auth::attempt('99000024', 'clave-de-prueba', '10.0.0.7'));
        $this->assertNull(Auth::attempt('99000024', 'clave-nueva-123', '10.0.0.7'));
        @unlink(BASE_PATH . '/storage/mail.log');
    }

    /**
     * Un token vencido (expires_at en el pasado), uno con formato inválido y uno bien formado pero inexistente no son
     * válidos.
     */
    public function testTokenVencidoONoValido(): void
    {
        $user = $this->makeUser('99000025', 'r25@test.local');
        $token = bin2hex(random_bytes(32));
        Database::execute('INSERT INTO password_resets (token_hash, user_id, expires_at) VALUES (?, ?, ?)',
            [hash('sha256', $token), $user->id, date('Y-m-d H:i:s', time() - 60)]);
        $this->assertFalse(Auth::resetTokenValid($token), 'vencido');
        $this->assertFalse(Auth::resetTokenValid('no-es-un-token'));
        $this->assertFalse(Auth::resetTokenValid(str_repeat('0', 64)));
    }
}
