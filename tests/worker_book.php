<?php
/**
 * tests/worker_book.php — Proceso hijo de ConcurrentBookingTest: intenta reservar a una hora exacta
 * para que dos procesos reales compitan por el mismo horario.
 *
 * Uso: php tests/worker_book.php <dni> <barbero> <fecha> <hora> <timestamp-de-arranque-con-decimales>
 * Imprime una línea JSON: {"ok": true} o {"ok": false, "status": 409, "error": "..."}.
 * El entorno (DB_NAME=navaja_test) lo hereda del proceso que lo lanza.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Models\User;
use App\Services\Booking;
use App\Services\BookingException;

if (App\Core\Config::get('database.name') !== 'navaja_test') {
    exit(1);
}
[, $dni, $barber, $date, $time, $startAt] = $argv;
$user = User::find($dni);

while (microtime(true) < (float) $startAt) {
    usleep(200); // espera activa: ambos procesos disparan en el mismo instante
}
try {
    Booking::create($user, 'corte', $barber, $date, $time);
    echo json_encode(['ok' => true]);
} catch (BookingException $e) {
    echo json_encode(['ok' => false, 'status' => $e->status, 'error' => $e->getMessage()]);
}
