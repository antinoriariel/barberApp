<?php
/**
 * pages/admin-barberos.php — Cortes atendidos por barbero, día por día, en la semana (lunes a domingo).
 *
 * Debajo, los días que abre el local (opening_hours, se eligen con un tilde por día) y el horario semanal de cada barbero: qué días atiende y de qué hora a qué hora. La agenda solo le ofrece
 * turnos dentro de esa franja; los días que el local cierra o atiende por orden de llegada no se configuran.
 * Cada ficha permite cambiar o quitar la foto del barbero, y al final se da de alta un barbero nuevo (con foto opcional).
 * Cada tarjeta tiene un interruptor Habilitado/Deshabilitado: deshabilitado, el barbero no figura para los clientes
 * (equipo ni reserva) pero su horario se sigue pudiendo editar.
 * Solo el administrador los edita: un barbero con la sección habilitada ($canEdit = false) ve lo mismo en modo
 * lectura (días, franjas y estado como texto, sin formularios).
 *
 * Variables: $canEdit (bool: es admin), $week (Dashboard::week), $prev/$next (YYYY-MM-DD: semana anterior/siguiente), $isCurrent (bool),
 *            $team (lista de ['barber' => Barber, 'hours' => BarberHours::week, 'pending' => turnos a futuro si está
 *            deshabilitado]), $weekdays (Schedule::weekdayStatus),
 *            $flash ('1' horario guardado | 'dias' días del local guardados | 'nuevo' barbero creado | 'foto' foto cambiada | ''),
 *            $error (string),
 *            $stranded (turnos reservados que quedaron en días ahora cerrados)
 */
$range = date('j', strtotime($week['from'])) . ' de ' . month_es((int) date('n', strtotime($week['from'])))
    . ' al ' . date('j', strtotime($week['to'])) . ' de ' . month_es((int) date('n', strtotime($week['to'])));
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Barberos', 'sub' => 'Semana del ' . $range . ($isCurrent ? ' · esta semana' : ''), 'sections' => $user->panelSections(), 'active' => 'barberos']) ?>

  <?php if ($flash === '1'): ?><p class="admin-flash ok" role="status">Horario guardado. Vale para las reservas nuevas.</p><?php endif; ?>
  <?php if ($flash === 'dias'): ?><p class="admin-flash ok" role="status">Días del local guardados.</p><?php endif; ?>
  <?php if ($flash === 'nuevo'): ?><p class="admin-flash ok" role="status">Barbero agregado. Ya aparece en la app; por defecto atiende la jornada completa, ajustá su horario abajo.</p><?php endif; ?>
  <?php if ($flash === 'foto'): ?><p class="admin-flash ok" role="status">Foto actualizada.</p><?php endif; ?>
  <?php if ($stranded > 0): ?><p class="admin-flash bad" role="alert">Ojo: hay <?= $stranded ?> turno<?= $stranded === 1 ? '' : 's' ?> reservado<?= $stranded === 1 ? '' : 's' ?> en días que ahora están cerrados. No se cancelan solos: revisalos en Turnos.</p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>

  <nav class="admin-nav" aria-label="Cambiar de semana">
    <a class="btn-small" href="/admin/barberos?fecha=<?= e($prev) ?>">← Semana anterior</a>
    <?php if (!$isCurrent): ?><a class="btn-small" href="/admin/barberos">Esta semana</a><?php endif; ?>
    <a class="btn-small" href="/admin/barberos?fecha=<?= e($next) ?>">Semana siguiente →</a>
  </nav>

  <section class="admin-kpis" aria-label="Total de la semana">
    <article class="kpi">
      <h2>Cortes de la semana</h2>
      <p class="kpi-value"><?= $week['total'] ?></p>
      <p class="kpi-prev">Turnos atendidos entre todos los barberos</p>
    </article>
  </section>

  <section class="admin-card" aria-labelledby="week-cuts">
    <h2 id="week-cuts">Cortes por barbero</h2>
    <?= partial('components/admin-week-table', ['days' => $week['days'], 'rows' => $week['barbers']]) ?>
  </section>

  <?php // Orden lunes → domingo; los índices son los de date('w') (0 = domingo). ?>
  <?php $order = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo']; ?>
  <?php $notes = ['closed' => 'Local cerrado', 'walkin' => 'Por orden de llegada']; ?>
  <section class="hours-section" aria-labelledby="hours-title">
    <header class="hours-section-head">
      <h2 id="hours-title" class="admin-section-title">Horarios de atención</h2>
      <p class="admin-sub">La agenda solo ofrece turnos dentro de la franja de cada barbero.<?= $canEdit ? '' : ' Los carga y edita el administrador.' ?></p>
    </header>
    <?php // Días que abre el local: un tilde por día. Los cerrados no ofrecen turnos y no se configuran por barbero. ?>
    <section class="admin-card shop-days" id="dias-local" aria-labelledby="dias-title">
      <header class="hours-head">
        <h3 id="dias-title">Días del local</h3>
        <span class="hours-count">Abre <?= count(array_filter($weekdays, static fn (string $st) => $st !== 'closed')) ?> de 7 días</span>
      </header>
<?php if ($canEdit): ?>
      <form method="post" action="/admin/barberos/dias" class="hours-form">
        <?= csrf_field() ?>
        <div class="day-chips">
<?php foreach ($order as $w => $dayName): ?>
          <label class="day-chip">
            <input type="checkbox" name="open[]" value="<?= $w ?>"<?= $weekdays[$w] !== 'closed' ? ' checked' : '' ?>>
            <span class="day-chip-name"><?= e($dayName) ?></span>
            <span class="day-chip-state" aria-hidden="true"></span>
<?php if ($weekdays[$w] === 'walkin'): ?>
            <small class="day-chip-hint">Por orden de llegada</small>
<?php endif; ?>
          </label>
<?php endforeach; ?>
        </div>
        <button class="btn-small hours-save" type="submit">Guardar días</button>
      </form>
<?php else: ?>
      <ul class="day-chips">
<?php foreach ($order as $w => $dayName): $isOpen = $weekdays[$w] !== 'closed'; ?>
        <li class="day-chip static<?= $isOpen ? ' on' : '' ?>">
          <span class="day-chip-name"><?= e($dayName) ?></span>
          <span class="day-chip-state"><?= $isOpen ? 'Abierto' : 'Cerrado' ?></span>
<?php if ($weekdays[$w] === 'walkin'): ?>
          <small class="day-chip-hint">Por orden de llegada</small>
<?php endif; ?>
        </li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </section>

    <div class="hours-grid">
<?php foreach ($team as ['barber' => $b, 'hours' => $hours, 'pending' => $pending]):
    // Resumen del encabezado: cuántos de los días con turnos atiende.
    $openDays = array_keys(array_filter($weekdays, static fn (string $st) => $st === 'open'));
    $working = count(array_filter($openDays, static fn (int $w) => $hours[$w]['works']));
?>
      <section class="admin-card hours-edit<?= $b->enabled ? '' : ' disabled' ?>" id="horario-<?= e($b->id) ?>" aria-labelledby="hours-<?= e($b->id) ?>">
        <header class="hours-head">
          <h3 id="hours-<?= e($b->id) ?>"><span class="dot" style="--tone: <?= e(css_color($b->tone)) ?>"></span><?= e($b->name) ?></h3>
          <?php /* El propio badge es el interruptor: un click lo saca de los barberos disponibles o lo vuelve a mostrar.
                   Para un barbero (solo lectura) es una etiqueta fija. */ ?>
<?php if ($canEdit): ?>
          <form method="post" action="/admin/barberos/estado" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="barber" value="<?= e($b->id) ?>">
            <button type="submit" name="enabled" value="<?= $b->enabled ? '0' : '1' ?>"
                    class="status toggle <?= $b->enabled ? 'done' : 'cancelled' ?>"
                    title="<?= $b->enabled ? 'Click para que no figure en los barberos disponibles' : 'Click para que vuelva a figurar en los barberos disponibles' ?>"
                    aria-label="<?= e($b->name) ?>: <?= $b->enabled ? 'habilitado, deshabilitar' : 'deshabilitado, habilitar' ?>"><?= $b->enabled ? 'Habilitado' : 'Deshabilitado' ?></button>
          </form>
<?php else: ?>
          <span class="status <?= $b->enabled ? 'done' : 'cancelled' ?>"><?= $b->enabled ? 'Habilitado' : 'Deshabilitado' ?></span>
<?php endif; ?>
          <span class="hours-count"><?= $b->enabled ? 'Atiende ' . $working . ' de ' . count($openDays) . ' días' : 'No figura para los clientes' ?></span>
        </header>
        <?php // Foto del barbero: la que se ve en su tarjeta, al reservar y en el ticket del turno. ?>
        <div class="barber-photo-edit">
<?php if ($b->photo): ?>
          <img class="bp-avatar" src="<?= e($b->photo) ?>" alt="Foto de <?= e($b->name) ?>" width="64" height="64" style="--tone: <?= e(css_color($b->tone)) ?>">
<?php else: ?>
          <span class="bp-avatar empty" style="--tone: <?= e(css_color($b->tone)) ?>" aria-hidden="true"><?= e(mb_substr($b->name, 0, 1)) ?></span>
<?php endif; ?>
<?php if ($canEdit): ?>
          <form method="post" action="/admin/barberos/foto" enctype="multipart/form-data" class="bp-form">
            <?= csrf_field() ?>
            <input type="hidden" name="barber" value="<?= e($b->id) ?>">
            <input class="bp-file" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required aria-label="Nueva foto de <?= e($b->name) ?>">
            <button class="btn-small" type="submit"><?= $b->photo ? 'Cambiar foto' : 'Subir foto' ?></button>
          </form>
<?php if ($b->photo): ?>
          <form method="post" action="/admin/barberos/foto" class="bp-form">
            <?= csrf_field() ?>
            <input type="hidden" name="barber" value="<?= e($b->id) ?>">
            <button class="btn-small" type="submit" name="remove" value="1">Quitar foto</button>
          </form>
<?php endif; ?>
<?php endif; ?>
        </div>
<?php if ($pending > 0): ?>
        <p class="admin-flash bad" role="alert">Tiene <?= $pending ?> turno<?= $pending === 1 ? '' : 's' ?> reservado<?= $pending === 1 ? '' : 's' ?> a futuro. No se cancelan solos: revisalos en Turnos.</p>
<?php endif; ?>
<?php if ($canEdit): ?>
        <form method="post" action="/admin/barberos/horario" class="hours-form">
          <?= csrf_field() ?>
          <input type="hidden" name="barber" value="<?= e($b->id) ?>">
          <ul class="hours-list">
<?php foreach ($order as $w => $dayName): $h = $hours[$w]; ?>
            <li class="hours-row<?= $weekdays[$w] !== 'open' ? ' off' : '' ?>">
<?php if ($weekdays[$w] === 'open'): ?>
              <label class="hours-day"><input type="checkbox" name="days[<?= $w ?>][works]" value="1"<?= $h['works'] ? ' checked' : '' ?>> <?= e($dayName) ?></label>
              <input class="hours-time from" type="time" name="days[<?= $w ?>][from]" value="<?= e($h['from']) ?>" step="300" aria-label="<?= e($dayName) ?> desde">
              <span class="hours-sep" aria-hidden="true">a</span>
              <input class="hours-time to" type="time" name="days[<?= $w ?>][to]" value="<?= e($h['to']) ?>" step="300" aria-label="<?= e($dayName) ?> hasta">
<?php else: ?>
              <span class="hours-day"><?= e($dayName) ?></span>
              <span class="hours-note"><?= e($notes[$weekdays[$w]]) ?></span>
<?php endif; ?>
            </li>
<?php endforeach; ?>
          </ul>
          <button class="btn-small hours-save" type="submit">Guardar horario</button>
        </form>
<?php else: ?>
        <ul class="hours-list">
<?php foreach ($order as $w => $dayName): $h = $hours[$w]; $works = $weekdays[$w] === 'open' && $h['works']; ?>
          <li class="hours-row <?= $works ? 'ro' : 'off' ?>">
            <span class="hours-day"><?= e($dayName) ?></span>
<?php if ($works): ?>
            <span class="hours-range"><?= e($h['from']) ?> a <?= e($h['to']) ?></span>
<?php else: ?>
            <span class="hours-note"><?= e($notes[$weekdays[$w]] ?? 'No atiende') ?></span>
<?php endif; ?>
          </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
      </section>
<?php endforeach; ?>
    </div>
  </section>

<?php if ($canEdit): ?>
  <?php // Alta de un barbero (solo el administrador): aparece en la app al instante; la foto se recorta al cuadrado y se guarda como WebP. ?>
  <section class="admin-card svc-edit" id="nuevo-barbero" aria-labelledby="barber-new">
    <h2 id="barber-new">Nuevo barbero</h2>
    <form method="post" action="/admin/barberos/nuevo" enctype="multipart/form-data" class="svc-edit">
      <?= csrf_field() ?>
      <div class="svc-fields">
        <label class="wide">Nombre y apellido<input type="text" name="name" maxlength="80" required placeholder="Ej: Tomi Sosa"></label>
        <label class="wide">Especialidad<input type="text" name="role" maxlength="80" required placeholder="Ej: Diseños y freestyle"></label>
        <label>Años de oficio<input type="number" name="years" value="1" min="0" max="60" step="1" inputmode="numeric" required></label>
        <label>Valoración<input type="number" name="rating" value="4.8" min="1" max="5" step="0.1" inputmode="decimal" required></label>
        <label>Color del avatar<input class="bp-color" type="color" name="tone" value="#EB1716" required></label>
        <label class="wide">Foto (opcional)<input class="bp-file" type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
      </div>
      <p class="admin-sub">JPG, PNG o WebP de hasta 5 MB. Se recorta al cuadrado del centro: mejor una foto con la cara centrada.</p>
      <div class="svc-row-actions">
        <button class="btn-small" type="submit">Agregar barbero</button>
      </div>
    </form>
  </section>
<?php endif; ?>
</main>
