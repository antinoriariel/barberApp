<?php
/**
 * pages/admin-barberos.php — Cortes atendidos por barbero, día por día, en la semana (lunes a domingo).
 *
 * Variables: $week (Dashboard::week), $prev/$next (YYYY-MM-DD: semana anterior/siguiente), $isCurrent (bool)
 */
$dayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
$maxTotal = max(1, ...array_column($week['barbers'], 'total'));
$range = date('j', strtotime($week['from'])) . ' de ' . month_es((int) date('n', strtotime($week['from'])))
    . ' al ' . date('j', strtotime($week['to'])) . ' de ' . month_es((int) date('n', strtotime($week['to'])));
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Barberos', 'sub' => 'Semana del ' . $range . ($isCurrent ? ' · esta semana' : ''), 'active' => 'barberos']) ?>

  <nav class="admin-nav" aria-label="Cambiar de semana">
    <a class="btn-small" href="/admin/barberos?fecha=<?= e($prev) ?>">← Semana anterior</a>
    <?php if (!$isCurrent): ?><a class="btn-small" href="/admin/barberos">Esta semana</a><?php endif; ?>
    <a class="btn-small" href="/admin/barberos?fecha=<?= e($next) ?>">Semana siguiente →</a>
  </nav>

  <section class="admin-kpis" aria-label="Total de la semana">
    <article class="kpi">
      <h2>Cortes de la semana</h2>
      <p class="kpi-value"><?= $week['total'] ?></p>
      <p class="kpi-prev">Turnos atendidos entre todos los barberos</p>
    </article>
  </section>

  <section class="admin-card" aria-labelledby="week-cuts">
    <h2 id="week-cuts">Cortes por barbero</h2>
    <div class="table-wrap">
      <?php // tabla ancha (7 días): se desliza de costado y la columna del barbero queda fija a la izquierda ?>
      <table class="admin-table sticky-first">
        <thead>
          <tr>
            <th>Barbero</th>
<?php foreach ($week['days'] as $i => $d): ?>
            <th class="num"><?= e($dayNames[$i]) ?> <small><?= date('j', strtotime($d)) ?></small></th>
<?php endforeach; ?>
            <th class="num">Total</th><th class="num">Ingresos</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($week['barbers'] as $b): ?>
          <tr>
            <th scope="row">
              <span class="dot" style="--tone: <?= e(css_color($b['tone'])) ?>"></span><?= e($b['name']) ?>
              <span class="bar" aria-hidden="true"><i style="width: <?= (int) round($b['total'] / $maxTotal * 100) ?>%"></i></span>
            </th>
<?php foreach ($b['perDay'] as $n): ?>
            <td class="num<?= $n === 0 ? ' zero' : '' ?>"><?= $n ?></td>
<?php endforeach; ?>
            <td class="num"><b><?= $b['total'] ?></b></td>
            <td class="num"><?= money($b['revenue']) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
