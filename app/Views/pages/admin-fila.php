<?php
/**
 * pages/admin-fila.php — Fila por orden de llegada de hoy: el QR para imprimir y pegar en el mostrador, el número
 * que se está atendiendo con el botón "Llamar siguiente" y la lista del día (con "No vino").
 *
 * Variables: $qr (SVG del QR, lo genera Services\Qr: es seguro imprimirlo sin escapar), $qrUrl (enlace que contiene),
 *            $serving (int|null), $tickets (QueueTicket::todayList), $flash ('llamado'|'vacia'|'')
 */
$statusLabel = ['waiting' => 'Esperando', 'called' => 'Atendiendo', 'done' => 'Atendido', 'no_show' => 'No vino', 'cancelled' => 'Se fue'];
$statusClass = ['waiting' => 'booked', 'called' => 'done', 'done' => 'done', 'no_show' => 'no_show', 'cancelled' => 'cancelled'];
$waiting = count(array_filter($tickets, static fn (array $t) => $t['status'] === 'waiting'));
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Fila', 'sub' => ucfirst(date_es(date('Y-m-d'))) . ' · por orden de llegada', 'active' => 'fila']) ?>

  <?php if ($flash === 'vacia'): ?><p class="admin-flash bad" role="status">No hay nadie esperando.</p><?php endif; ?>

  <section class="admin-kpis" aria-label="Estado de la fila">
    <article class="kpi">
      <h2>Atendiendo</h2>
      <p class="kpi-value"><?= $serving ?? '—' ?></p>
      <form method="post" action="/admin/fila/llamar" class="queue-call">
        <?= csrf_field() ?>
        <button class="btn-primary" type="submit"<?= $waiting === 0 ? ' disabled' : '' ?>>Llamar siguiente</button>
      </form>
    </article>
    <article class="kpi">
      <h2>Esperando</h2>
      <p class="kpi-value"><?= $waiting ?></p>
      <p class="kpi-prev"><?= count($tickets) ?> números entregados hoy</p>
    </article>
  </section>

  <section class="admin-card" aria-labelledby="queue-list">
    <h2 id="queue-list">Números de hoy</h2>
<?php if (!$tickets): ?>
    <p class="admin-empty">Todavía nadie sacó número. Cuando escaneen el QR del mostrador aparecen acá.</p>
<?php else: ?>
    <div class="table-wrap">
      <?php // .stack: en celular cada número es una tarjeta; desde 720px, tabla (ver admin.css). ?>
      <table class="admin-table stack">
        <thead><tr><th>Número</th><th>Cliente</th><th>Llegó</th><th>Estado</th><th></th></tr></thead>
        <tbody>
<?php foreach ($tickets as $t): ?>
          <tr>
            <td class="cell-main"><b>#<?= $t['number'] ?></b></td>
            <td data-label="Cliente"><?= e($t['client']) ?></td>
            <td data-label="Llegó"><?= e($t['time']) ?></td>
            <td data-label="Estado"><span class="status <?= e($statusClass[$t['status']] ?? '') ?>"><?= e($statusLabel[$t['status']] ?? $t['status']) ?></span></td>
            <td class="actions">
<?php if (in_array($t['status'], ['waiting', 'called'], true)): ?>
              <form method="post" action="/admin/fila/ausente" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button class="btn-small ghost" type="submit">No vino</button>
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

  <section class="admin-card queue-qr" aria-labelledby="queue-qr">
    <h2 id="queue-qr">QR del mostrador</h2>
    <p class="admin-empty">Imprimilo y pegalo en el local. Al escanearlo, cada cliente recibe el número siguiente.</p>
    <div class="queue-qr-code"><?= $qr ?></div>
    <p class="queue-qr-url"><?= e($qrUrl) ?></p>
  </section>
</main>
