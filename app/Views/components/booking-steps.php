<?php
/**
 * components/booking-steps.php — Pasos del formulario de reserva (servicio, barbero, día, horario).
 * Son radios ocultos (servicios: casillas, se pueden combinar varios en un turno): el CSS pinta la opción
 * elegida y assets/js/booking.js lee las selecciones.
 *
 * Variables: $services (Service[]), $barbers (Barber[]), $days (array), $slots (array),
 *            $onlyToday (bool, opcional: hora muerta en curso, $days trae solo hoy),
 *            $salePct (int, opcional: % de la hora muerta; booking.js lo usa para tachar el total del resumen)
 */
?>
<div class="reserve-steps" data-discount="<?= (int) ($salePct ?? 0) ?>">
  <fieldset class="step">
    <legend>Servicios <small class="step-hint">podés elegir hasta <?= \App\Services\Booking::MAX_SERVICES ?></small></legend>
    <div class="chips" data-max="<?= \App\Services\Booking::MAX_SERVICES ?>">
      <?php foreach ($services as $i => $s): ?>
        <label class="chip">
          <input type="checkbox" name="service" value="<?= e($s->id) ?>" data-label="<?= e($s->name) ?>" data-price="<?= $s->price ?>" data-min="<?= $s->minutes ?>" <?= $i === 0 ? 'checked' : '' ?>>
          <span><?= e($s->name) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset class="step">
    <legend>Barbero</legend>
    <!-- Grilla 2×2 de fichas chicas (radio oculto en cada una): cada barbero muestra su imagen (o sus iniciales si
         no tiene); la elegida queda marcada. Todas tienen el mismo tamaño para que la grilla no se mueva. -->
    <div class="barber-picks">
      <label class="barber-pick barber-pick-any-opt">
        <input type="radio" name="barber" value="any" data-label="Aleatorio" checked>
        <span class="barber-pick-body">
          <span class="barber-pick-top"></span>
          <span class="barber-pick-face"><img class="barber-pick-any" src="/img/barberos/cualquiera.webp" alt="Sillón de barbería" width="60" height="60" decoding="async"></span>
          <b class="barber-pick-name">Aleatorio</b>
          <small class="barber-pick-years">El primero libre en tu horario</small>
        </span>
      </label>
      <?php foreach ($barbers as $b): ?>
        <label class="barber-pick<?= $b->photo ? ' has-photo' : '' ?><?= $b->isTopRated() ? ' is-top' : '' ?>" style="--tone: <?= e(css_color($b->tone)) ?>">
          <input type="radio" name="barber" value="<?= e($b->id) ?>" data-label="<?= e($b->name) ?>">
          <span class="barber-pick-body">
            <span class="barber-pick-top"><span class="rating"><?php if ($b->isNew()): ?>Nuevo<?php else: ?><?= icon('star') ?><?= e($b->rating) ?><?php endif; ?></span></span>
            <span class="barber-pick-face">
              <?php if ($b->photo): ?>
                <img class="barber-pick-photo" src="<?= e($b->photo) ?>" alt="Ilustración de <?= e($b->name) ?>" width="76" height="76" decoding="async">
              <?php else: ?>
                <span class="avatar barber-pick-avatar"><?= initials($b->name) ?></span>
              <?php endif; ?>
            </span>
            <b class="barber-pick-name"><?= e($b->name) ?></b>
            <span class="barber-role"><?= e($b->role) ?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset class="step">
    <legend>Día</legend>
    <?php if (!empty($onlyToday)): ?>
      <p class="step-note">Hora muerta en curso: mientras dura solo se reservan turnos para hoy, con descuento.</p>
    <?php else: ?>
      <p class="step-note">Los miércoles no se dan turnos: atendemos por orden de llegada. Escaneá el QR de la fila en el local.</p>
    <?php endif; ?>
    <div class="days">
      <?php foreach ($days as $i => $d): ?>
        <label class="day">
          <input type="radio" name="day" value="<?= e($d['iso']) ?>" data-label="<?= e($d['label']) ?> <?= e($d['num']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
          <span><small><?= e($d['label']) ?></small><b><?= e($d['num']) ?></b></span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset class="step">
    <legend>Horario</legend>
    <div class="slots">
      <?php foreach ($slots as $slot): ?>
        <label class="slot">
          <input type="radio" name="time" value="<?= e($slot['time']) ?>" data-label="<?= e($slot['time']) ?>" <?= $slot['free'] ? '' : 'disabled' ?>>
          <span><?= e($slot['time']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>
</div>
