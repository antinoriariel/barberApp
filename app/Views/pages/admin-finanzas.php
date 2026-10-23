<?php
/**
 * pages/admin-finanzas.php — Ingresos (turnos atendidos) e ingresos extra contra egresos por mes; los gastos se
 * cargan en /admin/egresos y los ingresos extra en /admin/ingresos.
 *
 * Variables: $month ('YYYY-MM'), $prev/$next ('YYYY-MM'), $isCurrent, $finance (Dashboard::finance: el último es $month),
 *            $expenses (Expense::forMonth)
 */
$cur = $finance[5];
$net = $cur['net'];
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Finanzas', 'sub' => ucfirst(month_es((int) substr($month, 5))) . ' ' . substr($month, 0, 4) . ($isCurrent ? ' · mes en curso' : ''), 'sections' => $user->panelSections(), 'active' => 'finanzas']) ?>

  <nav class="admin-nav" aria-label="Cambiar de mes">
    <a class="btn-small" href="/admin/finanzas?mes=<?= e($prev) ?>">← Mes anterior</a>
    <?php if (!$isCurrent): ?><a class="btn-small" href="/admin/finanzas">Este mes</a><?php endif; ?>
    <a class="btn-small" href="/admin/finanzas?mes=<?= e($next) ?>">Mes siguiente →</a>
  </nav>

  <section class="admin-kpis" aria-label="Totales del mes">
    <article class="kpi">
      <span class="kpi-ic"><?= icon('trend-up') ?></span>
      <h2>Ingresos</h2>
      <p class="kpi-value"><?= money($cur['revenue']) ?></p>
      <p class="kpi-prev"><?= $cur['served'] ?> cortes atendidos</p>
    </article>
    <article class="kpi">
      <span class="kpi-ic"><?= icon('jar') ?></span>
      <h2>Ingresos extra</h2>
      <p class="kpi-value"><?= money($cur['extra']) ?></p>
      <p class="kpi-prev"><a class="link" href="/admin/ingresos?mes=<?= e($month) ?>">Cargar o ver ingresos extra</a></p>
    </article>
    <article class="kpi">
      <span class="kpi-ic"><?= icon('receipt') ?></span>
      <h2>Egresos</h2>
      <p class="kpi-value"><?= money($cur['expenses']) ?></p>
      <p class="kpi-prev"><?= count($expenses) ?> movimientos</p>
    </article>
    <article class="kpi">
      <span class="kpi-ic"><?= icon('wallet') ?></span>
      <h2>Resultado</h2>
      <p class="kpi-value <?= $net < 0 ? 'neg' : '' ?>"><?= $net < 0 ? '−' . money(-$net) : money($net) ?></p>
      <p class="kpi-prev">Ingresos + extra − egresos</p>
    </article>
  </section>

  <?= partial('components/admin-chart', ['finance' => $finance, 'id' => 'fin-chart', 'title' => 'Últimos 6 meses']) ?>

  <p class="admin-empty"><a class="link" href="/admin/egresos?mes=<?= e($month) ?>">Agregar o ver los gastos del mes en Egresos</a></p>
</main>
