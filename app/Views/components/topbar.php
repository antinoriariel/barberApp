<?php /** components/topbar.php — Cabecera con el logo, el estado del local, el botón de la guía (tour.js) y el selector de tema claro/oscuro. Variables: $shopStatus (Shop::status()) */ ?>
<header class="topbar">
  <?= partial('components/brand', ['href' => '#inicio', 'track' => 'topbar.logo']) ?>
  <div class="topbar-end">
    <?php // Estado en dos renglones cortos ("Abierto" / "hasta las 21:30") para no apretar al logo en celular. ?>
    <span data-track="topbar.estado" class="open-pill<?= $shopStatus['open'] ? '' : ' closed' ?>" aria-label="<?= e($shopStatus['label']) ?>">
      <i></i>
      <span class="open-text" aria-hidden="true">
        <b><?= $shopStatus['open'] ? 'Abierto' : 'Cerrado' ?></b>
        <?php if ($shopStatus['until'] !== ''): ?><small>hasta las <?= e($shopStatus['until']) ?></small><?php endif; ?>
      </span>
    </span>
    <?php // Modo guía: recorrido por las funciones de la app (assets/js/tour.js, con driver.js). ?>
    <button type="button" data-track="topbar.guia" class="theme-toggle tour-open" id="tour-open" data-tour-start aria-label="Ver la guía de la app" title="Guía de la app">
      <?= icon('help') ?>
    </button>
    <button type="button" data-track="topbar.tema" class="theme-toggle" id="theme-toggle" aria-label="Cambiar a modo claro" title="Cambiar tema">
      <?= icon('sun', 'theme-ic-sun') ?><?= icon('moon', 'theme-ic-moon') ?>
    </button>
  </div>
</header>
