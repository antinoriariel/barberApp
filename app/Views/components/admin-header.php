<?php
/**
 * components/admin-header.php — Cabecera del panel: título, subtítulo, selector de tema claro/oscuro, cierre de sesión
 * y pestañas de las vistas. El botón de tema lo conecta js/admin.js (que AdminController carga en todas las vistas).
 * Variables: $heading (string), $sub (string),
 *            $active ('resumen'|'turnos'|'fila'|'barberos'|'finanzas'|'ingresos'|'egresos'|'servicios'|'usuarios'),
 *            $sections (string[]: secciones que la cuenta puede ver, User::panelSections(); solo esas pestañas se muestran)
 */
$tabs = [
    'resumen'   => ['/admin', 'Resumen'],
    'turnos'    => ['/admin/turnos', 'Turnos'],
    'fila'      => ['/admin/fila', 'Fila'],
    'barberos'  => ['/admin/barberos', 'Barberos'],
    'finanzas'  => ['/admin/finanzas', 'Finanzas'],
    'ingresos'  => ['/admin/ingresos', 'Ingreso extra'],
    'egresos'   => ['/admin/egresos', 'Egresos'],
    'servicios' => ['/admin/servicios', 'Servicios'],
    'usuarios'  => ['/admin/usuarios', 'Usuarios'],
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
      <?php // Un barbero (sin la pestaña Usuarios, exclusiva del admin) también usa la app de clientes: le dejamos volver. ?>
      <?php if (!isset($tabs['usuarios'])): ?><a class="btn-small ghost" href="/">Ir a la app</a><?php endif; ?>
      <button class="btn-small ghost" type="submit">Cerrar sesión</button>
    </form>
  </div>
</header>
<nav class="admin-tabs" aria-label="Secciones del panel">
<?php foreach ($tabs as $key => [$href, $label]): ?>
  <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
</nav>
