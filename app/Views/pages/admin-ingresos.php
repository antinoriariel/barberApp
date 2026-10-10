<?php
/**
 * pages/admin-ingresos.php — Ingresos extra del mes (lo cobrado fuera de los turnos de la agenda): alta con fecha,
 * monto, categoría y observación debajo, listado con baja y totales. Lo cargado acá se suma a los ingresos de los
 * turnos en el resultado del resumen (día y mes) y en finanzas.
 *
 * Variables: $month ('YYYY-MM'), $prev/$next ('YYYY-MM'), $isCurrent, $totals (Dashboard::finance del mes:
 *            revenue/extra/expenses/net), $incomes (Income::forMonth), $flash ('1'|''), $error (string)
 */
$net = $totals['net'];
$today = date('Y-m-d');
$defaultDate = substr($today, 0, 7) === $month ? $today : $month . '-01';
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Ingreso extra', 'sub' => ucfirst(month_es((int) substr($month, 5))) . ' ' . substr($month, 0, 4) . ($isCurrent ? ' · mes en curso' : ''), 'active' => 'ingresos']) ?>

  <nav class="admin-nav" aria-label="Cambiar de mes">
    <a class="btn-small" href="/admin/ingresos?mes=<?= e($prev) ?>">← Mes anterior</a>
    <?php if (!$isCurrent): ?><a class="btn-small" href="/admin/ingresos">Este mes</a><?php endif; ?>
    <a class="btn-small" href="/admin/ingresos?mes=<?= e($next) ?>">Mes siguiente →</a>
  </nav>

  <?php if ($flash === '1'): ?><p class="admin-flash ok" role="status">Cambios guardados.</p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>

  <section class="admin-kpis" aria-label="Totales del mes">
    <article class="kpi">
      <h2>Ingresos extra del mes</h2>
      <p class="kpi-value"><?= money($totals['extra']) ?></p>
      <p class="kpi-prev"><?= count($incomes) ?> <?= count($incomes) === 1 ? 'movimiento' : 'movimientos' ?></p>
    </article>
    <article class="kpi">
      <h2>Resultado</h2>
      <p class="kpi-value <?= $net < 0 ? 'neg' : '' ?>"><?= $net < 0 ? '−' . money(-$net) : money($net) ?></p>
      <p class="kpi-prev">Turnos (<?= money($totals['revenue']) ?>) + extra − egresos (<?= money($totals['expenses']) ?>)</p>
    </article>
  </section>

  <section class="admin-card svc-edit" aria-labelledby="inc-new">
    <h2 id="inc-new">Agregar ingreso extra</h2>
    <form method="post" action="/admin/ingresos" class="svc-edit">
      <?= csrf_field() ?>
      <div class="svc-fields">
        <label>Fecha<input type="date" name="received_on" value="<?= e($defaultDate) ?>" required></label>
        <label>Monto ($)<input type="text" name="amount" inputmode="numeric" autocomplete="off" maxlength="11" pattern="\d{1,3}(\.\d{3})*" placeholder="Ej: 15.000" data-thousands required></label>
        <label>Categoría
          <select name="category" required>
<?php foreach (\App\Models\Income::CATEGORIES as $c): ?>
            <option value="<?= e($c) ?>"><?= e($c) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label class="wide">Observación<textarea name="notes" maxlength="255" rows="2" placeholder="Opcional: a quién, qué se cobró, detalle…"></textarea></label>
      </div>
      <div class="svc-row-actions"><button class="btn-small" type="submit">Agregar ingreso</button></div>
    </form>
  </section>

  <section class="admin-card" aria-labelledby="inc-list">
    <h2 id="inc-list">Ingresos extra del mes</h2>
<?php if (!$incomes): ?>
    <p class="admin-empty">No hay ingresos extra cargados en este mes.</p>
<?php else: ?>
    <div class="table-wrap">
      <?php // .oneline: cada ingreso ocupa un solo renglón también en celular; se desliza de costado con la fecha fija (ver admin.css). ?>
      <table class="admin-table oneline">
        <thead><tr><th>Fecha</th><th>Categoría</th><th>Observación</th><th class="num">Monto</th><th></th></tr></thead>
        <tbody>
<?php foreach ($incomes as $x): ?>
          <tr>
            <td class="cell-main"><b><?= e(date('d/m', strtotime($x['received_on']))) ?></b></td>
            <td data-label="Categoría"><?= e($x['category']) ?></td>
            <td data-label="Observación"><?= $x['notes'] !== '' ? e($x['notes']) : '—' ?></td>
            <td class="num" data-label="Monto"><b><?= money($x['amount']) ?></b></td>
            <td class="actions">
              <form method="post" action="/admin/ingresos/borrar" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $x['id'] ?>">
                <input type="hidden" name="mes" value="<?= e($month) ?>">
                <button class="btn-small ghost" type="submit">Borrar</button>
              </form>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>
  </section>
</main>
