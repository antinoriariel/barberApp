<?php
/**
 * pages/admin-hora-muerta.php — "Hora muerta": descuento por tiempo limitado para cuando el local está sin clientes.
 * Si hay una vigente, tarjeta con el porcentaje, la cuenta regresiva (js/countdown.js; al llegar a cero recarga la
 * página) y "Terminar ahora". Si no, el formulario para activarla: porcentaje y duración elegidos con chips.
 * Abajo, las últimas horas muertas con cuántos turnos se reservaron con su descuento.
 *
 * Variables: $current (DeadHour::current() o null), $recent (DeadHour::recent()),
 *            $flash ('inicio' activada | 'fin' cortada | ''), $error (string)
 */
use App\Models\DeadHour;
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Hora muerta', 'sub' => 'Descuento por tiempo limitado para llenar las horas sin clientes', 'sections' => $user->panelSections(), 'role' => $user->panelRole(), 'active' => 'hora-muerta']) ?>

  <?php if ($flash === 'inicio'): ?><p class="admin-flash ok" role="status">Hora muerta activada. Los clientes ya ven el descuento.</p><?php endif; ?>
  <?php if ($flash === 'fin'): ?><p class="admin-flash ok" role="status">Hora muerta terminada.</p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if ($current !== null): $left = max(0, $current['ends'] - time()); ?>
  <section class="admin-card dh-live" aria-labelledby="dh-live-title">
    <p class="dh-badge"><span class="dh-pulse" aria-hidden="true"></span>En curso</p>
    <h2 id="dh-live-title" class="dh-percent"><?= $current['percent'] ?>% OFF</h2>
    <p class="dh-clock" data-countdown="<?= $current['ends'] * 1000 ?>" data-now="<?= time() * 1000 ?>" data-reload
       role="timer" aria-live="off"><?= e(countdown_text($left)) ?></p>
    <p class="admin-sub">Termina a las <?= e(date('H:i', $current['ends'])) ?>. Los turnos de hoy que se reserven hasta entonces salen con el descuento.</p>
    <form method="post" action="/admin/hora-muerta/terminar" class="inline-form">
      <?= csrf_field() ?>
      <button class="btn-small ghost" type="submit">Terminar ahora</button>
    </form>
  </section>
<?php endif; ?>

  <section class="admin-card svc-edit invert" aria-labelledby="dh-new">
    <h2 id="dh-new"><?= $current !== null ? 'Reemplazar por otra' : 'Activar hora muerta' ?></h2>
    <form method="post" action="/admin/hora-muerta" class="svc-edit">
      <?= csrf_field() ?>
      <fieldset class="dh-group">
        <legend>Descuento</legend>
        <div class="dh-chips">
<?php foreach (DeadHour::PERCENTS as $p): ?>
          <label class="dh-chip"><input type="radio" name="percent" value="<?= $p ?>"<?= $p === 20 ? ' checked' : '' ?> required><span><?= $p ?>%</span></label>
<?php endforeach; ?>
        </div>
      </fieldset>
      <fieldset class="dh-group">
        <legend>Duración</legend>
        <div class="dh-chips">
<?php foreach (DeadHour::MINUTES as $m): ?>
          <label class="dh-chip"><input type="radio" name="minutes" value="<?= $m ?>"<?= $m === 60 ? ' checked' : '' ?> required><span><?= $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) : '') ?></span></label>
<?php endforeach; ?>
        </div>
      </fieldset>
      <p class="admin-sub">Empieza ahora y corre hacia atrás. Los clientes lo ven en Inicio y en su Perfil con la cuenta regresiva; vale solo para turnos de hoy.</p>
      <div class="svc-row-actions">
        <button class="btn-small" type="submit">Activar hora muerta</button>
      </div>
    </form>
  </section>

  <section class="admin-card" aria-labelledby="dh-list">
    <h2 id="dh-list">Últimas horas muertas</h2>
<?php if (!$recent): ?>
    <p class="admin-sub">Todavía no se activó ninguna.</p>
<?php else: ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>Día</th><th>Horario</th><th class="num">Descuento</th><th class="num">Turnos</th></tr></thead>
        <tbody>
<?php foreach ($recent as $r): ?>
          <tr>
            <td><?= e(date('d/m', $r['starts'])) ?></td>
            <td><?= e(date('H:i', $r['starts'])) ?> a <?= e(date('H:i', $r['ends'])) ?><?= $r['cut'] ? ' <small>(cortada)</small>' : '' ?></td>
            <td class="num"><?= $r['percent'] ?>%</td>
            <td class="num"><?= $r['bookings'] ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>
  </section>
</main>
