<?php
/** Turno próximo e historial (iguales para todos los usuarios de prueba). Lo lee Models\Appointment. */
return [
    'next' => ['service' => 'Corte + barba', 'barber' => 'Nico Ledesma', 'barber_photo' => '/img/barberos/nico.webp', 'day' => 'Jueves 9', 'time' => '18:30'],
    'history' => [
        ['service' => 'Fade degradé',     'barber' => 'Nico Ledesma', 'date' => '12 sep', 'price' => 14000],
        ['service' => 'Corte + barba',    'barber' => 'Lucho Paz',    'date' => '20 ago', 'price' => 18500],
        ['service' => 'Barba con toalla', 'barber' => 'Fran Ibarra',  'date' => '29 jul', 'price' => 9000],
    ],
];
