<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\GameReward;
use App\Models\User;
use App\Services\Auth;

/**
 * API JSON de los minijuegos. El juego corre en el navegador, así que el servidor no confía en el
 * puntaje: /juegos/iniciar abre una partida en la sesión (con su hora de inicio) y /juegos/premio
 * solo entrega la promo si la partida existe, duró lo mínimo creíble y el puntaje llega a la meta.
 * Es una barrera contra el abuso casual, no contra alguien que se lo proponga: por eso el límite
 * de un premio por día y por cliente, y que los premios sean descuentos chicos.
 */
final class GameController extends Controller
{
    private const RUN_KEY = 'game_run';

    /** Usuario de la sesión + CSRF válido, o responde 401/419 JSON. */
    private function apiWriter(Request $request): User
    {
        $user = Auth::user() ?? Response::json(['error' => 'Tu sesión caducó. Volvé a ingresar.'], 401);
        if (!$this->csrfValid($request)) {
            Response::json(['error' => 'La página caducó. Recargala e intentá de nuevo.'], 419);
        }
        return $user;
    }

    /** Definición del juego `$slug` (config/games.php) o responde 422. */
    private function game(string $slug): array
    {
        return (require BASE_PATH . '/config/games.php')['games'][$slug]
            ?? Response::json(['error' => 'Juego desconocido.'], 422);
    }

    /** POST /juegos/iniciar — campo: game. Abre una partida en la sesión. → {"ok": true} */
    public function start(Request $request): void
    {
        $this->apiWriter($request);
        $slug = (string) $request->input('game', '');
        $this->game($slug);
        Session::set(self::RUN_KEY, ['game' => $slug, 'at' => time()]);
        Response::json(['ok' => true]);
    }

    /**
     * POST /juegos/premio — campos: game, score. Cada partida sirve una sola vez (se consume al reclamar).
     * → 201 {"reward": {code, label, expires}} | 4xx {"error": mensaje}
     */
    public function claim(Request $request): void
    {
        $user = $this->apiWriter($request);
        $slug = (string) $request->input('game', '');
        $game = $this->game($slug);
        $score = (int) $request->input('score', 0);

        $run = Session::get(self::RUN_KEY);
        Session::remove(self::RUN_KEY);

        if (empty($game['reward'])) {
            Response::json(['error' => 'Este juego no da premios.'], 422);
        }
        if (!Database::enabled()) {
            Response::json(['error' => 'Los premios no están disponibles en este momento.'], 503);
        }
        if (!is_array($run) || $run['game'] !== $slug || $score < $game['target']
            || time() - $run['at'] < $game['min_seconds']) {
            Response::json(['error' => 'La partida no es válida para el premio.'], 422);
        }
        if (GameReward::wonToday($user)) {
            Response::json(['error' => 'Hoy ya ganaste tu premio. ¡Volvé mañana!'], 409);
        }

        $valid = (require BASE_PATH . '/config/games.php')['valid_days'];
        Response::json(['reward' => GameReward::create($user, $slug, $game['promo'], $score, $valid)], 201);
    }
}
