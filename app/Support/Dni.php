<?php
declare(strict_types=1);

namespace App\Support;

/** Utilidades para DNI argentinos. */
final class Dni
{
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

    /** "40123456" → "40.123.456". */
    public static function format(string $dni): string
    {
        return number_format((int) $dni, 0, ',', '.');
    }
}
