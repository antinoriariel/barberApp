<?php
/**
 * config/games.php — Minijuegos de la pantalla de inicio y la promoción que da cada uno.
 *
 * - reward:      si el juego da cupón; los demás solo suman puntos (sin target ni promo).
 * - target:      puntaje que hay que alcanzar para ganar (el servidor lo vuelve a validar).
 * - min_seconds: tiempo mínimo creíble para llegar a ese puntaje; menos que eso se rechaza como trampa.
 * - promo:       texto del premio que se muestra al cliente y se guarda en game_rewards.
 * - Un cliente gana como máximo un premio por día y cada premio vence a los `valid_days` días.
 *
 * Límites anti-trampa del ranking (Models\GameSession::plausible; el servidor mide la duración con su reloj):
 * - max_score: puntaje máximo posible en una partida.
 * - rate / burst: un puntaje es creíble si score <= burst + rate × segundos jugados (rate = puntos por segundo
 *   que da el juego a toda velocidad; burst = lo que se puede sumar de golpe, p. ej. comer fantasmas).
 * - step: el puntaje tiene que ser múltiplo de este número.
 * Además: `ranking_size` (cuántos muestra el top) y `max_session` (segundos; una partida más larga o abandonada
 * se cuenta como mucho por ese tiempo).
 */
return [
    'valid_days'   => 14,
    'ranking_size' => 6,
    'max_session'  => 3600,
    'games' => [
        'flappy' => [
            'name'   => 'Tijera voladora',
            'goal'   => 'Pasá entre los peines. ¡Sumá todos los puntos que puedas!',
            'reward' => false,
            // 68 px/s entre peines separados 98 px: ~0,7 peines por segundo; el primero llega a los ~2 s.
            'max_score' => 100000, 'rate' => 0.75, 'burst' => 2, 'step' => 1,
        ],
        'runner' => [
            'name'   => 'Barbero corredor',
            'goal'   => 'Saltá los obstáculos. ¡Sumá todos los puntos que puedas!',
            'reward' => false,
            // Un punto cada 7 px; la velocidad tope es 270 px/s (≈ 38,6 puntos por segundo).
            'max_score' => 1000000, 'rate' => 39, 'burst' => 20, 'step' => 1,
        ],
        'pacman' => [
            'name'        => 'Pac-Barber',
            'goal'        => 'Pac-Man original: limpiá el laberinto con 3 vidas para ganar el cupón.',
            'reward'      => true,
            'target'      => 2600, // mínimo al limpiar el nivel 1: 240 puntos x 10 + 4 pelotas x 50
            'min_seconds' => 45,
            'promo'       => '30% OFF en Corte + Barba',
            // Un solo nivel: 240 x 10 + 4 x 50 + 4 x (200+400+800+1600) + 2 frutas x 100 = 14.800.
            // Las pastillas a ~7,6 casillas/s dan ≤ 80 pts/s; fantasmas, frutas y pelotas suman de golpe (burst).
            'max_score' => 14800, 'rate' => 80, 'burst' => 12400, 'step' => 10,
        ],
    ],
];
