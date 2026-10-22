<?php
/**
 * public/index.php — Front controller: toda petición dinámica entra por acá.
 *
 * 1. Carga el arranque (autoload, errores, sesión).
 * 2. Envía las cabeceras de seguridad (CSP, X-Frame-Options…).
 * 3. Corta con 403 si la IP o el navegador están bloqueados por el superusuario (Models\Ban, Services\Device).
 * 4. Despacha la ruta. Cualquier excepción no atrapada se registra en el log y el
 *    usuario ve una página 500 genérica (nunca el detalle del error).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Security;
use App\Models\Ban;
use App\Services\Device;

Security::sendHeaders();

try {
    $request = new Request();
    // Bloqueo por IP o "Permaban" del navegador: no se atiende nada más (ni el login), sin explicar el motivo.
    if (Ban::blocks($request->ip(), Device::hash())) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        exit('Acceso denegado.');
    }
    $router = new Router();
    (require BASE_PATH . '/config/routes.php')($router);
    $router->dispatch($request);
} catch (Throwable $e) {
    error_log((string) $e);
    if (Config::get('debug')) {
        throw $e; // en desarrollo, que PHP muestre el detalle
    }
    Response::error(500, 'Algo salió mal', 'Ya estamos al tanto. Probá de nuevo en unos minutos.');
}
