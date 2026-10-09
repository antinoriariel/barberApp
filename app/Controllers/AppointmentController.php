<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\Auth;
use App\Services\Booking;
use App\Services\BookingException;

/**
 * API JSON de turnos: disponibilidad, reservar, cancelar y reprogramar.
 * Todas exigen sesión (401 JSON, no redirección); las que cambian estado son POST con token CSRF
 * (campo _csrf o cabecera X-CSRF-Token). Siempre actúan sobre el usuario de la sesión.
 */
final class AppointmentController extends Controller
{
    /** Usuario de la sesión o responde 401 JSON. */
    private function apiUser(): User
    {
        return Auth::user() ?? Response::json(['error' => 'Tu sesión caducó. Volvé a ingresar.'], 401);
    }

    /** Usuario de la sesión + CSRF válido (419 JSON) para las acciones POST. */
    private function apiWriter(Request $request): User
    {
        $user = $this->apiUser();
        if (!$this->csrfValid($request)) {
            Response::json(['error' => 'La página caducó. Recargala e intentá de nuevo.'], 419);
        }
        return $user;
    }

    /**
     * GET /api/disponibilidad?fecha=YYYY-MM-DD&barbero=slug|any&servicio=slug[,slug…]
     * → {"slots": [{"time": "10:00", "free": true}, …]}. Con varios servicios se busca lugar para la suma de sus duraciones.
     */
    public function availability(Request $request): void
    {
        $this->apiUser();
        $date = (string) $request->query('fecha', '');
        $barber = (string) $request->query('barbero', 'any');
        if (!Database::enabled()) { // sin base de datos solo existen los horarios fijos de ejemplo
            Response::json(['slots' => Schedule::slots($date)]);
        }
        $services = Service::findMany((string) $request->query('servicio', ''), Booking::MAX_SERVICES);

        if (!Schedule::isDate($date) || !$services) {
            Response::json(['error' => 'Fecha o servicio inválidos.'], 422);
        }
        $minutes = array_sum(array_map(static fn (Service $s) => $s->minutes, $services));
        Response::json(['slots' => Schedule::slots($date, $barber, $minutes)]);
    }

    /** POST /turnos — campos: service (slug o varios separados por coma), barber, day (YYYY-MM-DD), time (HH:MM). → 201 {"appointment": {...}} */
    public function store(Request $request): void
    {
        $user = $this->apiWriter($request);
        $this->respond(fn () => ['appointment' => Booking::create($user, ...$this->booking($request))], 201);
    }

    /** POST /turnos/cancelar — campo: id. → {"ok": true} */
    public function cancel(Request $request): void
    {
        $user = $this->apiWriter($request);
        $this->respond(function () use ($user, $request) {
            Booking::cancel($user, (int) $request->input('id', 0));
            return ['ok' => true];
        });
    }

    /** POST /turnos/reprogramar — campos: id (turno a mover) + los de store(). → {"appointment": {...}} */
    public function reschedule(Request $request): void
    {
        $user = $this->apiWriter($request);
        $this->respond(fn () => ['appointment' => Booking::reschedule($user, (int) $request->input('id', 0), ...$this->booking($request))]);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} service, barber, day, time tal como los mandó el cliente */
    private function booking(Request $request): array
    {
        return array_map(fn (string $k) => (string) $request->input($k, ''), ['service', 'barber', 'day', 'time']);
    }

    /** Ejecuta la acción y traduce BookingException a JSON {"error": mensaje} con su código HTTP. */
    private function respond(callable $action, int $okStatus = 200): never
    {
        try {
            Response::json($action(), $okStatus);
        } catch (BookingException $e) {
            Response::json(['error' => $e->getMessage()], $e->status);
        }
    }
}
