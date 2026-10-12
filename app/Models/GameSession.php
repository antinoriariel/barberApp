<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Integrity;

/**
 * Partidas de los minijuegos (tabla game_sessions): de acá sale cuánto tiempo jugó cada usuario a cada juego.
 * Requiere base de datos.
 *
 * Reglas de integridad:
 * - El tiempo lo mide el servidor (started_at al iniciar, su propio reloj al terminar); el navegador nunca lo informa.
 *   Una partida se cuenta como mucho `max_session` segundos (pestaña olvidada, juego abierto toda la noche…).
 * - Cada partida tiene un token aleatorio de un solo uso; en la base solo se guarda su sha256. Cerrar una partida es
 *   una transacción con la fila bloqueada (FOR UPDATE) y solo pasa de 'playing' a otro estado una vez: dos pedidos
 *   simultáneos con el mismo token no pueden registrar dos puntajes.
 * - El puntaje que manda el navegador se controla contra los límites del juego y la duración real (plausible()); si
 *   no es creíble la partida queda 'rejected' y no suma al ranking.
 * - Cada fila cerrada lleva una firma HMAC (Services\Integrity); las filas alteradas a mano se ignoran en los totales.
 * - Todas las consultas filtran por el usuario de la sesión y usan parámetros (nunca se interpola texto del cliente).
 */
final class GameSession
{
    /** Contexto de la firma (para que una firma de esta tabla no valga en otra). */
    private const SIG = 'game_sessions';

    /** Formato de fecha con que se guardan y firman los tiempos. */
    private const FMT = 'Y-m-d H:i:s';

    /**
     * Abre una partida nueva de `$game` y devuelve el token en claro (64 caracteres hex) que el navegador tiene que
     * devolver al terminar. Antes cierra como 'abandoned' (sin puntaje) las partidas que el usuario dejó abiertas,
     * contando su tiempo hasta ahora con el tope $maxSession: así hay como mucho una partida en juego por usuario.
     */
    public static function open(User $user, string $game, int $maxSession): string
    {
        return Database::transaction(function () use ($user, $game, $maxSession) {
            $now = time();
            foreach (Database::select(
                "SELECT id, game, started_at FROM game_sessions WHERE user_id = ? AND status = 'playing' FOR UPDATE",
                [$user->id]
            ) as $old) {
                self::close((int) $old['id'], $user->id, (string) $old['game'], (string) $old['started_at'],
                    'abandoned', 0, $now, $maxSession);
            }
            $token = bin2hex(random_bytes(32));
            Database::execute(
                'INSERT INTO game_sessions (user_id, game, token_hash, status, started_at) VALUES (?, ?, ?, ?, ?)',
                [$user->id, $game, hash('sha256', $token), 'playing', date(self::FMT, $now)]
            );
            return $token;
        });
    }

    /**
     * Cierra la partida del token con el puntaje informado. Valida que el token sea del usuario y del juego, que la
     * partida siga abierta y que el puntaje sea creíble para la duración medida por el servidor ($rules = la entrada
     * del juego en config/games.php). Si es válido actualiza el récord del usuario (GameScore::record).
     *
     * @return array{id: int, status: string, seconds: int, score: int, best: bool}|null null si el token no
     *         corresponde a una partida abierta de ese usuario y juego (vencido, ajeno, repetido o inventado)
     */
    public static function finish(User $user, string $game, string $token, int $score, array $rules, int $maxSession): ?array
    {
        return Database::transaction(function () use ($user, $game, $token, $score, $rules, $maxSession) {
            $row = Database::selectOne(
                "SELECT id, started_at FROM game_sessions
                  WHERE token_hash = ? AND user_id = ? AND game = ? AND status = 'playing' FOR UPDATE",
                [hash('sha256', $token), $user->id, $game]
            );
            if ($row === null) {
                return null;
            }
            $now = time();
            $seconds = self::elapsed((string) $row['started_at'], $now, $maxSession);
            $ok = self::plausible($score, $seconds, $rules);
            $status = $ok ? 'done' : 'rejected';
            self::close((int) $row['id'], $user->id, $game, (string) $row['started_at'], $status, $score, $now, $maxSession);
            $best = $ok && $score > 0 && GameScore::record($user->id, $game, $score, (int) $row['id'], date(self::FMT, $now));
            return ['id' => (int) $row['id'], 'status' => $status, 'seconds' => $seconds, 'score' => $score, 'best' => $best];
        });
    }

    /**
     * ¿Es creíble hacer `$score` puntos en `$seconds` segundos? Entero no negativo, múltiplo de `step`, no mayor que
     * `max_score` y no mayor que burst + rate × segundos (ver config/games.php). Sin límites configurados, rechaza.
     */
    public static function plausible(int $score, int $seconds, array $rules): bool
    {
        if (!isset($rules['max_score'], $rules['rate'], $rules['burst'])) {
            return false;
        }
        $step = max(1, (int) ($rules['step'] ?? 1));
        return $score >= 0
            && $score % $step === 0
            && $score <= (int) $rules['max_score']
            && $score <= (float) $rules['burst'] + (float) $rules['rate'] * $seconds;
    }

    /**
     * Marca como premiada una partida válida del usuario (para que un mismo resultado no dé dos cupones) y devuelve
     * su puntaje y duración, o null si no existe, no es del usuario/juego, no terminó bien o ya dio premio.
     * Debe llamarse dentro de una transacción (bloquea la fila hasta el commit).
     * @return array{id: int, score: int, seconds: int}|null
     */
    public static function claimable(User $user, string $game, string $token): ?array
    {
        $row = Database::selectOne(
            "SELECT * FROM game_sessions
              WHERE token_hash = ? AND user_id = ? AND game = ? AND status = 'done' AND rewarded = 0 FOR UPDATE",
            [hash('sha256', $token), $user->id, $game]
        );
        if ($row === null || !Integrity::valid(self::SIG, self::signed($row), $row['sig'])) {
            return null;
        }
        $row['rewarded'] = 1;
        Database::execute('UPDATE game_sessions SET rewarded = 1, sig = ? WHERE id = ? AND rewarded = 0',
            [Integrity::sign(self::SIG, self::signed($row)), $row['id']]);
        return ['id' => (int) $row['id'], 'score' => (int) $row['score'], 'seconds' => (int) $row['seconds']];
    }

    /**
     * Tiempo invertido por un usuario en cada juego, en segundos (partidas terminadas, abandonadas o rechazadas: todas
     * son tiempo jugado). No se muestra en la app: es para el negocio (ver bin/game-stats.php).
     * Las filas con la firma alterada no se cuentan.
     * @return array<string, int> slug => segundos
     */
    public static function timeSpent(int $userId): array
    {
        $out = [];
        foreach (self::closedRows('user_id = ?', [$userId]) as $r) {
            $out[$r['game']] = ($out[$r['game']] ?? 0) + (int) $r['seconds'];
        }
        return $out;
    }

    /**
     * Totales por juego de todos los usuarios: partidas, jugadores distintos y segundos jugados (firmas válidas).
     * @return array<string, array{plays: int, players: int, seconds: int}>
     */
    public static function totals(): array
    {
        $out = $players = [];
        foreach (self::closedRows('1 = 1', []) as $r) {
            $g = $r['game'];
            $out[$g] ??= ['plays' => 0, 'players' => 0, 'seconds' => 0];
            $out[$g]['plays']++;
            $out[$g]['seconds'] += (int) $r['seconds'];
            $players[$g][$r['user_id']] = true;
        }
        foreach ($out as $g => &$t) {
            $t['players'] = count($players[$g]);
        }
        return $out;
    }

    /**
     * Partidas cerradas que cumplen `$where` y cuya firma es válida. Las alteradas se descartan y se dejan en el log
     * de errores para revisarlas. `$where` es siempre una condición fija escrita en esta clase (nunca del cliente).
     * @return array<int, array<string, mixed>>
     */
    private static function closedRows(string $where, array $params): array
    {
        $ok = [];
        foreach (Database::select("SELECT * FROM game_sessions WHERE status <> 'playing' AND {$where}", $params) as $r) {
            if (Integrity::valid(self::SIG, self::signed($r), $r['sig'])) {
                $ok[] = $r;
            } else {
                error_log("game_sessions #{$r['id']}: firma inválida (fila alterada fuera de la app), se ignora");
            }
        }
        return $ok;
    }

    /** Segundos entre el inicio guardado y $now, sin negativos (reloj corrido) y con el tope $maxSession. */
    private static function elapsed(string $startedAt, int $now, int $maxSession): int
    {
        return max(0, min($maxSession, $now - (int) strtotime($startedAt)));
    }

    /** Cierra una partida abierta con su estado, puntaje, hora de fin, duración y firma. */
    private static function close(int $id, int $userId, string $game, string $startedAt, string $status,
                                  int $score, int $now, int $maxSession): void
    {
        $row = [
            'id' => $id, 'user_id' => $userId, 'game' => $game, 'status' => $status, 'started_at' => $startedAt,
            'ended_at' => date(self::FMT, $now), 'seconds' => self::elapsed($startedAt, $now, $maxSession),
            'score' => max(0, min($score, 4294967295)), 'rewarded' => 0,
        ];
        Database::execute(
            "UPDATE game_sessions SET status = ?, ended_at = ?, seconds = ?, score = ?, sig = ?
              WHERE id = ? AND status = 'playing'",
            [$status, $row['ended_at'], $row['seconds'], $row['score'], Integrity::sign(self::SIG, self::signed($row)), $id]
        );
    }

    /** Campos que cubre la firma, normalizados (la base devuelve números como texto). */
    private static function signed(array $r): array
    {
        return [(int) $r['id'], (int) $r['user_id'], (string) $r['game'], (string) $r['status'], (string) $r['started_at'],
                (string) $r['ended_at'], (int) $r['seconds'], (int) $r['score'], (int) $r['rewarded']];
    }
}
