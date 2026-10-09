<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\QueueTicket;
use App\Services\Audit;
use App\Services\Auth;

/**
 * Fila por orden de llegada: el QR del mostrador abre GET /fila?c=CÓDIGO, el cliente confirma con un POST (nada que
 * cambie estado va por GET) y recibe el número siguiente. Inicio consulta GET /api/fila para mantener su lugar al día.
 *
 * El código del QR (config queue.code) evita que se saquen números sin estar en el local: hay que haberlo escaneado.
 */
final class QueueController extends Controller
{
    /** ¿El código recibido es el del QR del mostrador? (comparación a tiempo constante) */
    private function validCode(mixed $code): bool
    {
        return is_string($code) && hash_equals((string) Config::get('queue.code'), $code);
    }

    /**
     * GET /fila?c=CÓDIGO — pantalla para confirmar "Tomar número" (o ver el que ya tiene).
     * Sin sesión, recuerda esta dirección y manda al login; al entrar vuelve acá (ver AuthController::login).
     */
    public function show(Request $request): void
    {
        $code = $request->query('c', '');
        if (!$this->validCode($code)) {
            Response::error(404, 'QR no válido', 'Escaneá el QR que está en el mostrador del local.');
        }
        if (Auth::user() === null) {
            Session::set('after_login', '/fila?c=' . rawurlencode((string) $code));
            $this->redirect('/login');
        }
        $user = $this->requireLogin();
        if (!Database::enabled()) {
            Response::error(503, 'Fila no disponible', 'La fila necesita la base de datos.');
        }
        $this->view('pages/fila', [
            'title'     => 'Tomar número · Barbería',
            'bodyClass' => 'login-page', // mismo marco que el login
            'code'      => (string) $code,
            'ticket'  => QueueTicket::currentFor($user),
            'user'    => $user,
        ]);
    }

    /** POST /fila — campos: c (código del QR). Da el número siguiente (o el que ya tenía) y vuelve a Inicio. */
    public function take(Request $request): void
    {
        $this->requireCsrf($request);
        $user = $this->requireLogin();
        if (!$this->validCode($request->input('c', '')) || !Database::enabled()) {
            Response::error(404, 'QR no válido', 'Escaneá el QR que está en el mostrador del local.');
        }
        $ticket = QueueTicket::take($user);
        Audit::log('queue_take', $user->id, '#' . $ticket['number']);
        $this->redirect('/#inicio');
    }

    /** POST /fila/salir — el cliente deja la fila (cancela su número de hoy). */
    public function leave(Request $request): void
    {
        $this->requireCsrf($request);
        $user = $this->requireLogin();
        if (Database::enabled() && QueueTicket::leave($user)) {
            Audit::log('queue_leave', $user->id);
        }
        $this->redirect('/#inicio');
    }

    /** GET /api/fila → {"ticket": {...}|null} con el número del cliente y su lugar (Inicio lo consulta cada tanto). */
    public function status(Request $request): void
    {
        $user = Auth::user() ?? Response::json(['error' => 'Tu sesión caducó. Volvé a ingresar.'], 401);
        Response::json(['ticket' => QueueTicket::currentFor($user)]);
    }
}
