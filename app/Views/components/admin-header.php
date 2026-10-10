<?php
/**
 * components/admin-header.php — Cabecera del panel: título, subtítulo y pestañas de las vistas.
 * Variables: $heading (string), $sub (string), $active ('resumen'|'turnos'|'fila'|'barberos'|'finanzas'|'egresos'|'servicios')
 *
 * Incluye el botón de modo claro/oscuro y carga js/admin.js, que lo conecta: como todas las vistas del panel
 * usan esta cabecera, el selector de tema está en todas sin tocar cada controlador.
 */
$tabs = [
    'resumen'   => ['/admin', 'Resumen'],
    'turnos'    => ['/admin/turnos', 'Turnos'],
    'fila'      => ['/admin/fila', 'Fila'],
    'barberos'  => ['/admin/barberos', 'Barberos'],
    'finanzas'  => ['/admin/finanzas', 'Finanzas'],
    'egresos'   => ['/admin/egresos', 'Egresos'],
    'servicios' => ['/admin/servicios', 'Servicios'],
];
?>
<header class="admin-head">
  <div>
    <h1><?= e($heading) ?></h1>
    <p class="admin-sub"><?= e($sub) ?></p>
  </div>
  <div class="admin-head-actions">
    <button type="button" class="admin-theme-toggle" id="theme-toggle" aria-label="Cambiar a modo claro" title="Cambiar tema">
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
<script type="module" src="<?= e(asset('js/admin.js')) ?>"></script>
