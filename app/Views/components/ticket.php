<?php
/**
 * components/ticket.php — Tarjeta principal de Inicio: el próximo turno, o una invitación a reservar si no hay.
 * Variables: $appointment (array ['id','service','service_id','barber','barber_id','barber_photo','day','time'] o null)
 * Si el barbero del turno tiene imagen (barber_photo), se muestra a la izquierda de los datos del turno.
 * Los botones los atiende assets/js/booking.js (data-reschedule / data-cancel con el id del turno).
 */
if (!$appointment): ?>
<article class="ticket ticket-empty" data-track="turno.tarjeta">
  <div class="ticket-main">
    <p class="ticket-label">Sin turno por ahora</p>
    <p class="ticket-title">¿Te armamos un turno?</p>
    <p class="ticket-what">Elegí servicio, barbero y horario en un minuto.</p>
  </div>
  <div class="ticket-actions">
    <a href="#reservar" class="ticket-btn primary" data-track="turno.reservar">Reservar turno</a>
  </div>
</article>
<?php return; endif; ?>
<?php $photo = $appointment['barber_photo'] ?? null; ?>
<article class="ticket<?= $photo ? ' has-photo' : '' ?>" data-track="turno.tarjeta">
  <div class="ticket-main">
    <p class="ticket-label">Tu próximo turno</p>
    <p class="ticket-when"><?= e($appointment['day']) ?> <span><?= e($appointment['time']) ?></span></p>
    <p class="ticket-what"><?= icon('scissors') ?><?= e($appointment['service']) ?> con <?= e($appointment['barber']) ?></p>
    <?php if ($photo): ?>
      <img class="ticket-photo" src="<?= e($photo) ?>" alt="Ilustración de <?= e($appointment['barber']) ?>" width="128" height="128" decoding="async">
    <?php endif; ?>
  </div>
  <div class="ticket-actions">
    <button class="ticket-btn" type="button" data-track="turno.reprogramar" data-track-to="#reservar" data-reschedule="<?= (int) ($appointment['id'] ?? 0) ?>" data-service="<?= e($appointment['service_id'] ?? '') ?>" data-barber="<?= e($appointment['barber_id'] ?? '') ?>">Reprogramar</button>
    <button class="ticket-btn quiet" type="button" data-track="turno.cancelar" data-cancel="<?= (int) ($appointment['id'] ?? 0) ?>">Cancelar</button>
  </div>
</article>
