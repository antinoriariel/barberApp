<?php
/**
 * config/app.php — Configuración general de la aplicación.
 * Se lee con Config::get('clave.anidada'), p. ej. Config::get('app.name').
 */
return [
    'app' => [
        'name'             => 'Barbería',
        'lang'             => 'es-AR',
        'theme_color'      => '#111111',
        'background_color' => '#111111',
        'description'      => 'Reservá tu turno en la barbería.',
    ],

    // Modo depuración: SOLO en desarrollo (APP_DEBUG=1). En producción los errores se registran
    // en el log de PHP y el usuario ve una página 500 genérica, sin rastros ni rutas internas.
    'debug' => getenv('APP_DEBUG') === '1',

    // Zona horaria del local: PHP y la conexión a la base usan la misma, para que Schedule y los DATETIME coincidan.
    'timezone' => 'America/Argentina/Buenos_Aires',

    // Base de datos (ver config/database.php y docs/plan-base-de-datos.md).
    'database' => require __DIR__ . '/database.php',

    // URL pública base (la usa el QR de la fila). Fijarla en producción: no se toma del encabezado Host.
    'url' => getenv('APP_URL') ?: 'http://localhost:8000',

    // Cuentas: contraseña mínima y contraseña de las cuentas de demostración que crea bin/seed.php
    // (cambiarla o borrar esas cuentas antes de producción).
    'auth' => [
        'min_password'  => 8,
        'demo_password' => getenv('DEMO_PASSWORD') ?: 'Navaja2026!',
    ],

    // Soporte: "Olvidé mi contraseña" abre un chat de WhatsApp con este número (internacional, solo dígitos)
    // y este mensaje ya escrito. La contraseña nueva la pone el administrador desde /admin/usuarios.
    'support' => [
        'whatsapp'       => getenv('SUPPORT_WHATSAPP') ?: '5493781401440',
        'forgot_message' => 'Olvidé mi contraseña de la app de barbería, solicito asistencia',
    ],

    // Reservas: cuántos días hacia adelante se puede reservar, cuántos turnos futuros puede tener a la vez
    // un cliente, hora de cierre (un turno no puede terminar después) y días de la semana (date('w'), 3 = miércoles)
    // en que el local abre pero no toma turnos: se atiende por orden de llegada con el QR de la fila.
    'booking' => [
        'window_days'      => 30,
        'max_active'       => 3,
        'closing_time'     => '20:00',
        'walk_in_weekdays' => [3],
    ],

    // Fila por orden de llegada (QR del mostrador). 'code' va dentro del QR: sin él no se puede sacar número, así que
    // conviene cambiarlo en .env (QUEUE_CODE) y reimprimir el QR si alguien lo comparte fuera del local.
    'queue' => [
        'code' => getenv('QUEUE_CODE') ?: 'mostrador-navaja',
    ],

    // Visitas necesarias para que el corte sea gratis.
    'loyalty_goal' => 7,

    // Sesión: 30 días, para que la PWA instalada no pida login a cada rato.
    'session_lifetime' => 60 * 60 * 24 * 30,

    // Seguridad (ver docs/seguridad.md).
    'security' => [
        // Intentos de login fallidos permitidos por ventana, por IP y por DNI.
        // LOGIN_RATELIMIT=0 en .env lo apaga (solo para desarrollo local).
        'login_max_attempts_ip'  => getenv('LOGIN_RATELIMIT') === '0' ? PHP_INT_MAX : 10,
        'login_max_attempts_dni' => getenv('LOGIN_RATELIMIT') === '0' ? PHP_INT_MAX : 5,
        'login_window'           => 15 * 60, // segundos que dura el bloqueo
        // Intentos de alta de cuenta por IP, por ventana.
        'register_max_attempts_ip' => 5,
        // Cuentas creadas por IP cada 24 h (además del límite de una por navegador). Bajo para frenar spam, pero
        // no 1: en un wifi compartido o con la IP compartida de las compañías de celular puede haber varias personas.
        'register_max_ip_day'      => 3,
        // "Olvidé mi contraseña" (DNI + celular del registro): intentos fallidos por ventana, por IP y por DNI. Más
        // bajos que el login: el celular es el único dato que se verifica.
        'recover_max_attempts_ip'  => 5,
        'recover_max_attempts_dni' => 3,
        // HSTS (solo se envía bajo HTTPS): segundos que el navegador recordará usar solo HTTPS.
        'hsts_max_age'           => 60 * 60 * 24 * 180,
        // Segundos sin actividad tras los que la sesión del superusuario se cierra sola (2 horas).
        'superuser_idle'         => 2 * 60 * 60,
    ],

    'assets' => [
        // Big Shoulders (títulos) + Figtree (texto) + Cormorant Garamond itálica (subtítulos del panel).
        'fonts_url' => 'https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@700;800;900'
                     . '&family=Figtree:wght@400;500;600;700'
                     . '&family=Cormorant+Garamond:ital,wght@1,500;1,600&display=swap',

        // Hojas de estilo en orden de cascada (relativas a public/assets/).
        // Mobile first: una sola interfaz móvil (en PC se muestra centrada, con el ancho de un celular).
        'styles' => [
            'css/base.css',
            'css/layout.css',
            'css/components/button.css',
            'css/components/ticket.css',
            'css/components/loyalty.css',
            'css/components/rail.css',
            'css/components/menu.css',
            'css/components/services.css',
            'css/components/profile.css',
            'css/components/crew.css',
            'css/components/booking.css',
            'css/components/tabbar.css',
            'css/components/toast.css',
            'css/components/skeleton.css',
            'css/components/arcade.css',
            'css/components/queue.css',
            'css/components/dead-hour.css',
            'vendor/driver/driver.css', // driver.js (modo guía), servido localmente por la CSP
            'css/components/tour.css',
            'css/pages/login.css',
            'css/pages/admin.css',
            'css/pages/su.css',
            'css/pages/admin-skin.css', // piel del panel (fondo de ladrillo, vidrio, latón): después de admin.css y su.css
            'css/pages/error.css',
            'css/a11y.css',
        ],
    ],
];
