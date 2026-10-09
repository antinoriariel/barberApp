<?php
/**
 * pages/shell.php — Página de la app: marco + todas las pantallas.
 * navigation.js (cargado por main.js) muestra una pantalla por vez según el hash (#inicio, #servicios…).
 *
 * Variables: $user, $shop, $loyalty, $next, $queue (número de la fila o null), $history, $services, $popular,
 *            $games, $rewards, $barbers, $days, $slots (las arma ShellController).
 */
?>
<div class="app">
  <?= partial('components/topbar', ['shopStatus' => \App\Models\Shop::status()]) ?>

  <main>
    <?= partial('components/skeletons') ?>

    <?= partial('screens/inicio', compact('user', 'shop', 'loyalty', 'next', 'popular', 'games', 'rewards', 'queue')) ?>
    <?= partial('screens/servicios', compact('services', 'products')) ?>
    <?= partial('screens/reservar', compact('services', 'barbers', 'days', 'slots')) ?>
    <?= partial('screens/barberos', compact('barbers')) ?>
    <?= partial('screens/perfil', compact('user', 'loyalty', 'history')) ?>
  </main>

  <?= partial('components/tabbar', compact('user')) ?>
  <?= partial('components/qr-scanner') ?>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>
</div>
