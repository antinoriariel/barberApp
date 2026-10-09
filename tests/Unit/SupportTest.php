<?php
declare(strict_types=1);

namespace Tests\Unit;

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
    public function testDniNormalizaYFormatea(): void
    {
        $this->assertSame('40123456', Dni::normalize('40.123.456'));
        $this->assertSame('40.123.456', Dni::format('40123456'));
    }

    #[DataProvider('dnis')]
    public function testDniValido(string $dni, bool $esperado): void
    {
        $this->assertSame($esperado, Dni::isValid($dni));
    }

    public static function dnis(): array
    {
        return [['40123456', true], ['1234567', true], ['123456', false], ['123456789', false], ['4012345a', false], ['', false]];
    }

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

    public function testScheduleIsDate(): void
    {
        $this->assertTrue(Schedule::isDate('2026-02-28'));
        $this->assertFalse(Schedule::isDate('2026-02-30'));
        $this->assertFalse(Schedule::isDate('26-02-28'));
        $this->assertFalse(Schedule::isDate("2026-02-28\n"));
    }
}
