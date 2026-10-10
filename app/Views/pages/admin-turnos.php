<?php
/**
 * pages/admin-turnos.php — Agenda del día: cliente, servicio, barbero y estado; permite marcar cada turno
 * reservado como atendido o ausente.
 *
 * Variables: $day/$prev/$next (YYYY-MM-DD), $isToday, $appointments (Dashboard::appointments), $flash ('1'|'0'|'')
 */
$statusLabel = ['booked' => 'Reservado', 'done' => 'Atendido', 'no_show' => 'No vino', 'cancelled' => 'Cancelado'];
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Turnos', 'sub' => ucfirst(date_es($day)) . ($isToday ? ' · hoy' : ''), 'active' => 'turnos']) ?>

  <nav class="admin-nav" aria-label="Cambiar de día">
    <a class="btn-small" href="/admin/turnos?fecha=<?= e($prev) ?>">← Anterior</a>
    <?php if (!$isToday): ?><a class="btn-small" href="/admin/turnos">Hoy</a><?php endif; ?>
    <a class="btn-small" href="/admin/turnos?fecha=<?= e($next) ?>">Siguiente →</a>
  </nav>

  <?php if ($flash === '1'): ?><p class="admin-flash ok" role="status">Turno actualizado.</p>
  <?php elseif ($flash === '0'): ?><p class="admin-flash bad" role="status">No se pudo actualizar: el turno ya no estaba reservado.</p><?php endif; ?>

  <section class="admin-card" aria-labelledby="day-turns">
    <h2 id="day-turns">Turnos del día</h2>
<?php if (!$appointments): ?>
    <p class="admin-empty">No hay turnos para este día.</p>
<?php else: ?>
    <div class="table-wrap">
      <?php // .oneline: cada turno ocupa un solo renglón también en celular; se desliza de costado con la hora fija (ver admin.css). ?>
      <table class="admin-table oneline">
        <thead>
          <tr><th>Hora</th><th>Cliente</th><th>Servicio</th><th>Barbero</th><th class="num">Precio</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
<?php foreach ($appointments as $a): ?>
          <tr>
            <td class="cell-main"><b><?= e($a['time']) ?></b></td>
            <td data-label="Cliente"><?= e($a['client']) ?></td>
            <td data-label="Servicio"><?= e($a['service']) ?></td>
            <td data-label="Barbero"><?= e($a['barber']) ?></td>
            <td class="num" data-label="Precio"><?= money($a['price']) ?></td>
            <td data-label="Estado"><span class="status <?= e($a['status']) ?>"><?= e($statusLabel[$a['status']] ?? $a['status']) ?></span></td>
            <td class="actions">
<?php if ($a['status'] === 'booked'): ?>
              <form method="post" action="/admin/turnos/estado" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                <input type="hidden" name="fecha" value="<?= e($day) ?>">
                <button class="btn-small" name="status" value="done" type="submit">Atendido</button>
                <button class="btn-small ghost" name="status" value="no_show" type="submit">No vino</button>
              </form>
<?php endif; ?>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>
  </section>
</main>
