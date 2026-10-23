<?php
/**
 * components/admin-header.php — Cabecera del panel: sello de la marca (navaja de img/C.png + "BarberShop · Panel"), título,
 * subtítulo, selector de tema claro/oscuro, cierre de sesión y pestañas de las vistas, cada una con su ícono
 * (ICON_PATHS en helpers.php). Va sobre la foto de fondo del panel (admin.css), por eso su texto es siempre claro.
 * El botón de tema lo conecta js/admin.js (que AdminController carga en todas las vistas).
 * Variables: $heading (string), $sub (string),
 *            $active ('semana'|'resumen'|'turnos'|'hora-muerta'|'fila'|'barberos'|'finanzas'|'ingresos'|'egresos'|'servicios'|'usuarios',
 *                     o en el panel del superusuario 'su'|'su-registros'|'su-seguridad'|'su-auditoria'),
 *            $sections (string[]: secciones que la cuenta puede ver, User::panelSections(); solo esas pestañas se muestran)
 */
$tabs = [ // clave => [ruta, texto, ícono]
    'semana'      => ['/admin/mi-semana', 'Mi semana', 'calendar'],
    'resumen'     => ['/admin', 'Resumen', 'chart'],
    'turnos'      => ['/admin/turnos', 'Turnos', 'calendar'],
    'hora-muerta' => ['/admin/hora-muerta', 'Hora muerta', 'hourglass'],
    'fila'        => ['/admin/fila', 'Fila', 'ticket'],
    'barberos'    => ['/admin/barberos', 'Barberos', 'chair'],
    'finanzas'    => ['/admin/finanzas', 'Finanzas', 'wallet'],
    'ingresos'    => ['/admin/ingresos', 'Ingreso extra', 'jar'],
    'egresos'     => ['/admin/egresos', 'Egresos', 'receipt'],
    'servicios'   => ['/admin/servicios', 'Servicios', 'scissors'],
    'usuarios'    => ['/admin/usuarios', 'Usuarios', 'users'],
    // Pestañas del superusuario (las vistas de /su pasan estas claves en $sections).
    'su'           => ['/su', 'Cuentas', 'users'],
    'su-registros' => ['/su/registros', 'Registros', 'id'],
    'su-seguridad' => ['/su/seguridad', 'Seguridad', 'shield'],
    'su-auditoria' => ['/su/auditoria', 'Auditoría', 'list'],
];
$tabs = array_intersect_key($tabs, array_flip($sections ?? []));
?>
<header class="admin-head">
  <div>
    <p class="admin-brand"><span class="admin-brand-mark"><span class="admin-brand-logo"></span></span><span>BarberShop</span><span class="admin-brand-tag">Panel</span></p>
    <h1><?= e($heading) ?></h1>
    <p class="admin-sub"><?= e($sub) ?></p>
  </div>
  <div class="admin-head-end">
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Cambiar a modo claro" title="Cambiar tema">
      <?= icon('sun', 'theme-ic-sun') ?><?= icon('moon', 'theme-ic-moon') ?>
    </button>
    <form method="post" action="/logout" class="inline-form">
      <?= csrf_field() ?>
      <button class="btn-small ghost" type="submit" title="Cerrar sesión" aria-label="Cerrar sesión"><?= icon('logout') ?><span>Salir</span></button>
    </form>
  </div>
</header>
<?php // Con una sola sección (un barbero que solo tiene "Mi semana") las pestañas no aportan nada: no se muestran. ?>
<?php if (count($tabs) > 1): ?>
<nav class="admin-tabs" aria-label="Secciones del panel">
<?php foreach ($tabs as $key => [$href, $label, $ic]): ?>
  <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
