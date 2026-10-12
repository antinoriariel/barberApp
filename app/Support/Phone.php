<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Teléfonos argentinos. Se guardan como el número nacional de 10 dígitos (código de área sin 0 + número sin 15),
 * p. ej. "3781401440": así un mismo celular escrito de distintas formas ("+54 9 3781 40-1440", "03781 15 401440",
 * "3781-401440") queda igual y el índice único de users.phone impide que dos cuentas lo usen.
 *
 * Solo se aceptan números de Argentina: con prefijo internacional tiene que ser +54 (o 0054); los códigos de área
 * argentinos empiezan con 11 (AMBA) o con 2 / 3 (interior), así que cualquier otra cosa se rechaza.
 */
final class Phone
{
    /**
     * Códigos de área de 3 dígitos (el resto del interior usa 4 y el AMBA 11). Sirven para saber dónde termina el
     * código al quitar el "15" del celular y para mostrar el número agrupado.
     */
    private const AREA3 = ['220', '221', '223', '230', '236', '237', '249', '260', '261', '263', '264', '266', '280',
        '291', '294', '297', '298', '299', '341', '342', '343', '345', '348', '351', '353', '358', '362', '364', '370',
        '376', '379', '380', '381', '383', '385', '387', '388'];

    /** Mensaje de error de formato (el mismo en el registro, el panel y el JS). */
    public const ERROR = 'Ingresá un celular argentino con código de área (ej: 3781 40-1440).';

    /**
     * Lleva lo que escribió la persona al número nacional de 10 dígitos, o null si no es un teléfono argentino válido.
     *
     *  1. Solo admite dígitos, espacios, +, guiones, puntos y paréntesis (nada de letras).
     *  2. Prefijo internacional (+ o 00): tiene que ser 54; se quita junto con el 9 de celular si está.
     *     Sin "+" pero empezando con 54 y con largo de internacional (12 o 13 dígitos) se trata igual: ningún
     *     código de área argentino empieza con 5.
     *  3. Si no, se quita el 0 de larga distancia.
     *  4. Si quedan 12 dígitos es que vino con el 15 del celular: se quita solo si hay una única posición posible
     *     (después de 11, de un código de 3 dígitos conocido o de uno de 4); si es ambigua se rechaza.
     *  5. El resultado tiene que tener 10 dígitos, empezar con 11, 2 o 3, y el número (sin el código) no puede ser
     *     un mismo dígito repetido.
     */
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 30 || !preg_match('/^\+?[\d\s().-]+$/D', $raw)) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $raw);
        $international = str_starts_with($raw, '+') || str_starts_with($digits, '00');
        $digits = $international ? ltrim($digits, '0') : $digits;

        if ($international || (str_starts_with($digits, '54') && in_array(strlen($digits), [12, 13], true))) {
            if (!str_starts_with($digits, '54')) {
                return null; // otro país
            }
            $digits = substr($digits, 2);
            if (str_starts_with($digits, '9')) {
                $digits = substr($digits, 1);
            }
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 12) {
            $digits = self::stripFifteen($digits);
            if ($digits === null) {
                return null;
            }
        }
        if (!preg_match('/^(11[2-9]|[23]\d)\d{7,8}$/D', $digits) || strlen($digits) !== 10) {
            return null;
        }
        if (preg_match('/^(\d)\1+$/D', substr($digits, -7))) {
            return null;
        }
        return $digits;
    }

    /** ¿Es un teléfono argentino válido? (ver normalize). */
    public static function isValid(string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /**
     * Quita el "15" de un número de 12 dígitos (código + 15 + número). Solo si hay exactamente una posición donde
     * puede estar: después del 11, de un código de 3 dígitos conocido o de uno de 4 que empiece con 2 o 3.
     */
    private static function stripFifteen(string $digits): ?string
    {
        $found = [];
        foreach ([2, 3, 4] as $len) {
            if (substr($digits, $len, 2) !== '15') {
                continue;
            }
            $area = substr($digits, 0, $len);
            $plausible = match ($len) {
                2 => $area === '11',
                3 => in_array($area, self::AREA3, true),
                4 => (bool) preg_match('/^[23]/', $area),
            };
            if ($plausible) {
                $found[] = $len;
            }
        }
        return count($found) === 1 ? substr($digits, 0, $found[0]) . substr($digits, $found[0] + 2) : null;
    }

    /** Largo del código de área de un número nacional: 2 (11), 3 (si es uno de AREA3) o 4. */
    private static function areaLength(string $national): int
    {
        if (str_starts_with($national, '11')) {
            return 2;
        }
        return in_array(substr($national, 0, 3), self::AREA3, true) ? 3 : 4;
    }

    /**
     * Para mostrar: "3781401440" → "+54 9 3781 40-1440", "1123456789" → "+54 9 11 2345-6789". Un valor que no es un
     * número nacional de 10 dígitos se devuelve tal cual.
     */
    public static function format(string $national): string
    {
        if (!preg_match('/^\d{10}$/D', $national)) {
            return $national;
        }
        $len = self::areaLength($national);
        $number = substr($national, $len);
        return '+54 9 ' . substr($national, 0, $len) . ' ' . substr($number, 0, -4) . '-' . substr($number, -4);
    }

    /** Número para WhatsApp (wa.me): internacional sin "+", con el 9 de celular. "3781401440" → "5493781401440". */
    public static function whatsapp(string $national): string
    {
        return '549' . $national;
    }
}
