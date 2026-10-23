<?php
/** screens/reservar.php — Reserva de turno. Variables: $services, $barbers, $days, $slots, $onlyToday (hora muerta en curso), $salePct */
?>
<section class="view reserve-grid" id="view-reservar" data-title="Reservar" data-skel="reservar" hidden>
  <h1 class="page-title reserve-title">Reservar turno</h1>
  <?= partial('components/booking-steps', compact('services', 'barbers', 'days', 'slots', 'onlyToday', 'salePct')) ?>
  <?= partial('components/booking-summary') ?>
</section>
