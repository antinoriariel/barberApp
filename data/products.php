<?php
/**
 * Productos a la venta en el local (catálogo estático: no se reservan ni se compran online, se muestran
 * en la pestaña Productos de la pantalla Servicios). 'price' en pesos enteros. Lo lee Models\Product.
 * Para agregar uno, sumar una fila con un 'id' único.
 */
return [
    ['id' => 'cera',  'name' => 'Cera mate',            'description' => 'Fijación media, acabado natural sin brillo.',  'price' => 8500],
    ['id' => 'gel',   'name' => 'Gel fijación fuerte',  'description' => 'Peinados firmes todo el día, no deja caspa.', 'price' => 5500],
    ['id' => 'polvo', 'name' => 'Polvo de peinar',      'description' => 'Da volumen y textura desde la raíz.',         'price' => 9500],
];
