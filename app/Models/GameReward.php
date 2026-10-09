<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Premios ganados en los minijuegos. Requiere base de datos (con driver files no se pueden guardar).
 * Las escrituras filtran siempre por el usuario de la sesión.
 */
final class GameReward
{
    /** Alfabeto de los códigos: sin 0/O/1/I para que se puedan dictar en el mostrador sin confundirse. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Premios vigentes del cliente (sin canjear y sin vencer), del más nuevo al más viejo.
     * @return array<int, array{code: string, label: string, expires: string}> expires es "18/10"
     */
    public static function active(User $user): array
    {
        if (!Database::enabled() || $user->id === 0) {
            return [];
        }
        return array_map(fn (array $r) => [
            'code'    => $r['code'],
            'label'   => $r['label'],
            'expires' => date('d/m', strtotime($r['expires_at'])),
        ], Database::select(
            "SELECT code, label, expires_at FROM game_rewards
              WHERE user_id = ? AND redeemed_at IS NULL AND expires_at > ?
              ORDER BY created_at DESC LIMIT 10",
            [$user->id, date('Y-m-d H:i:s')]
        ));
    }

    /** ¿El cliente ya ganó un premio hoy? */
    public static function wonToday(User $user): bool
    {
        return Database::selectOne(
            'SELECT id FROM game_rewards WHERE user_id = ? AND created_at >= ? LIMIT 1',
            [$user->id, date('Y-m-d 00:00:00')]
        ) !== null;
    }

    /**
     * Guarda un premio nuevo con un código aleatorio ("NAV-K7M2QX") que vence en $validDays días.
     * @return array{code: string, label: string, expires: string}
     */
    public static function create(User $user, string $game, string $label, int $score, int $validDays): array
    {
        $expires = strtotime("+{$validDays} days");
        $code = 'NAV-';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        Database::execute(
            'INSERT INTO game_rewards (user_id, game, code, label, score, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$user->id, $game, $code, $label, $score, date('Y-m-d H:i:s', $expires)]
        );
        return ['code' => $code, 'label' => $label, 'expires' => date('d/m', $expires)];
    }
}
