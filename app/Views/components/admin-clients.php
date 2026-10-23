<?php
/**
 * components/admin-clients.php — Tarjeta plegable con la dona de clientes del mes (nuevos en latón, recurrentes en
 * gris; en el centro el total y el % de nuevos) y el top 3 de clientes por lo gastado en el mes.
 * La dona es SVG puro (la CSP no deja cargar librerías de gráficos de un CDN): dos arcos sobre un círculo con
 * pathLength=100, así el largo de cada trazo es directamente su porcentaje.
 * Arriba a la derecha hay un botón para minimizar (js/collapse.js, recuerda el estado con la clave $id).
 * Variables: $clients (Dashboard::clients), $id (id único del título, también clave del estado), $title
 */
$total = $clients['total'];
$newPct = $total ? (int) round($clients['new'] / $total * 100) : 0;
$oldPct = $total ? 100 - $newPct : 0;
$body = $id . '-body';
$medals = ['1°', '2°', '3°'];
?>
<section class="admin-card collapsible" aria-labelledby="<?= e($id) ?>" data-collapse-key="<?= e($id) ?>">
  <div class="admin-card-head">
    <h2 id="<?= e($id) ?>"><?= e($title) ?></h2>
    <button type="button" class="card-toggle" aria-expanded="true" aria-controls="<?= e($body) ?>" aria-label="Minimizar gráfico" title="Minimizar">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 15l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
  </div>
  <div class="admin-card-body clients" id="<?= e($body) ?>">
    <div class="clients-chart">
      <figure class="donut" role="img" aria-label="<?= e($clients['new'] . ' clientes nuevos y ' . $clients['returning'] . ' recurrentes') ?>">
        <svg viewBox="0 0 120 120" aria-hidden="true">
          <circle class="donut-track" cx="60" cy="60" r="48" pathLength="100"/>
<?php if ($oldPct > 0): ?>
          <circle class="donut-old" cx="60" cy="60" r="48" pathLength="100" stroke-dasharray="<?= $oldPct ?> <?= 100 - $oldPct ?>" stroke-dashoffset="<?= -$newPct ?>"/>
<?php endif; ?>
<?php if ($newPct > 0): ?>
          <circle class="donut-new" cx="60" cy="60" r="48" pathLength="100" stroke-dasharray="<?= $newPct ?> <?= 100 - $newPct ?>"/>
<?php endif; ?>
        </svg>
        <figcaption class="donut-center">
          <b><?= $total ?></b>
          <span><?= $total === 1 ? 'cliente' : 'clientes' ?></span>
        </figcaption>
      </figure>
      <ul class="donut-legend">
        <li><span class="sw new"></span>Nuevos <b><?= $clients['new'] ?></b> <small><?= $newPct ?>%</small></li>
        <li><span class="sw old"></span>Recurrentes <b><?= $clients['returning'] ?></b> <small><?= $oldPct ?>%</small></li>
      </ul>
    </div>

    <h3 class="clients-top-title">Top 3 clientes del mes</h3>
<?php if (!$clients['top']): ?>
    <p class="kpi-prev">Todavía no hay turnos atendidos este mes.</p>
<?php else: ?>
    <ol class="clients-top">
<?php foreach ($clients['top'] as $i => $c): ?>
      <li>
        <span class="rank rank-<?= $i + 1 ?>"><?= $medals[$i] ?? ($i + 1) . '°' ?></span>
        <span class="who"><?= e($c['name']) ?><?php if ($c['isNew']): ?> <small class="tag-new">nuevo</small><?php endif; ?>
          <small><?= $c['visits'] ?> <?= $c['visits'] === 1 ? 'visita' : 'visitas' ?></small></span>
        <b class="num"><?= money($c['spent']) ?></b>
      </li>
<?php endforeach; ?>
    </ol>
<?php endif; ?>
  </div>
</section>
