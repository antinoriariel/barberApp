<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/** Hash y reglas de contraseñas (Argon2id si PHP lo trae; si no, el algoritmo por defecto). */
final class Password
{
    /** Máximo en caracteres: evita contraseñas gigantes que hagan lento el hash (DoS) y respeta el tope de bcrypt. */
    public const MAX = 72;

    /** Algoritmo preferido disponible en este PHP. */
    private static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /** Hash para guardar en users.password_hash. */
    public static function hash(string $plain): string
    {
        return password_hash($plain, self::algo());
    }

    /** ¿La contraseña coincide con el hash? (tiempo constante). */
    public static function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    /** ¿El hash guardado quedó viejo y conviene regenerarlo tras un login correcto? */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    /**
     * Valida una contraseña nueva: sin bytes nulos (el login nunca las acepta y bcrypt corta en ellos),
     * largo mínimo configurable (auth.min_password) y máximo MAX.
     * @return string|null mensaje de error, o null si es válida
     */
    public static function validate(string $plain): ?string
    {
        if (str_contains($plain, "\0")) {
            return 'La contraseña tiene caracteres no válidos.';
        }
        $min = (int) Config::get('auth.min_password');
        $len = mb_strlen($plain);
        if ($len < $min) {
            return "La contraseña debe tener al menos {$min} caracteres.";
        }
        if ($len > self::MAX) {
            return 'La contraseña es demasiado larga (máximo ' . self::MAX . ' caracteres).';
        }
        return null;
    }
}
