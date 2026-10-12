<?php
/**
 * screens/inicio.php — Pantalla de inicio (mobile first): saludo, turno, fidelidad,
 * servicios más pedidos, minijuegos plegados y datos del local.
 * Variables: $user, $shop (Shop), $loyalty (LoyaltyCard), $next (array|null), $queue (QueueTicket::currentFor|null), $popular (Service[]),
 *            $games (config/games.php), $rewards (premios vigentes del cliente)
 */
?>
<section class="view" id="view-inicio" data-title="Inicio" data-skel="inicio" hidden>
  <header class="home-title">
    <div data-track="inicio.saludo">
      <h1 class="hero-title">Hola, <?= e($user->name) ?></h1>
      <p class="home-sub">Qué bueno verte por acá.</p>
    </div>
    <?= partial('components/face-avatar', ['initials' => initials($user->fullName), 'href' => '#perfil', 'label' => 'Mi perfil', 'class' => 'home-avatar', 'track' => 'inicio.avatar']) ?>
  </header>

  <?php if ($queue): // número de la fila de hoy (QR del mostrador): va primero porque es lo más urgente ?>
    <?= partial('components/queue-ticket', ['ticket' => $queue]) ?>
  <?php endif; ?>

  <?= partial('components/ticket', ['appointment' => $next]) ?>

  <?= partial('components/loyalty', ['card' => $loyalty]) ?>

  <div class="section-head">
    <h2>Lo más pedido</h2>
    <a href="#servicios" class="link" data-track="inicio.ver-todos">Ver todos</a>
  </div>
  <div class="rail">
    <?php foreach ($popular as $service): ?>
      <?= partial('components/service-card', compact('service')) ?>
    <?php endforeach; ?>
  </div>

  <?= partial('components/arcade', compact('games', 'rewards')) ?>

  <div class="info-list" data-track="inicio.local">
    <p><span class="info-ic"><?= icon('pin') ?></span><?= e($shop->address) ?></p>
    <p><span class="info-ic"><?= icon('clock') ?></span><span><?= nl2br(e($shop->hours)) ?></span></p>
  </div>
</section>
