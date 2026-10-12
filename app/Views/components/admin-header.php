<?php
/**
 * components/admin-header.php — Cabecera del panel: título, subtítulo, selector de tema claro/oscuro, cierre de sesión
 * y pestañas de las vistas. El botón de tema lo conecta js/admin.js (que AdminController carga en todas las vistas).
 * Variables: $heading (string), $sub (string),
 *            $active ('semana'|'resumen'|'turnos'|'fila'|'barberos'|'finanzas'|'ingresos'|'egresos'|'servicios'|'usuarios',
 *                     o en el panel del superusuario 'su'|'su-seguridad'|'su-auditoria'),
 *            $sections (string[]: secciones que la cuenta puede ver, User::panelSections(); solo esas pestañas se muestran)
 */
$tabs = [
    'semana'    => ['/admin/mi-semana', 'Mi semana'],
    'resumen'   => ['/admin', 'Resumen'],
    'turnos'    => ['/admin/turnos', 'Turnos'],
    'fila'      => ['/admin/fila', 'Fila'],
    'barberos'  => ['/admin/barberos', 'Barberos'],
    'finanzas'  => ['/admin/finanzas', 'Finanzas'],
    'ingresos'  => ['/admin/ingresos', 'Ingreso extra'],
    'egresos'   => ['/admin/egresos', 'Egresos'],
    'servicios' => ['/admin/servicios', 'Servicios'],
    'usuarios'  => ['/admin/usuarios', 'Usuarios'],
    // Pestañas del superusuario (las vistas de /su pasan estas claves en $sections).
    'su'           => ['/su', 'Cuentas'],
    'su-seguridad' => ['/su/seguridad', 'Seguridad'],
    'su-auditoria' => ['/su/auditoria', 'Auditoría'],
];
$tabs = array_intersect_key($tabs, array_flip($sections ?? []));
?>
<header class="admin-head">
  <div>
    <h1><?= e($heading) ?></h1>
    <p class="admin-sub"><?= e($sub) ?></p>
  </div>
  <div class="admin-head-end">
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Cambiar a modo claro" title="Cambiar tema">
      <?= icon('sun', 'theme-ic-sun') ?><?= icon('moon', 'theme-ic-moon') ?>
    </button>
    <form method="post" action="/logout" class="inline-form">
      <?= csrf_field() ?>
      <button class="btn-small ghost" type="submit">Cerrar sesión</button>
    </form>
  </div>
</header>
<?php // Con una sola sección (un barbero que solo tiene "Mi semana") las pestañas no aportan nada: no se muestran. ?>
<?php if (count($tabs) > 1): ?>
<nav class="admin-tabs" aria-label="Secciones del panel">
<?php foreach ($tabs as $key => [$href, $label]): ?>
  <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
