<?php
/**
 * components/dead-hour.php — Aviso de "Hora muerta" para el cliente (Inicio y Perfil): porcentaje de descuento, cuenta
 * regresiva (js/countdown.js; al llegar a cero el aviso se oculta) y acceso directo a Reservar.
 * Variables: $promo (DeadHour::current(): percent, ends) — no se incluye si no hay una vigente.
 */
?>
<aside class="dead-hour" aria-label="Hora muerta: descuento por tiempo limitado">
  <div class="dead-hour-text">
    <p class="dead-hour-tag">Hora muerta</p>
    <p class="dead-hour-pct"><?= (int) $promo['percent'] ?>% OFF</p>
    <p class="dead-hour-sub">En turnos de hoy reservados ahora</p>
  </div>
  <div class="dead-hour-end">
    <small>Termina en</small>
    <span class="dead-hour-clock" data-countdown="<?= (int) $promo['ends'] * 1000 ?>" data-now="<?= time() * 1000 ?>" data-hide=".dead-hour" data-end-class="sale-over"
          role="timer" aria-live="off"><?= e(countdown_text($promo['ends'] - time())) ?></span>
    <a class="dead-hour-cta" href="#reservar">Reservar</a>
  </div>
</aside>
