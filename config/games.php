<?php
/**
 * config/games.php — Minijuegos de la pantalla de inicio y la promoción que da cada uno.
 *
 * - reward:      si el juego da cupón; los demás solo suman puntos (sin target ni promo).
 * - target:      puntaje que hay que alcanzar para ganar (el servidor lo vuelve a validar).
 * - min_seconds: tiempo mínimo creíble para llegar a ese puntaje; menos que eso se rechaza como trampa.
 * - promo:       texto del premio que se muestra al cliente y se guarda en game_rewards.
 * - Un cliente gana como máximo un premio por día y cada premio vence a los `valid_days` días.
 */
return [
    'valid_days' => 14,
    'games' => [
        'flappy' => [
            'name'   => 'Tijera voladora',
            'goal'   => 'Pasá entre los peines. ¡Sumá todos los puntos que puedas!',
            'reward' => false,
        ],
        'runner' => [
            'name'   => 'Barbero corredor',
            'goal'   => 'Saltá los obstáculos. ¡Sumá todos los puntos que puedas!',
            'reward' => false,
        ],
        'pacman' => [
            'name'        => 'Pac-Barber',
            'goal'        => 'Pac-Man original: limpiá el laberinto con 3 vidas para ganar el cupón.',
            'reward'      => true,
            'target'      => 2600, // mínimo al limpiar el nivel 1: 240 puntos x 10 + 4 pelotas x 50
            'min_seconds' => 45,
            'promo'       => '30% OFF en Corte + Barba',
        ],
    ],
];
