<?php
/**
 * components/admin-header.php — Cabecera del panel: título, subtítulo y pestañas de las vistas.
 * Variables: $heading (string), $sub (string), $active ('resumen'|'turnos'|'fila'|'barberos'|'finanzas'|'ingresos'|'egresos'|'servicios')
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
];
?>
<header class="admin-head">
  <div>
    <h1><?= e($heading) ?></h1>
    <p class="admin-sub"><?= e($sub) ?></p>
  </div>
  <div class="admin-head-end">
    <?php // Mismo selector claro/oscuro que la cabecera de la app (lo conecta theme.js vía admin.js). ?>
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Cambiar a modo claro" title="Cambiar tema">
      <?= icon('sun', 'theme-ic-sun') ?><?= icon('moon', 'theme-ic-moon') ?>
    </button>
    <form method="post" action="/logout" class="inline-form">
      <?= csrf_field() ?>
      <button class="btn-small ghost" type="submit">Cerrar sesión</button>
    </form>
  </div>
</header>
<nav class="admin-tabs" aria-label="Secciones del panel">
<?php foreach ($tabs as $key => [$href, $label]): ?>
  <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
</nav>
