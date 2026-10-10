<?php
/**
 * components/barber-card.php — Tarjeta de un barbero: banda con su color, avatar, valoración, especialidad,
 * años de oficio, imagen opcional junto al nombre ($barber->photo) y botón para reservar con él
 * (data-book-barber, lo atiende booking.js). Con 5 estrellas (isTopRated) toda la tarjeta toma el fondo de la banda.
 * Variables: $barber (Models\Barber)
 */
$first = explode(' ', $barber->name)[0];
?>
<li class="barber<?= $barber->photo ? ' has-photo' : '' ?><?= $barber->isTopRated() ? ' is-top' : '' ?>" style="--tone: <?= e(css_color($barber->tone)) ?>">
  <div class="barber-top">
    <?php // Ficha recién creada desde /admin/usuarios: sin valoración ni años cargados, se presenta como "Nuevo". ?>
    <span class="rating"><?php if ($barber->isNew()): ?>Nuevo<?php else: ?><?= icon('star') ?><?= e($barber->rating) ?><?php endif; ?></span>
  </div>
  <span class="avatar barber-avatar"><?= initials($barber->name) ?></span>
  <div class="barber-body">
    <div class="barber-info">
      <h2><?= e($barber->name) ?></h2>
      <p class="barber-role"><?= e($barber->role) ?></p>
      <?php if ($barber->years > 0): ?>
        <p class="barber-years"><b><?= $barber->years ?></b> años de oficio</p>
      <?php else: ?>
        <p class="barber-years">Nuevo en el equipo</p>
      <?php endif; ?>
      <?php if ($barber->photo): ?>
        <img class="barber-photo" src="<?= e($barber->photo) ?>" alt="Ilustración de <?= e($barber->name) ?>" width="128" height="128" loading="lazy" decoding="async">
      <?php endif; ?>
    </div>
    <button class="btn-primary block" data-book-barber="<?= e($barber->id) ?>">Reservar con <?= e($first) ?></button>
  </div>
</li>
