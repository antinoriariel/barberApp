<?php
/**
 * public/router.php — Script de enrutamiento para el servidor embebido de PHP (solo desarrollo):
 *   php -S 0.0.0.0:8000 -t public public/router.php
 *
 * Sirve tal cual los archivos estáticos que existen y delega el resto al front controller.
 * Los .php de public/ se ejecutan por el front controller, no directamente, para que nadie
 * pueda invocarlos saltándose el enrutador.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($path !== '/' && is_file(__DIR__ . $path) && !str_ends_with(strtolower($path), '.php')) {
    return false;
}

require __DIR__ . '/index.php';
