<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Session;
use App\Models\AuditLog;
use App\Models\Ban;
use App\Models\Superuser;
use App\Models\User;
use App\Models\UserActivity;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\Booking;
use App\Services\Device;
use App\Support\Password;

/** Superusuario: login con "superuser", vigencia de su sesión, rastreo de navegadores, bloqueos y borrado completo. */
final class SuperuserTest extends DbTestCase
{
    /**
     * Sesión vacía y un superusuario recién configurado con una contraseña conocida (se reemplaza el que hubiera en
     * la base de pruebas).
     */
    protected function setUp(): void
    {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Session::start();
        }
        Database::execute('DELETE FROM superuser');
        Superuser::setPassword(Password::hash('clave-super-de-prueba'));
    }

    /**
     * Vacía la sesión, borra el superusuario y los bloqueos de IP (rango de documentación 203.0.113.0/24) y de
     * navegador creados por las pruebas.
     */
    protected function tearDown(): void
    {
        $_SESSION = [];
        Database::execute('DELETE FROM superuser');
        Database::execute("DELETE FROM ip_bans WHERE ip LIKE '203.0.113.%'");
        Database::execute('DELETE FROM device_bans WHERE device_hash = ?', [str_repeat('ab', 32)]);
        parent::tearDown();
    }

    /**
     * Escribir "superuser" (sin importar mayúsculas ni espacios) en el campo DNI verifica contra la clave del
     * superusuario con el mismo límite de intentos; al entrar no hay cuenta de usuario asociada a la sesión.
     */
    public function testEntraConSuperuserYNoEsUnaCuenta(): void
    {
        $this->assertSame(Auth::ERROR_CREDENTIALS . ' Te quedan 4 intentos.', Auth::attempt('superuser', 'mala', '10.0.1.1'));
        $this->assertFalse(Auth::isSuperuser());

        $this->assertNull(Auth::attempt('  SuperUser ', 'clave-super-de-prueba', '10.0.1.1'));
        $this->assertTrue(Auth::isSuperuser());
        $this->assertNull(Auth::user(), 'el superusuario no es una cuenta de la barbería');
        $this->assertNull(Session::get('dni'));
    }

    /** Si nunca se configuró la contraseña (tabla superuser vacía) el login con "superuser" falla siempre. */
    public function testSinConfigurarNoSePuedeEntrar(): void
    {
        Database::execute('DELETE FROM superuser');
        $this->assertNotNull(Auth::attempt('superuser', 'clave-super-de-prueba', '10.0.1.2'));
        $this->assertFalse(Auth::isSuperuser());
    }

    /**
     * Rotar la contraseña invalida las sesiones de superusuario abiertas (session_version) y pasar más de
     * security.superuser_idle sin actividad también.
     */
    public function testCambiarLaContrasenaCierraLaSesionYLaInactividadTambien(): void
    {
        $this->assertNull(Auth::attempt('superuser', 'clave-super-de-prueba', '10.0.1.3'));
        Superuser::setPassword(Password::hash('otra-clave-super'));
        $this->assertFalse(Auth::isSuperuser(), 'la sesión vieja ya no vale');

        $this->assertNull(Auth::attempt('superuser', 'otra-clave-super', '10.0.1.3'));
        Session::set('su_seen', time() - 3 * 60 * 60);
        $this->assertFalse(Auth::isSuperuser(), 'venció por inactividad');
    }

    /**
     * Un bloqueo de IP rechaza cualquier navegador desde esa IP; un Permaban rechaza ese navegador desde cualquier IP.
     * Desbloquear dos veces devuelve false y normalizeIp compacta IPv6 y descarta texto que no es IP.
     */
    public function testBloqueosPorIpYPorNavegador(): void
    {
        $device = str_repeat('ab', 32);
        $this->assertFalse(Ban::blocks('203.0.113.7', $device));

        Ban::banIp('203.0.113.7', 'prueba');
        $this->assertTrue(Ban::blocks('203.0.113.7', str_repeat('cd', 32)));
        $this->assertTrue(Ban::unbanIp('203.0.113.7'));
        $this->assertFalse(Ban::unbanIp('203.0.113.7'));

        Ban::banDevice($device, null, 'Mozilla/5.0 prueba');
        $this->assertTrue(Ban::blocks('203.0.113.8', $device), 'el Permaban sigue aunque cambie la IP');
        $this->assertArrayHasKey($device, Ban::deviceSet());
        $this->assertTrue(Ban::unbanDevice($device));
        $this->assertFalse(Ban::blocks('203.0.113.8', $device));

        $this->assertSame('2001:db8::1', Ban::normalizeIp(' 2001:DB8:0::1 '));
        $this->assertNull(Ban::normalizeIp('no-es-ip'));
    }

    /**
     * Device::touch registra IP y navegador de la cuenta, UserActivity::browser lo resume ("Chrome · Android") y
     * recentActions devuelve las tres últimas acciones con su user agent, de la más nueva a la más vieja.
     */
    public function testRegistraNavegadorYUltimasTresAcciones(): void
    {
        $u = $this->makeUser('99800001');
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36';
        Device::touch($u, '203.0.113.20');

        $devices = UserActivity::devicesByUser()[$u->id] ?? [];
        $this->assertCount(1, $devices);
        $this->assertSame('203.0.113.20', $devices[0]['ip']);
        $this->assertSame(Device::hash(), $devices[0]['device_hash']);
        $this->assertSame('Chrome · Android', UserActivity::browser($devices[0]['user_agent']));

        foreach (['login_ok', 'appointment_create', 'appointment_cancel', 'logout'] as $event) {
            Audit::log($event, $u->id);
        }
        $actions = UserActivity::recentActions(3)[$u->id];
        $this->assertSame(['logout', 'appointment_cancel', 'appointment_create'], array_column($actions, 'event'));
        $this->assertSame($_SERVER['HTTP_USER_AGENT'], $actions[0]['user_agent']);
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    /**
     * Un bloqueo temporal cuenta cada rechazo (hits), deja de bloquear y de listarse al vencer, y volver a bloquear sin
     * duración lo hace permanente y reinicia el contador.
     */
    public function testBloqueoTemporalVenceYCuentaLosRechazos(): void
    {
        $device = str_repeat('cd', 32);
        Ban::banIp('203.0.113.30', 'prueba', 3600);
        $this->assertTrue(Ban::blocks('203.0.113.30', $device));
        $this->assertTrue(Ban::blocks('203.0.113.30', $device));
        $row = Database::selectOne('SELECT hits, expires_at FROM ip_bans WHERE ip = ?', ['203.0.113.30']);
        $this->assertSame(2, (int) $row['hits'], 'cada rechazo se cuenta');
        $this->assertNotNull($row['expires_at']);

        Database::execute('UPDATE ip_bans SET expires_at = ? WHERE ip = ?', [date('Y-m-d H:i:s', time() - 1), '203.0.113.30']);
        $this->assertFalse(Ban::blocks('203.0.113.30', $device), 'vencido ya no bloquea');
        $this->assertNotContains('203.0.113.30', array_column(Ban::ips(), 'ip'), 'ni se lista');

        Ban::banIp('203.0.113.30', 'de nuevo');
        $row = Database::selectOne('SELECT hits, expires_at FROM ip_bans WHERE ip = ?', ['203.0.113.30']);
        $this->assertSame(0, (int) $row['hits'], 'volver a bloquear reinicia el contador');
        $this->assertNull($row['expires_at'], 'sin duración es permanente');
    }

    /**
     * AuditLog::filters descarta fechas y usuarios inválidos; search filtra por grupo, IP, usuario y texto (con % y _
     * literales) y actor() nombra al autor. suspiciousIps detecta la IP con muchos fallos sobre varios DNI y
     * targetedAccounts el DNI atacado, pero no un fallo suelto.
     */
    public function testRegistroFiltraYDetectaFuerzaBruta(): void
    {
        $u = $this->makeUser('99800003');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.40';
        foreach (['99800003', '99800003', '99800003', '11222333'] as $dni) {
            Audit::log('login_fail', null, $dni);
        }
        Audit::log('appointment_create', $u->id, 'turno de prueba');
        Audit::log('su_ip_ban', null, '203.0.113.99');
        unset($_SERVER['REMOTE_ADDR']);

        $f = AuditLog::filters(['grupo' => 'fallos', 'ip' => '203.0.113.40', 'desde' => 'no-es-fecha', 'usuario' => 'x']);
        $this->assertSame('', $f['desde'], 'fecha inválida se descarta');
        $this->assertSame(0, $f['usuario']);
        $this->assertSame(4, AuditLog::search($f)['total']);

        $mine = AuditLog::search(AuditLog::filters(['usuario' => (string) $u->id]));
        $this->assertSame(['appointment_create'], array_column($mine['rows'], 'event'));
        $this->assertSame('Prueba 99800003', AuditLog::actor($mine['rows'][0]));

        $su = AuditLog::search(AuditLog::filters(['grupo' => 'su', 'q' => '203.0.113.99']));
        $this->assertSame('Superusuario', AuditLog::actor($su['rows'][0]));
        $this->assertSame(0, AuditLog::search(AuditLog::filters(['q' => '100%_nada']))['total'], 'el % y el _ se buscan literales');

        $ip = array_values(array_filter(AuditLog::suspiciousIps(), static fn (array $r) => $r['ip'] === '203.0.113.40'))[0];
        $this->assertSame(4, (int) $ip['fails']);
        $this->assertSame(2, (int) $ip['dnis']);
        $target = array_values(array_filter(AuditLog::targetedAccounts(), static fn (array $r) => $r['dni'] === '99800003'))[0];
        $this->assertSame((int) $u->id, (int) $target['user_id']);
        $this->assertNotContains('11222333', array_column(AuditLog::targetedAccounts(), 'dni'), 'un solo fallo no es alerta');
    }

    /**
     * User::delete no puede con una cuenta que tiene turnos, historyCounts los cuenta y User::purge borra la cuenta
     * junto con sus turnos.
     */
    public function testPurgeBorraLaCuentaConSuHistorial(): void
    {
        $u = $this->makeUser('99800002');
        Booking::create($u, 'corte', 'nico', $this->futureOpenDay(), '10:00');
        $this->assertFalse(User::delete($u->id), 'con turnos el borrado común no puede');
        $this->assertSame(1, User::historyCounts()[$u->id] ?? 0);

        $this->assertTrue(User::purge($u->id));
        $this->assertNull(User::findById($u->id));
        $this->assertNull(Database::selectOne('SELECT 1 AS x FROM appointments WHERE user_id = ?', [$u->id]));
    }
}
