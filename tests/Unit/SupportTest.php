<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Models\LoyaltyCard;
use App\Models\Schedule;
use App\Models\User;
use App\Support\Dni;
use App\Support\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pruebas sin base de datos de las utilidades puras: Dni, Password, LoyaltyCard y Schedule::isDate. */
final class SupportTest extends TestCase
{
    /**
     * Request::string devuelve "" ante un arreglo o un número (evita "Array"), corta a 1024 caracteres y usa el valor
     * por defecto si falta el campo.
     */
    public function testRequestStringIgnoraArreglosYCortaElLargo(): void
    {
        $_POST = ['password' => ['a', 'b', 'c'], 'dni' => str_repeat('1', 5000), 'n' => 5];
        $r = new Request();
        $this->assertSame('', $r->string('password'), 'password[]=… no se convierte en "Array"');
        $this->assertSame(1024, strlen($r->string('dni')));
        $this->assertSame('', $r->string('n'));
        $this->assertSame('x', $r->string('falta', 'x'));
        $_POST = [];
    }

    /** RateLimiter::ipBucket deja una IPv4 tal cual y agrupa las IPv6 por su prefijo /64. */
    public function testIpBucketAgrupaIpv6(): void
    {
        $this->assertSame('1.2.3.4', RateLimiter::ipBucket('1.2.3.4'));
        $this->assertSame('2001:db8:1:2::/64', RateLimiter::ipBucket('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame(RateLimiter::ipBucket('2001:db8:1:2::1'), RateLimiter::ipBucket('2001:db8:1:2::ffff'));
    }

    /** Password::validate rechaza una contraseña con byte nulo y acepta una normal. */
    public function testPasswordRechazaBytesNulos(): void
    {
        $this->assertNotNull(Password::validate("clave-larga\0-123"));
        $this->assertNull(Password::validate('clave-larga-123'));
    }

    /** Dni::normalize saca los puntos y Dni::format los agrega. */
    public function testDniNormalizaYFormatea(): void
    {
        $this->assertSame('40123456', Dni::normalize('40.123.456'));
        $this->assertSame('40.123.456', Dni::format('40123456'));
    }

    /**
     * Dni::isValid acepta 7 u 8 dígitos y rechaza más cortos, más largos, con letras o vacíos (casos en dnis()).
     */
    #[DataProvider('dnis')]
    public function testDniValido(string $dni, bool $esperado): void
    {
        $this->assertSame($esperado, Dni::isValid($dni));
    }

    /** Casos para testDniValido: [DNI, ¿es válido?]. */
    public static function dnis(): array
    {
        return [['40123456', true], ['1234567', true], ['123456', false], ['123456789', false], ['4012345a', false], ['', false]];
    }

    /**
     * Password::hash/verify funcionan ida y vuelta, un hash vacío nunca verifica y validate exige el largo mínimo y
     * máximo.
     */
    public function testPasswordHashVerificaYValida(): void
    {
        $hash = Password::hash('Clave-Larga-1');
        $this->assertTrue(Password::verify('Clave-Larga-1', $hash));
        $this->assertFalse(Password::verify('otra', $hash));
        $this->assertFalse(Password::verify('x', ''));
        $this->assertNotNull(Password::validate('corta'));
        $this->assertNull(Password::validate('suficiente-8'));
        $this->assertNotNull(Password::validate(str_repeat('a', Password::MAX + 1)));
    }

    /**
     * LoyaltyCard: visitas restantes, porcentaje, estado y meta cumplida a los 6 cortes pagos; cobrado el corte gratis
     * la tarjeta vuelve a cero y empieza otro ciclo.
     */
    public function testTarjetaDeFidelidad(): void
    {
        $user = new User('1', 'A', 'A B', 'a@b.c', 3);
        $card = LoyaltyCard::for($user);
        $this->assertSame(3, $card->visitsLeft());
        $this->assertSame(50, $card->percent());
        $this->assertFalse($card->isComplete());
        $this->assertSame('Te faltan 3 visitas', $card->status());

        $done = new LoyaltyCard(13, 7);
        $this->assertTrue($done->isComplete(), 'segunda tarjeta: 7 + 6 cortes pagos');
        $this->assertSame(100, $done->percent());
        $this->assertSame('Meta cumplida', $done->status());
        $this->assertTrue((new LoyaltyCard(6, 7))->isComplete(), 'a los 6 cortes pagos el 7° es gratis');
        $this->assertSame('Te falta 1 visita', (new LoyaltyCard(5, 7))->status());

        $reset = new LoyaltyCard(7, 7);
        $this->assertSame(0, $reset->stamps(), 'cobrado el corte gratis, la tarjeta vuelve a cero');
        $this->assertFalse($reset->isComplete());
        $this->assertSame(0, $reset->percent());
        $this->assertSame('Te faltan 6 visitas', $reset->status());
        $this->assertSame(1, (new LoyaltyCard(8, 7))->stamps());
    }

    /** Schedule::isDate acepta solo fechas reales en formato YYYY-MM-DD, sin año corto ni salto de línea al final. */
    public function testScheduleIsDate(): void
    {
        $this->assertTrue(Schedule::isDate('2026-02-28'));
        $this->assertFalse(Schedule::isDate('2026-02-30'));
        $this->assertFalse(Schedule::isDate('26-02-28'));
        $this->assertFalse(Schedule::isDate("2026-02-28\n"));
    }
}
