<?php
/**
 * components/queue-ticket.php — Tarjeta de Inicio con el número de la fila del cliente: su número, detrás de qué
 * número está, cuántos tiene adelante y qué número se atiende ahora. assets/js/queue.js la actualiza cada tanto
 * (GET /api/fila) usando los data-q-*.
 * Variables: $ticket (QueueTicket::currentFor: number, status, ahead, before, serving)
 */
$called = $ticket['status'] === 'called';
?>
<article class="queue-card<?= $called ? ' is-called' : '' ?>" id="queue-card" data-track="fila.tarjeta" aria-live="polite">
  <div class="queue-main">
    <p class="queue-label">Tu número en la fila</p>
    <p class="queue-number" data-q-number><?= $ticket['number'] ?></p>
  </div>
  <div class="queue-info">
    <p class="queue-state" data-q-state>
      <?php if ($called): ?>¡Es tu turno! Acercate al sillón.
      <?php elseif ($ticket['ahead'] === 0): ?>Sos el próximo.
      <?php else: ?>Estás después del <b><?= $ticket['before'] ?></b>.
      <?php endif; ?>
    </p>
    <p class="queue-meta">
      <span>Delante tuyo: <b data-q-ahead><?= $ticket['ahead'] ?></b></span>
      <span>Atendiendo: <b data-q-serving><?= $ticket['serving'] ?? '—' ?></b></span>
    </p>
    <form method="post" action="/fila/salir" class="queue-leave">
      <?= csrf_field() ?>
      <button class="link" type="submit" data-track="fila.salir">Dejar la fila</button>
    </form>
  </div>
</article>
