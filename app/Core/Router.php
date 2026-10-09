<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Enrutador: asocia (método, ruta) con [Controlador::class, 'acción'].
 * Las rutas se declaran en config/routes.php.
 */
final class Router
{
    /** @var array<string, array<string, array{0: class-string, 1: string}>> método → ruta → [clase, acción] */
    private array $routes = [];

    /** Registra una ruta GET. */
    public function get(string $path, array $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    /** Registra una ruta POST. */
    public function post(string $path, array $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    /**
     * Ejecuta la acción que corresponde a la petición.
     * - Ruta y método registrados → instancia el controlador y llama a la acción.
     * - Ruta existente con otro método → 405 con la cabecera Allow.
     * - Ruta desconocida → 404.
     * HEAD se trata como GET (PHP descarta el cuerpo).
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $path = $request->path();
        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {
            $allowed = array_keys(array_filter($this->routes, fn (array $r) => isset($r[$path])));
            if ($allowed) {
                header('Allow: ' . implode(', ', $allowed));
                Response::error(405, 'Método no permitido');
            }
            Response::error(404, 'No encontramos esa página');
        }

        [$class, $action] = $handler;
        (new $class())->$action($request);
    }
}
