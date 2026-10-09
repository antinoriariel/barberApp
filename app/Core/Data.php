<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Origen de datos hardcodeados: carga data/<nombre>.php y lo cachea.
 *
 * Es la única capa que hay que reemplazar por una base de datos: los modelos
 * piden sus datos acá y nada más en la app sabe de dónde salen.
 * Plan de reemplazo: docs/plan-base-de-datos.md.
 */
final class Data
{
    /** @var array<string, array> archivos ya cargados, por nombre */
    private static array $cache = [];

    /**
     * Devuelve el contenido de data/<nombre>.php (se lee una sola vez por petición).
     * $name lo escribe siempre el código de los modelos, nunca el usuario (no hay riesgo de path traversal).
     */
    public static function get(string $name): array
    {
        return self::$cache[$name] ??= require BASE_PATH . "/data/{$name}.php";
    }
}
