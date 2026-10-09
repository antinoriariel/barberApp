<?php
/** screens/barberos.php — Equipo en tarjetas. Variables: $barbers (Barber[]) */
?>
<section class="view" id="view-barberos" data-title="Barberos" data-skel="crew" hidden>
  <h1 class="page-title">Los barberos</h1>
  <p class="crew-intro">Elegí con quién querés atenderte.</p>
  <ul class="crew">
    <?php foreach ($barbers as $barber): ?>
      <?= partial('components/barber-card', compact('barber')) ?>
    <?php endforeach; ?>
  </ul>
</section>
