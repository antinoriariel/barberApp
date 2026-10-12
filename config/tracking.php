<?php
/**
 * config/tracking.php — Catálogo del uso de la interfaz (mapa de calor del superusuario, /su/uso).
 *
 * Cada elemento que se mide lleva en la vista un atributo data-track="<código>" (y, si es uno de una lista,
 * data-track-item="<cuál>"). track.js manda el código; acá cada código tiene su nombre legible para el panel.
 * Convención: "<zona>.<elemento>", en minúsculas y sin espacios. Un click en algo sin data-track igual se guarda:
 *  - "auto:<id|tab|nombre|clase>" si cayó en un elemento interactivo sin código (hay que agregarle uno);
 *  - "fondo" si cayó en una zona que no hace nada (click "muerto": la persona creyó que se podía tocar).
 * Las taps dentro del lienzo de los juegos no se miden (data-track-ignore): son jugadas, no navegación; el tiempo
 * de juego tiene su propio panel (/su/juegos).
 *
 * - screens:    pantallas de la app (las <section class="view" id="view-…">), en el orden en que se listan.
 * - components: código => nombre para el panel.
 * - retention:  antigüedades (días) que el superusuario puede borrar desde /su/uso.
 * - limits:     topes por lote y por minuto que acepta POST /api/uso (más que eso se descarta).
 */
return [
    'screens' => [
        'inicio'    => 'Inicio',
        'servicios' => 'Servicios',
        'reservar'  => 'Reservar',
        'barberos'  => 'Barberos',
        'perfil'    => 'Perfil',
    ],

    'components' => [
        // Barra superior (fija).
        'topbar.logo'   => 'Barra superior · logo (vuelve a Inicio)',
        'topbar.estado' => 'Barra superior · abierto/cerrado',
        'topbar.tema'   => 'Barra superior · tema claro/oscuro',
        // Barra inferior (fija).
        'tabbar.inicio'    => 'Barra inferior · Inicio',
        'tabbar.servicios' => 'Barra inferior · Servicios',
        'tabbar.qr'        => 'Barra inferior · Tomar número (QR)',
        'tabbar.barberos'  => 'Barra inferior · Barberos',
        'tabbar.perfil'    => 'Barra inferior · Perfil',
        // Lector de QR (diálogo).
        'scanner.camara' => 'Lector QR · cámara',
        'scanner.salir'  => 'Lector QR · salir',
        // Inicio.
        'inicio.avatar'      => 'Inicio · avatar (va a Perfil)',
        'inicio.saludo'      => 'Inicio · saludo',
        'fila.tarjeta'       => 'Inicio · número de la fila',
        'fila.salir'         => 'Inicio · dejar la fila',
        'turno.tarjeta'      => 'Inicio · tarjeta del turno',
        'turno.reservar'     => 'Inicio · reservar turno (sin turno)',
        'turno.reprogramar'  => 'Inicio · reprogramar turno',
        'turno.cancelar'     => 'Inicio · cancelar turno',
        'inicio.fidelidad'   => 'Inicio · tarjeta de fidelidad',
        'inicio.ver-todos'   => 'Inicio · ver todos los servicios',
        'inicio.popular'     => 'Inicio · servicio más pedido',
        'juegos.desplegar'   => 'Inicio · abrir/cerrar minijuegos',
        'juegos.jugar'       => 'Inicio · elegir minijuego',
        'juegos.premios'     => 'Inicio · mis premios',
        'juegos.salir'       => 'Minijuego · salir',
        'juegos.reintentar'  => 'Minijuego · jugar de nuevo',
        'juegos.ranking'     => 'Minijuego · ranking',
        'inicio.local'       => 'Inicio · dirección y horarios',
        // Servicios.
        'servicios.tab-servicios' => 'Servicios · pestaña Servicios',
        'servicios.tab-productos' => 'Servicios · pestaña Productos',
        'servicios.reservar'      => 'Servicios · reservar servicio',
        'servicios.producto'      => 'Servicios · producto',
        // Reservar.
        'reservar.servicio'          => 'Reservar · servicio',
        'reservar.barbero'           => 'Reservar · barbero',
        'reservar.dia'               => 'Reservar · día',
        'reservar.horario'           => 'Reservar · horario',
        'reservar.confirmar'         => 'Reservar · confirmar turno',
        'reservar.resumen'           => 'Reservar · resumen',
        'reservar.dejar-reprogramar' => 'Reservar · dejar de reprogramar',
        // Barberos.
        'barberos.tarjeta'  => 'Barberos · tarjeta',
        'barberos.reservar' => 'Barberos · reservar con…',
        // Perfil.
        'perfil.datos'         => 'Perfil · datos de la cuenta',
        'perfil.historial'     => 'Perfil · últimas visitas',
        'perfil.panel'         => 'Perfil · panel / mi semana',
        'perfil.avisos'        => 'Perfil · avisos de turno',
        'perfil.cerrar-sesion' => 'Perfil · cerrar sesión',
        'perfil.cerrar-todo'   => 'Perfil · cerrar sesión en todos lados',
        // Sin código.
        'fondo' => 'Zona sin acción (click muerto)',
    ],

    'retention' => [30, 90, 180],

    'limits' => [
        'clicks'      => 200,   // clicks por lote
        'moves'       => 2000,  // celdas de mouse por lote
        'batches'     => 30,    // lotes por minuto y por sesión
        'body_bytes'  => 65536, // tamaño máximo del JSON
    ],
];
