<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\UiUsage;
use App\Services\Auth;

/**
 * POST /api/uso — recibe los lotes de uso de la interfaz que arma public/assets/js/track.js (clicks por componente,
 * presencia del mouse y visitas por pantalla) para el mapa de calor del superusuario (/su/uso).
 *
 * El navegador lo manda con navigator.sendBeacon (sobrevive al cierre de la página y no frena nada), como formulario:
 * `_csrf` (no puede llevar cabeceras) y `d` = el lote en JSON. Nunca devuelve datos: 204 si se guardó o se descartó
 * en silencio (sin base, cuenta del personal, superusuario), 4xx si el pedido no es válido.
 * Topes (config/tracking.php → limits): tamaño del JSON, entradas por lote y lotes por minuto por sesión.
 */
final class UsageController extends Controller
{
    /** Clave de la sesión con la ventana del límite de lotes: [inicio del minuto, lotes en ese minuto]. */
    private const RATE_KEY = 'uso_rl';

    /** POST /api/uso — campos _csrf, d (JSON). → 204 | 400 | 401 | 413 | 419 | 429 */
    public function collect(Request $request): void
    {
        $user = Auth::user();
        if ($user === null) {
            Response::json(['error' => 'Sin sesión.'], 401);
        }
        if (!$this->csrfValid($request)) {
            Response::json(['error' => 'La página caducó.'], 419);
        }
        // Solo interesa cómo usan la app los clientes: el personal entra al panel y sus pruebas ensuciarían el mapa.
        if (!Database::enabled() || $user->hasPanel()) {
            $this->noContent();
        }

        $limits = UiUsage::config()['limits'];
        $json = $request->string('d', '', $limits['body_bytes'] + 1);
        if (strlen($json) > $limits['body_bytes']) {
            Response::json(['error' => 'Lote demasiado grande.'], 413);
        }
        $raw = json_decode($json, true, 6);
        if (!is_array($raw)) {
            Response::json(['error' => 'Lote inválido.'], 400);
        }

        [$start, $count] = Session::get(self::RATE_KEY) ?? [0, 0];
        $now = time();
        if ($now - (int) $start >= 60) {
            [$start, $count] = [$now, 0];
        }
        if ($count >= $limits['batches']) {
            Response::json(['error' => 'Demasiados lotes.'], 429);
        }
        Session::set(self::RATE_KEY, [$start, $count + 1]);

        UiUsage::record($user->id, UiUsage::sanitize($raw), $now);
        $this->noContent();
    }

    /** Responde 204 sin cuerpo y termina. */
    private function noContent(): never
    {
        http_response_code(204);
        header('Cache-Control: no-store');
        exit;
    }
}
