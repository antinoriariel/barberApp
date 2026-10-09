<?php
/** Catálogo de servicios. 'min' = duración en minutos, 'price' = pesos argentinos. Lo lee Models\Service. */
return [
    ['id' => 'corte', 'name' => 'Corte clásico',      'desc' => 'Tijera y máquina, lavado y peinado.',      'min' => 40,  'price' => 12000],
    ['id' => 'barba', 'name' => 'Barba con toalla',   'desc' => 'Toalla caliente, navaja y aceite.',         'min' => 30,  'price' => 9000],
    ['id' => 'combo', 'name' => 'Corte + barba',      'desc' => 'El combo completo, un solo turno.',         'min' => 60,  'price' => 18500],
    ['id' => 'fade',  'name' => 'Fade degradé',       'desc' => 'Degradé a navaja con terminación prolija.', 'min' => 45,  'price' => 14000],
    ['id' => 'cejas', 'name' => 'Perfilado de cejas', 'desc' => 'Rápido y sin dolor.',                       'min' => 10,  'price' => 3500],
    ['id' => 'color', 'name' => 'Color o platinado',  'desc' => 'Decoloración y matiz. Incluye corte.',      'min' => 120, 'price' => 38000],
];
