<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Models\GameReward;
use App\Models\GameScore;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Audit;
use App\Services\Auth;

/**
 * API JSON de los minijuegos. El juego corre en el navegador, así que el servidor no confía en lo que informa:
 * - /juegos/iniciar abre una partida en la base (Models\GameSession) con la hora del servidor y devuelve un token
 *   de un solo uso; el tiempo jugado se mide siempre con el reloj del servidor.
 * - /juegos/fin cierra esa partida con el puntaje; si no es creíble para la duración real queda rechazada y no
 *   entra al ranking. Responde el top del juego para mostrarlo en el "game over".
 * - /juegos/premio solo entrega la promo sobre una partida ya cerrada y válida (con el puntaje que guardó el
 *   servidor, no el que mande el cliente), una vez por partida y un premio por día por cliente.
 * Es una barrera contra el abuso casual y los pedidos armados a mano, no contra alguien que reescriba el juego:
 * por eso los topes por juego (config/games.php), el límite diario y que los premios sean descuentos chicos.
 */
final class GameController extends Controller
{
    /** Partidas que un usuario puede iniciar por minuto (evita llenar la tabla con pedidos automáticos). */
    private const STARTS_PER_MINUTE = 20;

    /** Usuario de la sesión + CSRF válido, o responde 401/419 JSON. */
    private function apiWriter(Request $request): User
    {
        $user = Auth::user() ?? Response::json(['error' => 'Tu sesión caducó. Volvé a ingresar.'], 401);
        if (!$this->csrfValid($request)) {
            Response::json(['error' => 'La página caducó. Recargala e intentá de nuevo.'], 419);
        }
        return $user;
    }

    /** config/games.php completo (se lee una vez por petición). */
    private function config(): array
    {
        static $config = null;
        return $config ??= require BASE_PATH . '/config/games.php';
    }

    /**
     * Valida el slug contra la lista blanca de config/games.php y devuelve [slug, definición], o responde 422.
     * El slug nunca llega a una consulta sin pasar por acá.
     */
    private function game(mixed $slug): array
    {
        $slug = is_string($slug) ? $slug : '';
        $game = $this->config()['games'][$slug] ?? null;
        if ($game === null || !preg_match('/^[a-z]{1,20}$/', $slug)) {
            Response::json(['error' => 'Juego desconocido.'], 422);
        }
        return [$slug, $game];
    }

    /** Token de partida del pedido (64 caracteres hex) o null si falta o tiene otro formato. */
    private function token(Request $request): ?string
    {
        $run = $request->string('run', '', 100);
        return preg_match('/^[0-9a-f]{64}$/', $run) ? $run : null;
    }

    /** Ranking del juego para responder junto a otros datos (vacío sin base de datos). */
    private function top(string $slug, ?User $user): array
    {
        return Database::enabled() ? GameScore::top($slug, (int) $this->config()['ranking_size'], $user) : [];
    }

    /** POST /juegos/iniciar — campo: game. Abre una partida. → {"run": token|null} (null sin base de datos) */
    public function start(Request $request): void
    {
        $user = $this->apiWriter($request);
        [$slug] = $this->game($request->input('game'));
        if (!Database::enabled() || $user->id === 0) {
            Response::json(['run' => null]); // se puede jugar, pero no se registra nada
        }
        if (RateLimiter::hit("game_start:u:{$user->id}", 60) > self::STARTS_PER_MINUTE) {
            Response::json(['error' => 'Demasiadas partidas seguidas. Esperá un minuto.'], 429);
        }
        Response::json(['run' => GameSession::open($user, $slug, (int) $this->config()['max_session'])]);
    }

    /**
     * POST /juegos/fin — campos: game, run (token de /juegos/iniciar), score. Cierra la partida y devuelve el ranking.
     * → {"counted": bool, "best": int, "record": bool, "top": [{rank, name, score, me}]}
     *   counted = false si el puntaje no fue creíble (no suma al ranking; el tiempo jugado sí queda registrado).
     * El puntaje tiene que ser un entero de hasta 9 cifras; cualquier otra cosa se rechaza antes de tocar la base.
     */
    public function finish(Request $request): void
    {
        $user = $this->apiWriter($request);
        [$slug, $game] = $this->game($request->input('game'));
        $raw = $request->string('score', '', 20);
        if (!preg_match('/^\d{1,9}$/', $raw)) {
            Response::json(['error' => 'Puntaje inválido.'], 422);
        }
        if (!Database::enabled() || $user->id === 0) {
            Response::json(['counted' => false, 'best' => 0, 'record' => false, 'top' => []]);
        }
        $token = $this->token($request) ?? Response::json(['error' => 'La partida no es válida.'], 422);
        $score = (int) $raw;

        $result = GameSession::finish($user, $slug, $token, $score, $game, (int) $this->config()['max_session']);
        if ($result === null) {
            Response::json(['error' => 'La partida ya terminó o no es válida.'], 409);
        }
        if ($result['status'] === 'rejected') {
            Audit::log('game_score_rejected', $user->id, "{$slug}: {$score} pts en {$result['seconds']} s");
        }
        Response::json([
            'counted' => $result['status'] === 'done',
            'best'    => GameScore::best($user->id, $slug),
            'record'  => $result['best'],
            'top'     => $this->top($slug, $user),
        ]);
    }

    /** GET /api/juegos/ranking?game=slug — top del juego (solo con sesión). → {"top": [...], "best": int} */
    public function leaderboard(Request $request): void
    {
        $user = Auth::user() ?? Response::json(['error' => 'Tu sesión caducó. Volvé a ingresar.'], 401);
        [$slug] = $this->game($request->query('game'));
        Response::json([
            'top'  => $this->top($slug, $user),
            'best' => Database::enabled() && $user->id !== 0 ? GameScore::best($user->id, $slug) : 0,
        ]);
    }

    /**
     * POST /juegos/premio — campos: game, run. Entrega el premio de una partida ya cerrada con /juegos/fin si fue
     * válida, llegó a la meta y duró lo mínimo creíble (todo con los datos que guardó el servidor). La partida se marca
     * premiada y el chequeo de "un premio por día" se hace con la cuenta bloqueada, así dos pedidos simultáneos no
     * pueden sacar dos cupones.
     * → 201 {"reward": {code, label, expires}} | 4xx {"error": mensaje}
     */
    public function claim(Request $request): void
    {
        $user = $this->apiWriter($request);
        [$slug, $game] = $this->game($request->input('game'));
        if (empty($game['reward'])) {
            Response::json(['error' => 'Este juego no da premios.'], 422);
        }
        if (!Database::enabled() || $user->id === 0) {
            Response::json(['error' => 'Los premios no están disponibles en este momento.'], 503);
        }
        $token = $this->token($request) ?? Response::json(['error' => 'La partida no es válida para el premio.'], 422);
        $valid = (int) $this->config()['valid_days'];

        [$status, $body] = Database::transaction(function () use ($user, $slug, $game, $token, $valid) {
            Database::selectOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->id]); // serializa por cliente
            $run = GameSession::claimable($user, $slug, $token);
            if ($run === null || $run['score'] < $game['target'] || $run['seconds'] < $game['min_seconds']) {
                return [422, ['error' => 'La partida no es válida para el premio.']];
            }
            if (GameReward::wonToday($user)) {
                return [409, ['error' => 'Hoy ya ganaste tu premio. ¡Volvé mañana!']];
            }
            return [201, ['reward' => GameReward::create($user, $slug, $game['promo'], $run['score'], $valid)]];
        });
        if ($status === 201) {
            Audit::log('game_reward', $user->id, $slug);
        }
        Response::json($body, $status);
    }
}
