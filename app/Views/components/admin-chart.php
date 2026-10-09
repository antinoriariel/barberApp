<?php
/**
 * components/admin-chart.php — Barras agrupadas de ingresos (rojo) y egresos (gris) por mes.
 * Variables: $finance (Dashboard::finance)
 */
$max = max(1, ...array_column($finance, 'revenue'), ...array_column($finance, 'expenses'));
?>
<ul class="chart" aria-label="Ingresos y egresos de los últimos meses">
<?php foreach ($finance as $m): ?>
  <li>
    <span class="chart-val" title="<?= e(money($m['revenue'])) ?>"><?= e(money_short($m['revenue'])) ?></span>
    <span class="chart-bars" title="Ingresos <?= e(money($m['revenue'])) ?> · Egresos <?= e(money($m['expenses'])) ?>">
      <i class="rev" style="height: <?= (int) round($m['revenue'] / $max * 100) ?>%"></i>
      <i class="exp" style="height: <?= (int) round($m['expenses'] / $max * 100) ?>%"></i>
    </span>
    <span class="chart-name"><?= e(mb_substr(month_es((int) substr($m['month'], 5)), 0, 3)) ?></span>
  </li>
<?php endforeach; ?>
</ul>
<p class="chart-legend"><span class="rev"></span>Ingresos <span class="exp"></span>Egresos</p>
