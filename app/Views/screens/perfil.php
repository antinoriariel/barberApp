<?php
/**
 * screens/perfil.php — Perfil: datos de la cuenta, resumen de visitas, historial y ajustes.
 * Variables: $user (User), $loyalty (LoyaltyCard), $history (array de ['service','barber','date','price']),
 *            $deadHour (DeadHour::current() o null: aviso de descuento con cuenta regresiva)
 */
?>
<section class="view" id="view-perfil" data-title="Perfil" data-skel="perfil" hidden>
  <header class="me-card">
    <?= partial('components/face-avatar', ['initials' => initials($user->fullName), 'class' => 'big']) ?>
    <div class="me-text">
      <h1><?= e($user->fullName) ?></h1>
      <p>DNI <?= e(fmt_dni($user->dni)) ?></p>
      <?php if ($user->phone !== ''): ?><p><?= e(fmt_phone($user->phone)) ?></p><?php endif; ?>
    </div>
    <dl class="me-stats">
      <div><dt>Visitas</dt><dd><?= $loyalty->visits ?></dd></div>
      <div>
        <dt>Corte gratis</dt>
        <dd><?= $loyalty->isComplete() ? '¡Listo!' : 'Faltan ' . $loyalty->visitsLeft() ?></dd>
      </div>
    </dl>
  </header>

  <?php if ($deadHour): ?>
    <?= partial('components/dead-hour', ['promo' => $deadHour]) ?>
  <?php endif; ?>

  <div class="section-head"><h2>Últimas visitas</h2></div>
  <?php if (!$history): ?>
    <p class="prof-empty">Todavía no tenés visitas. Cuando te atendamos, van a aparecer acá.</p>
  <?php else: ?>
    <ul class="visits">
      <?php foreach ($history as $h): ?>
        <li class="visit">
          <span class="visit-ic"><?= icon('scissors') ?></span>
          <span class="visit-text">
            <strong><?= e($h['service']) ?></strong>
            <small><?= e($h['barber']) ?> · <?= e($h['date']) ?></small>
          </span>
          <b class="visit-price"><?= money($h['price']) ?></b>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="section-head"><h2>Ajustes</h2></div>
  <ul class="prof-list">
    <?php if ($user->hasPanel()): ?>
      <li>
        <a class="prof-row" href="/admin">
          <span class="prof-ic"><?= icon('home') ?></span>
          <span class="prof-text"><strong><?= $user->isAdmin ? 'Panel de administración' : 'Mi semana' ?></strong><small><?= $user->isAdmin ? 'Turnos, ingresos y servicios' : 'Tus cortes de la semana' ?></small></span>
        </a>
      </li>
    <?php endif; ?>
    <li>
      <button class="prof-row" type="button" data-toast="Avisos activados: te recordamos 2 horas antes">
        <span class="prof-ic"><?= icon('bell') ?></span>
        <span class="prof-text"><strong>Avisos de turno</strong><small>Te recordamos 2 horas antes</small></span>
      </button>
    </li>
    <li>
      <?php // Vuelve a abrir el modo guía (assets/js/tour.js). ?>
      <button class="prof-row" type="button" data-tour-start>
        <span class="prof-ic"><?= icon('help') ?></span>
        <span class="prof-text"><strong>Guía de la app</strong><small>Te mostramos para qué sirve cada cosa</small></span>
      </button>
    </li>
    <li>
      <form class="inline-form" method="post" action="/logout">
        <?= csrf_field() ?>
        <button class="prof-row" type="submit">
          <span class="prof-ic"><?= icon('logout') ?></span>
          <span class="prof-text"><strong>Cerrar sesión</strong></span>
        </button>
      </form>
    </li>
    <li>
      <form class="inline-form" method="post" action="/logout-todo">
        <?= csrf_field() ?>
        <button class="prof-row danger" type="submit">
          <span class="prof-ic"><?= icon('logout') ?></span>
          <span class="prof-text"><strong>Cerrar sesión en todos los dispositivos</strong><small>Útil si perdiste un teléfono o usaste una PC ajena</small></span>
        </button>
      </form>
    </li>
  </ul>
</section>
