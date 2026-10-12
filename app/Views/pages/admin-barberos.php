<?php
/**
 * pages/admin-barberos.php — Cortes atendidos por barbero, día por día, en la semana (lunes a domingo).
 *
 * Debajo, los días que abre el local (opening_hours, se eligen con un tilde por día) y el horario semanal de cada barbero: qué días atiende y de qué hora a qué hora. La agenda solo le ofrece
 * turnos dentro de esa franja; los días que el local cierra o atiende por orden de llegada no se configuran.
 *
 * Variables: $week (Dashboard::week), $prev/$next (YYYY-MM-DD: semana anterior/siguiente), $isCurrent (bool),
 *            $team (lista de ['barber' => Barber, 'hours' => BarberHours::week]), $weekdays (Schedule::weekdayStatus),
 *            $flash ('1' horario guardado | 'dias' días del local guardados | ''), $error (string),
 *            $stranded (turnos reservados que quedaron en días ahora cerrados)
 */
$range = date('j', strtotime($week['from'])) . ' de ' . month_es((int) date('n', strtotime($week['from'])))
    . ' al ' . date('j', strtotime($week['to'])) . ' de ' . month_es((int) date('n', strtotime($week['to'])));
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Barberos', 'sub' => 'Semana del ' . $range . ($isCurrent ? ' · esta semana' : ''), 'sections' => $user->panelSections(), 'active' => 'barberos']) ?>

  <?php if ($flash === '1'): ?><p class="admin-flash ok" role="status">Horario guardado. Vale para las reservas nuevas.</p><?php endif; ?>
  <?php if ($flash === 'dias'): ?><p class="admin-flash ok" role="status">Días del local guardados.</p><?php endif; ?>
  <?php if ($stranded > 0): ?><p class="admin-flash bad" role="alert">Ojo: hay <?= $stranded ?> turno<?= $stranded === 1 ? '' : 's' ?> reservado<?= $stranded === 1 ? '' : 's' ?> en días que ahora están cerrados. No se cancelan solos: revisalos en Turnos.</p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>

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
    <?= partial('components/admin-week-table', ['days' => $week['days'], 'rows' => $week['barbers']]) ?>
  </section>

  <?php // Orden lunes → domingo; los índices son los de date('w') (0 = domingo). ?>
  <?php $order = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo']; ?>
  <?php $notes = ['closed' => 'Local cerrado', 'walkin' => 'Por orden de llegada']; ?>
  <section class="hours-section" aria-labelledby="hours-title">
    <header class="hours-section-head">
      <h2 id="hours-title" class="admin-section-title">Horarios de atención</h2>
      <p class="admin-sub">La agenda solo ofrece turnos dentro de la franja de cada barbero.</p>
    </header>
    <?php // Días que abre el local: un tilde por día. Los cerrados no ofrecen turnos y no se configuran por barbero. ?>
    <section class="admin-card shop-days" id="dias-local" aria-labelledby="dias-title">
      <header class="hours-head">
        <h3 id="dias-title">Días del local</h3>
        <span class="hours-count">Abre <?= count(array_filter($weekdays, static fn (string $st) => $st !== 'closed')) ?> de 7 días</span>
      </header>
      <form method="post" action="/admin/barberos/dias" class="hours-form">
        <?= csrf_field() ?>
        <div class="day-chips">
<?php foreach ($order as $w => $dayName): ?>
          <label class="day-chip">
            <input type="checkbox" name="open[]" value="<?= $w ?>"<?= $weekdays[$w] !== 'closed' ? ' checked' : '' ?>>
            <span class="day-chip-name"><?= e($dayName) ?></span>
            <span class="day-chip-state" aria-hidden="true"></span>
<?php if ($weekdays[$w] === 'walkin'): ?>
            <small class="day-chip-hint">Por orden de llegada</small>
<?php endif; ?>
          </label>
<?php endforeach; ?>
        </div>
        <button class="btn-small hours-save" type="submit">Guardar días</button>
      </form>
    </section>

    <div class="hours-grid">
<?php foreach ($team as ['barber' => $b, 'hours' => $hours]):
    // Resumen del encabezado: cuántos de los días con turnos atiende.
    $openDays = array_keys(array_filter($weekdays, static fn (string $st) => $st === 'open'));
    $working = count(array_filter($openDays, static fn (int $w) => $hours[$w]['works']));
?>
      <section class="admin-card hours-edit" id="horario-<?= e($b->id) ?>" aria-labelledby="hours-<?= e($b->id) ?>">
        <header class="hours-head">
          <h3 id="hours-<?= e($b->id) ?>"><span class="dot" style="--tone: <?= e(css_color($b->tone)) ?>"></span><?= e($b->name) ?></h3>
          <span class="hours-count">Atiende <?= $working ?> de <?= count($openDays) ?> días</span>
        </header>
        <form method="post" action="/admin/barberos/horario" class="hours-form">
          <?= csrf_field() ?>
          <input type="hidden" name="barber" value="<?= e($b->id) ?>">
          <ul class="hours-list">
<?php foreach ($order as $w => $dayName): $h = $hours[$w]; ?>
            <li class="hours-row<?= $weekdays[$w] !== 'open' ? ' off' : '' ?>">
<?php if ($weekdays[$w] === 'open'): ?>
              <label class="hours-day"><input type="checkbox" name="days[<?= $w ?>][works]" value="1"<?= $h['works'] ? ' checked' : '' ?>> <?= e($dayName) ?></label>
              <input class="hours-time from" type="time" name="days[<?= $w ?>][from]" value="<?= e($h['from']) ?>" step="300" aria-label="<?= e($dayName) ?> desde">
              <span class="hours-sep" aria-hidden="true">a</span>
              <input class="hours-time to" type="time" name="days[<?= $w ?>][to]" value="<?= e($h['to']) ?>" step="300" aria-label="<?= e($dayName) ?> hasta">
<?php else: ?>
              <span class="hours-day"><?= e($dayName) ?></span>
              <span class="hours-note"><?= e($notes[$weekdays[$w]]) ?></span>
<?php endif; ?>
            </li>
<?php endforeach; ?>
          </ul>
          <button class="btn-small hours-save" type="submit">Guardar horario</button>
        </form>
      </section>
<?php endforeach; ?>
    </div>
  </section>
</main>
