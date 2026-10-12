<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Integrity;

/**
 * Récords de los minijuegos (tabla game_highscores): el mejor puntaje de cada usuario en cada juego, del que sale el
 * ranking que se muestra al terminar una partida. Requiere base de datos.
 *
 * Solo GameSession::finish escribe acá, con un puntaje ya validado y dentro de su transacción. Cada fila va firmada
 * (Services\Integrity): las que no cuadran (editadas en la base) no aparecen en el ranking y se pisan con el próximo
 * puntaje válido del usuario. El ranking solo expone nombre de pila + inicial del apellido y el puntaje.
 */
final class GameScore
{
    /** Contexto de la firma. */
    private const SIG = 'game_highscores';

    /**
     * Guarda `$score` como récord del usuario en `$game` si supera el anterior (o si no tenía, o el anterior estaba
     * alterado). Bloquea la fila para que dos partidas que terminan a la vez no se pisen. Devuelve si fue récord.
     */
    public static function record(int $userId, string $game, int $score, int $sessionId, string $at): bool
    {
        return Database::transaction(function () use ($userId, $game, $score, $sessionId, $at) {
            $cur = Database::selectOne(
                'SELECT * FROM game_highscores WHERE user_id = ? AND game = ? FOR UPDATE', [$userId, $game]
            );
            if ($cur !== null && self::valid($cur) && (int) $cur['score'] >= $score) {
                return false;
            }
            $sig = Integrity::sign(self::SIG, [$userId, $game, $score, $sessionId, $at]);
            if ($cur === null) {
                Database::execute(
                    'INSERT INTO game_highscores (user_id, game, score, session_id, achieved_at, sig) VALUES (?, ?, ?, ?, ?, ?)',
                    [$userId, $game, $score, $sessionId, $at, $sig]
                );
            } else {
                Database::execute(
                    'UPDATE game_highscores SET score = ?, session_id = ?, achieved_at = ?, sig = ? WHERE user_id = ? AND game = ?',
                    [$score, $sessionId, $at, $sig, $userId, $game]
                );
            }
            return true;
        });
    }

    /**
     * Los `$size` mejores de `$game`: un lugar por usuario (su récord), de mayor a menor puntaje y, a igual puntaje,
     * primero quien lo logró antes. Excluye cuentas suspendidas y filas con firma inválida.
     * @return array<int, array{rank: int, name: string, score: int, me: bool}>
     */
    public static function top(string $game, int $size, ?User $viewer = null): array
    {
        $size = max(1, min($size, 50));
        $limit = $size * 3 + 10; // margen por si hay filas descartadas por la firma (entero calculado acá, no del cliente)
        $out = [];
        foreach (Database::select(
            "SELECT h.*, u.full_name FROM game_highscores h JOIN users u ON u.id = h.user_id AND u.active = 1
              WHERE h.game = ? ORDER BY h.score DESC, h.achieved_at ASC, h.user_id ASC LIMIT {$limit}",
            [$game]
        ) as $r) {
            if (!self::valid($r)) {
                error_log("game_highscores user {$r['user_id']}/{$r['game']}: firma inválida, se ignora");
                continue;
            }
            $out[] = [
                'rank'  => count($out) + 1,
                'name'  => self::publicName((string) $r['full_name']),
                'score' => (int) $r['score'],
                'me'    => $viewer !== null && (int) $r['user_id'] === $viewer->id,
            ];
            if (count($out) === $size) {
                break;
            }
        }
        return $out;
    }

    /** Récord vigente (firma válida) del usuario en el juego, o 0. */
    public static function best(int $userId, string $game): int
    {
        $r = Database::selectOne('SELECT * FROM game_highscores WHERE user_id = ? AND game = ?', [$userId, $game]);
        return $r !== null && self::valid($r) ? (int) $r['score'] : 0;
    }

    /**
     * Nombre que ven los demás jugadores: nombre de pila + inicial del último apellido ("Juan P."), sin DNI ni email.
     * Se corta a 24 caracteres. Es texto plano: la vista lo escapa (el JS lo pone con textContent).
     */
    public static function publicName(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'Jugador';
        }
        $name = mb_substr($parts[0], 0, 20);
        if (count($parts) > 1) {
            $name .= ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
        }
        return mb_substr($name, 0, 24);
    }

    /** ¿La firma de la fila corresponde a sus datos? */
    private static function valid(array $r): bool
    {
        return Integrity::valid(self::SIG, [(int) $r['user_id'], (string) $r['game'], (int) $r['score'],
            (int) $r['session_id'], (string) $r['achieved_at']], $r['sig']);
    }
}
