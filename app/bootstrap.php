<?php
declare(strict_types=1);

/**
 * bootstrap.php — Arranque común: constantes, autoload, errores, helpers y sesión.
 * Lo carga public/index.php antes de despachar la ruta.
 */

define('BASE_PATH', dirname(__DIR__));

// Autoload PSR-4 simple: App\Models\User → app/Models/User.php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/Support/helpers.php';

// Credenciales y ajustes del entorno (.env) antes de leer cualquier configuración.
App\Core\Env::load();
date_default_timezone_set(App\Core\Config::get('timezone'));

// Errores: siempre se registran; solo se muestran en pantalla con APP_DEBUG=1.
// Mostrar errores en producción filtraría rutas y detalles internos.
error_reporting(E_ALL);
ini_set('display_errors', App\Core\Config::get('debug') ? '1' : '0');
ini_set('log_errors', '1');

// En la línea de comandos (bin/*.php) no hay sesión ni cookies.
if (PHP_SAPI !== 'cli') {
    App\Core\Session::start();
}
