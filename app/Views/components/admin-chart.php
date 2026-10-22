<?php
/**
 * components/admin-chart.php — Tarjeta con barras agrupadas por mes: ingresos (turnos en rojo, con el ingreso extra
 * apilado encima en rojo claro) contra egresos (gris). La altura de la barra de ingresos es turnos + extra, y el
 * número de arriba es ese total.
 * Arriba a la derecha hay un botón para minimizar el gráfico (lo conecta js/collapse.js, que recuerda el estado por
 * tarjeta en localStorage con la clave $id).
 * Variables: $finance (Dashboard::finance), $id (id único del título, también clave del estado), $title
 */
$incomes = array_map(static fn (array $m) => $m['revenue'] + $m['extra'], $finance);
$max = max(1, ...$incomes, ...array_column($finance, 'expenses'));
$body = $id . '-body';
?>
<section class="admin-card collapsible" aria-labelledby="<?= e($id) ?>" data-collapse-key="<?= e($id) ?>">
  <div class="admin-card-head">
    <h2 id="<?= e($id) ?>"><?= e($title) ?></h2>
    <button type="button" class="card-toggle" aria-expanded="true" aria-controls="<?= e($body) ?>" aria-label="Minimizar gráfico" title="Minimizar">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 15l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
  </div>
  <div class="admin-card-body" id="<?= e($body) ?>">
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
  </div>
</section>
