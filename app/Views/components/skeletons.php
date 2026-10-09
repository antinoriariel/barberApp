<?php
/**
 * components/skeletons.php — Marcadores de carga (skeletons) de cada pantalla.
 *
 * Cada bloque imita la estructura de su pantalla real. Se enlazan por el atributo
 * data-skel de la <section class="view"> (ej. data-skel="list" → #skel-list);
 * navigation.js los alterna con las pantallas. Reutilizan las clases de layout
 * (home-grid, reserve-grid, crew…) para ocupar el mismo lugar en escritorio.
 * El helper sk() está en Support/helpers.php.
 */
?>
<!-- Inicio (visible de entrada: es lo primero que se pinta) -->
<div class="skeleton home-grid" id="skel-inicio" aria-hidden="true">
  <div class="home-title"><div class="sk-stack"><?= sk('sk-title w80') . sk('sk-title w55') ?></div></div>
  <?= sk('sk-block h150') ?>
  <?= sk('sk-block h110') ?>
  <div><?= sk('sk-line w35') ?></div>
  <div class="rail"><?= sk('sk-card', 3) ?></div>
</div>

<!-- Servicios -->
<div class="skeleton" id="skel-list" hidden aria-hidden="true">
  <?= sk('sk-title w50') ?>
  <div class="sk-stack"><?= sk('sk-row', 6) ?></div>
</div>

<!-- Reservar -->
<div class="skeleton reserve-grid" id="skel-reservar" hidden aria-hidden="true">
  <div class="reserve-title"><?= sk('sk-title w60') ?></div>
  <div class="reserve-steps">
    <div class="sk-group"><?= sk('sk-line w25') ?><div class="sk-wrap"><?= sk('sk-chip', 5) ?></div></div>
    <div class="sk-group"><?= sk('sk-line w25') ?><div class="sk-wrap"><?= sk('sk-chip', 4) ?></div></div>
    <div class="sk-group"><?= sk('sk-line w25') ?><div class="sk-wrap"><?= sk('sk-day', 6) ?></div></div>
    <div class="sk-group"><?= sk('sk-line w25') ?><div class="sk-slots"><?= sk('sk-slot', 12) ?></div></div>
  </div>
  <?= sk('sk-block h110 reserve-side') ?>
</div>

<!-- Barberos -->
<div class="skeleton" id="skel-crew" hidden aria-hidden="true">
  <?= sk('sk-title w50') ?>
  <div class="crew"><?= sk('sk-block h150', 3) ?></div>
</div>

<!-- Perfil -->
<div class="skeleton" id="skel-perfil" hidden aria-hidden="true">
  <div class="sk-me"><?= sk('sk-avatar') ?><div class="sk-me-text"><?= sk('sk-title w70') . sk('sk-line w50') ?></div></div>
  <?= sk('sk-line w35') ?>
  <div class="sk-stack"><?= sk('sk-row', 3) ?></div>
</div>
