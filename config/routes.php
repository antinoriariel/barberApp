<?php
/**
 * config/routes.php — Tabla de rutas: (método, ruta) → [Controlador, acción].
 * Toda ruta que cambie estado (login, logout…) es POST y exige token CSRF.
 */

use App\Controllers\AdminController;
use App\Controllers\AppointmentController;
use App\Controllers\AuthController;
use App\Controllers\GameController;
use App\Controllers\PwaController;
use App\Controllers\QueueController;
use App\Controllers\ShellController;
use App\Core\Router;

return static function (Router $router): void {
    // App (requiere sesión): una sola página con todas las pantallas.
    $router->get('/', [ShellController::class, 'index']);

    // Autenticación por DNI + contraseña.
    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->post('/logout', [AuthController::class, 'logout']);
    $router->post('/logout-todo', [AuthController::class, 'logoutAll']);

    // Alta de cuenta y recuperación de contraseña (requieren base de datos).
    $router->get('/registro', [AuthController::class, 'showRegister']);
    $router->post('/registro', [AuthController::class, 'register']);
    $router->get('/recuperar', [AuthController::class, 'showForgot']);
    $router->post('/recuperar', [AuthController::class, 'forgot']);
    $router->get('/restablecer', [AuthController::class, 'showReset']);
    $router->post('/restablecer', [AuthController::class, 'reset']);

    // API de turnos (JSON; requiere sesión y, las POST, token CSRF).
    $router->get('/api/disponibilidad', [AppointmentController::class, 'availability']);
    $router->post('/turnos', [AppointmentController::class, 'store']);
    $router->post('/turnos/cancelar', [AppointmentController::class, 'cancel']);
    $router->post('/turnos/reprogramar', [AppointmentController::class, 'reschedule']);

    // Fila por orden de llegada: el QR del mostrador abre /fila; tomar o dejar el número es POST con CSRF.
    $router->get('/fila', [QueueController::class, 'show']);
    $router->post('/fila', [QueueController::class, 'take']);
    $router->post('/fila/salir', [QueueController::class, 'leave']);
    $router->get('/api/fila', [QueueController::class, 'status']);

    // Panel de administración (requiere sesión de administrador).
    $router->get('/admin', [AdminController::class, 'dashboard']);
    $router->get('/admin/turnos', [AdminController::class, 'appointments']);
    $router->post('/admin/turnos/estado', [AdminController::class, 'mark']);
    $router->get('/admin/barberos', [AdminController::class, 'barbers']);
    $router->get('/admin/finanzas', [AdminController::class, 'finance']);
    $router->get('/admin/egresos', [AdminController::class, 'expenses']);
    $router->post('/admin/egresos', [AdminController::class, 'saveExpense']);
    $router->post('/admin/egresos/borrar', [AdminController::class, 'deleteExpense']);
    $router->get('/admin/servicios', [AdminController::class, 'services']);
    $router->post('/admin/servicios/guardar', [AdminController::class, 'saveService']);
    $router->post('/admin/servicios/estado', [AdminController::class, 'toggleService']);
    $router->get('/admin/fila', [AdminController::class, 'queue']);
    $router->post('/admin/fila/llamar', [AdminController::class, 'queueCall']);
    $router->post('/admin/fila/ausente', [AdminController::class, 'queueNoShow']);

    // Minijuegos de la pantalla de inicio (JSON; sesión + CSRF).
    $router->post('/juegos/iniciar', [GameController::class, 'start']);
    $router->post('/juegos/premio', [GameController::class, 'claim']);

    // Recursos de la PWA generados dinámicamente.
    $router->get('/manifest.webmanifest', [PwaController::class, 'manifest']);
    $router->get('/icon.png', [PwaController::class, 'icon']);
};
