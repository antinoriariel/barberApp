<?php
/**
 * pages/admin.php — Resumen del administrador: cortes e ingresos del día (vs el día anterior), ingresos, egresos
 * y resultado del mes, gráfico de 6 meses y desempeño de cada barbero en el día.
 *
 * Variables: $day/$prev/$next (YYYY-MM-DD), $isToday, $today/$yesterday (Dashboard::day), $dayExpenses (egresos del día),
 *            $dayNet (ingresos − egresos del día), $revenueDelta/$servedDelta/
 *            $monthDelta (Dashboard::delta), $verdict (string), $finance (Dashboard::finance, el último es el mes del día)
 */
[$revClass, $revText] = delta_text($revenueDelta, true, 'ayer');
[$cntClass, $cntText] = delta_text($servedDelta, false, 'ayer');
$cur = $finance[5];
$old = $finance[4];
[$monClass, $monText] = delta_text($monthDelta, true, month_es((int) substr($old['month'], 5)));
$maxRevenue = max(1, ...array_column($today['barbers'], 'revenue'));
$before = array_column($yesterday['barbers'], null, 'id');
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Panel', 'sub' => ucfirst(date_es($day)) . ($isToday ? ' · hoy' : ''), 'active' => 'resumen']) ?>

  <nav class="admin-nav" aria-label="Cambiar de día">
    <a class="btn-small" href="/admin?fecha=<?= e($prev) ?>">← Anterior</a>
    <?php if (!$isToday): ?><a class="btn-small" href="/admin">Hoy</a><?php endif; ?>
    <a class="btn-small" href="/admin?fecha=<?= e($next) ?>">Siguiente →</a>
  </nav>

  <p class="admin-verdict"><?= e($verdict) ?></p>

  <section class="admin-kpis" aria-label="Totales del día">
    <article class="kpi">
      <h2>Cortes del día</h2>
      <p class="kpi-value"><?= $today['served'] ?></p>
      <p class="kpi-delta <?= e($cntClass) ?>"><?= e($cntText) ?></p>
      <p class="kpi-prev">Ticket promedio: <?= money($today['ticket']) ?></p>
    </article>
    <article class="kpi">
      <h2>Ingresos del día</h2>
      <p class="kpi-value"><?= money($today['total']) ?></p>
      <p class="kpi-delta <?= e($revClass) ?>"><?= e($revText) ?></p>
      <p class="kpi-prev">Día anterior: <?= money($yesterday['total']) ?></p>
    </article>
    <article class="kpi">
      <h2>Egresos del día</h2>
      <p class="kpi-value"><?= money($dayExpenses) ?></p>
      <p class="kpi-prev"><a class="link" href="/admin/egresos?mes=<?= e(substr($day, 0, 7)) ?>">Agregar gasto</a></p>
    </article>
    <article class="kpi">
      <h2>Resultado del día</h2>
      <p class="kpi-value <?= $dayNet < 0 ? 'neg' : '' ?>"><?= $dayNet < 0 ? '−' . money(-$dayNet) : money($dayNet) ?></p>
      <p class="kpi-prev">Ingresos menos egresos</p>
    </article>
  </section>

  <section class="admin-kpis" aria-label="Totales del mes">
    <article class="kpi">
      <h2>Ingresos de <?= e(month_es((int) substr($cur['month'], 5))) ?></h2>
      <p class="kpi-value"><?= money($cur['revenue']) ?></p>
      <p class="kpi-delta <?= e($monClass) ?>"><?= e($monText) ?></p>
      <p class="kpi-prev"><?= $cur['served'] ?> cortes atendidos en el mes</p>
    </article>
    <article class="kpi">
      <h2>Egresos del mes</h2>
      <p class="kpi-value"><?= money($cur['expenses']) ?></p>
      <p class="kpi-prev"><a class="link" href="/admin/egresos?mes=<?= e($cur['month']) ?>">Cargar o ver egresos</a></p>
    </article>
    <article class="kpi">
      <h2>Resultado del mes</h2>
      <p class="kpi-value <?= $cur['net'] < 0 ? 'neg' : '' ?>"><?= $cur['net'] < 0 ? '−' . money(-$cur['net']) : money($cur['net']) ?></p>
      <p class="kpi-prev">Ingresos menos egresos</p>
    </article>
  </section>

  <section class="admin-card" aria-labelledby="by-month">
    <h2 id="by-month">Ingresos y egresos · últimos 6 meses</h2>
    <?= partial('components/admin-chart', ['finance' => $finance]) ?>
  </section>

  <section class="admin-card" aria-labelledby="by-barber">
    <h2 id="by-barber">Por barbero · <?= e(date_es($day)) ?></h2>
    <div class="table-wrap">
      <?php // .stack: en celular cada barbero es una tarjeta (data-label = título de la columna); desde 720px, tabla. ?>
      <table class="admin-table stack">
        <thead>
          <tr><th>Barbero</th><th class="num">Cortes</th><th class="num">Ingresos</th><th class="num">Ayer</th><th class="num">Variación</th></tr>
        </thead>
        <tbody>
<?php foreach ($today['barbers'] as $b):
    $p = $before[$b['id']] ?? ['served' => 0, 'revenue' => 0];
    [$bc, $bt] = delta_text(\App\Models\Dashboard::delta($b['revenue'], $p['revenue']), true, 'ayer'); ?>
          <tr>
            <th scope="row" class="cell-main">
              <span class="dot" style="--tone: <?= e(css_color($b['tone'])) ?>"></span><?= e($b['name']) ?>
              <span class="bar" aria-hidden="true"><i style="width: <?= (int) round($b['revenue'] / $maxRevenue * 100) ?>%"></i></span>
            </th>
            <td class="num" data-label="Cortes"><?= $b['served'] ?> <small>(ayer <?= $p['served'] ?>)</small></td>
            <td class="num" data-label="Ingresos"><b><?= money($b['revenue']) ?></b></td>
            <td class="num" data-label="Ayer"><?= money($p['revenue']) ?></td>
            <td class="num kpi-delta <?= e($bc) ?>" data-label="Variación"><?= e($bt) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
