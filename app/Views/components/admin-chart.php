<?php
/**
 * components/admin-chart.php — Barras agrupadas por mes: ingresos (turnos en rojo, con el ingreso extra apilado
 * encima en rojo claro) contra egresos (gris). La altura de la barra de ingresos es turnos + extra, y el número
 * de arriba es ese total.
 * Variables: $finance (Dashboard::finance)
 */
$incomes = array_map(static fn (array $m) => $m['revenue'] + $m['extra'], $finance);
$max = max(1, ...$incomes, ...array_column($finance, 'expenses'));
?>
<ul class="chart" aria-label="Ingresos y egresos de los últimos meses">
<?php foreach ($finance as $i => $m):
    $income = $incomes[$i]; ?>
  <li>
    <span class="chart-val" title="<?= e(money($income)) ?>"><?= e(money_short($income)) ?></span>
    <span class="chart-bars" title="Turnos <?= e(money($m['revenue'])) ?> · Extra <?= e(money($m['extra'])) ?> · Egresos <?= e(money($m['expenses'])) ?>">
      <span class="inc" style="height: <?= (int) round($income / $max * 100) ?>%">
        <i class="extra" style="height: <?= $income > 0 ? (int) round($m['extra'] / $income * 100) : 0 ?>%"></i>
        <i class="rev"></i>
      </span>
      <i class="exp" style="height: <?= (int) round($m['expenses'] / $max * 100) ?>%"></i>
    </span>
    <span class="chart-name"><?= e(mb_substr(month_es((int) substr($m['month'], 5)), 0, 3)) ?></span>
  </li>
<?php endforeach; ?>
</ul>
<p class="chart-legend"><span class="rev"></span>Turnos <span class="extra"></span>Extra <span class="exp"></span>Egresos</p>
