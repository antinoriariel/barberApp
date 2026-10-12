<?php
declare(strict_types=1);

namespace App\Support;

/** Utilidades para DNI argentinos. */
final class Dni
{
    /** Menor DNI aceptado al registrarse: no hay DNI argentinos de personas vigentes por debajo. */
    public const MIN_REGISTRO = 16_500_000;

    /** Mayor DNI aceptado al registrarse: todavía no se emitieron DNI por encima. */
    public const MAX_REGISTRO = 70_000_000;

    /** Deja solo los dígitos: "40.123.456" → "40123456". */
    public static function normalize(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw);
    }

    /** Un DNI válido (solo formato, no verifica que exista) tiene 7 u 8 dígitos. */
    public static function isValid(string $dni): bool
    {
        return (bool) preg_match('/^\d{7,8}$/D', $dni);
    }

    /**
     * DNI aceptado para crear una cuenta: además del formato (isValid), tiene que estar entre MIN_REGISTRO y
     * MAX_REGISTRO, ambos incluidos. Solo se exige en el alta; el login sigue usando isValid para que las
     * cuentas que ya existen puedan entrar igual.
     */
    public static function isRegistrable(string $dni): bool
    {
        return self::isValid($dni) && (int) $dni >= self::MIN_REGISTRO && (int) $dni <= self::MAX_REGISTRO;
    }

    /** "40123456" → "40.123.456". */
    public static function format(string $dni): string
    {
        return number_format((int) $dni, 0, ',', '.');
    }
}
