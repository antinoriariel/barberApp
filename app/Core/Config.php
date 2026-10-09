<?php
declare(strict_types=1);

namespace App\Core;

/** Acceso de solo lectura a config/app.php con notación de puntos: Config::get('assets.styles'). */
final class Config
{
    /** Contenido de config/app.php, cargado una sola vez (null = todavía no se leyó). */
    private static ?array $items = null;

    /**
     * Devuelve el valor de una clave anidada.
     *
     * Recorre el arreglo segmento por segmento ("assets.styles" → ['assets']['styles']);
     * si algún segmento no existe devuelve $default en lugar de fallar.
     *
     * @param string $key     clave con puntos, p. ej. 'security.login_window'
     * @param mixed  $default valor si la clave no existe
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::$items ??= require BASE_PATH . '/config/app.php';

        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
