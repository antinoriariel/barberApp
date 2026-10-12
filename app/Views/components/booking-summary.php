<?php
/** components/booking-summary.php — Resumen de la reserva y botón de confirmar (lo actualiza booking.js; "Dejar de reprogramar" solo aparece al mover un turno existente). */
?>
<div class="summary reserve-side" id="summary" data-track="reservar.resumen">
  <div>
    <p class="summary-line" id="summary-line">Elegí un horario</p>
    <p class="summary-sub" id="summary-sub"></p>
    <button class="link" type="button" id="stop-reschedule" data-track="reservar.dejar-reprogramar" hidden>Dejar de reprogramar</button>
  </div>
  <button class="btn-primary" id="confirm" data-track="reservar.confirmar" disabled>Confirmar turno</button>
</div>
