<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\GameReward;
use App\Models\LoyaltyCard;
use App\Models\Product;
use App\Models\QueueTicket;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Shop;

/**
 * Controlador de la app autenticada.
 *
 * La PWA es una sola página con varias pantallas (inicio, servicios, reservar,
 * barberos y perfil). Este controlador reúne los datos de todos los modelos y
 * la vista pages/shell las reparte entre las pantallas; la navegación entre
 * ellas la resuelve el JS con el hash de la URL.
 */
final class ShellController extends Controller
{
    /**
     * GET / — exige sesión (si no hay, redirige a /login; los administradores van a /admin) y renderiza la app con todos los datos.
     * Las claves del arreglo son las variables que reciben pages/shell y sus pantallas.
     */
    public function index(Request $request): void
    {
        $user = $this->requireLogin();
        // El administrador solo ve su panel, no la app de clientes (el panel necesita la base de datos).
        if ($user->isAdmin && \App\Core\Database::enabled()) {
            $this->redirect('/admin');
        }
        $services = Service::all();
        $days = Schedule::openDays(7);

        $this->view('pages/shell', [
            'title'       => 'Barbería',
            'scripts'     => ['js/main.js'],
            'user'        => $user,
            'shop'        => Shop::get(),
            'loyalty'     => LoyaltyCard::for($user),
            'next'        => Appointment::next($user),
            'history'     => Appointment::history($user),
            'services'    => $services,
            'popular'     => Service::popular(3),
            'products'    => Product::all(), // catálogo estático (pestaña Productos de Servicios)
            'queue'       => QueueTicket::currentFor($user), // número de la fila de hoy (QR del mostrador) o null
            'games'       => (require BASE_PATH . '/config/games.php')['games'],
            'rewards'     => GameReward::active($user),
            'barbers'     => Barber::all(),
            'days'        => $days,
            // Horarios del primer día con el primer servicio y "cualquier barbero" (la selección inicial);
            // booking.js los recarga desde /api/disponibilidad al cambiar servicio, barbero o día.
            'slots'       => $days && $services ? Schedule::slots($days[0]['iso'], 'any', $services[0]->minutes) : [],
        ]);
    }
}
