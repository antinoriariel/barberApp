<?php
/**
 * Usuarios de prueba (hardcodeados). Los lee Models\User y los siembra bin/seed.php.
 * La clave es el DNI (solo dígitos), lo único que se pide para iniciar sesión.
 *
 * Claves opcionales de rol (solo con base de datos):
 *  - 'admin'  => true     cuenta administradora (entra al panel /admin).
 *  - 'barber' => '<slug>' cuenta de barbero, vinculada a esa ficha de data/barbers.php.
 * Sin ninguna de las dos es un cliente. Las cuentas de personal no reciben turnos de ejemplo.
 * La lista completa para probar está en users.md.
 */
return [
    '40123456' => ['name' => 'Mateo',  'full' => 'Mateo Fernández', 'email' => 'mateoFnz@gmail.com',  'visits' => 7],
    '35987654' => ['name' => 'Camila', 'full' => 'Camila Rojas',    'email' => 'camila@correo.com', 'visits' => 10],
    '33202388' => ['name' => 'Andres', 'full' => 'Andres Sena',      'email' => 'admin@navaja.test', 'visits' => 0, 'admin' => true],
    '32333444' => ['name' => 'Nico',   'full' => 'Nico Ledesma',    'email' => 'nico@navaja.test',  'visits' => 0, 'barber' => 'nico'],
];
