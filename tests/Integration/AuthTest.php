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
        parent::tearDown();
    }

    public function testLoginCorrectoEIncorrectoConElMismoMensaje(): void
    {
        $this->makeUser('99000021');

        $this->assertSame(Auth::ERROR_CREDENTIALS, Auth::attempt('99000021', 'mala', '10.0.0.1'));
        $this->assertSame(Auth::ERROR_CREDENTIALS, Auth::attempt('99999999', 'clave-de-prueba', '10.0.0.1'), 'DNI inexistente: mismo mensaje');
        $this->assertSame(Auth::ERROR_CREDENTIALS, Auth::attempt('abc', 'clave-de-prueba', '10.0.0.1'), 'formato inválido: mismo mensaje');
        $this->assertNull(Session::get('dni'));

        $this->assertNull(Auth::attempt('99.000.021', 'clave-de-prueba', '10.0.0.1'));
        $this->assertSame('99000021', Session::get('dni'));
        $this->assertSame('99000021', Auth::user()?->dni);
    }

    public function testBloqueoPorIntentosFallidos(): void
    {
        $this->makeUser('99000022');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(Auth::ERROR_CREDENTIALS, Auth::attempt('99000022', 'mala', '10.0.0.2'));
        }
        // Tras 5 fallos el DNI queda bloqueado: ni siquiera la contraseña correcta entra.
        $this->assertSame(Auth::ERROR_THROTTLED, Auth::attempt('99000022', 'clave-de-prueba', '10.0.0.3'));
    }

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

        $this->assertSame(Auth::ERROR_CREDENTIALS, Auth::attempt('99000024', 'clave-de-prueba', '10.0.0.7'));
        $this->assertNull(Auth::attempt('99000024', 'clave-nueva-123', '10.0.0.7'));
        @unlink(BASE_PATH . '/storage/mail.log');
    }

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
