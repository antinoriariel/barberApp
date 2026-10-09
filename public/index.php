<?php
/**
 * public/index.php — Front controller: toda petición dinámica entra por acá.
 *
 * 1. Carga el arranque (autoload, errores, sesión).
 * 2. Envía las cabeceras de seguridad (CSP, X-Frame-Options…).
 * 3. Despacha la ruta. Cualquier excepción no atrapada se registra en el log y el
 *    usuario ve una página 500 genérica (nunca el detalle del error).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Security;

Security::sendHeaders();

try {
    $router = new Router();
    (require BASE_PATH . '/config/routes.php')($router);
    $router->dispatch(new Request());
} catch (Throwable $e) {
    error_log((string) $e);
    if (Config::get('debug')) {
        throw $e; // en desarrollo, que PHP muestre el detalle
    }
    Response::error(500, 'Algo salió mal', 'Ya estamos al tanto. Probá de nuevo en unos minutos.');
}
