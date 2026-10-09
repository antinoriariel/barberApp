<?php /** components/topbar.php — Cabecera con el logo, el estado del local y el selector de tema claro/oscuro. Variables: $shopStatus (Shop::status()) */ ?>
<header class="topbar">
  <?= partial('components/brand', ['href' => '#inicio']) ?>
  <div class="topbar-end">
    <?php // Estado en dos renglones cortos ("Abierto" / "hasta las 21:30") para no apretar al logo en celular. ?>
    <span class="open-pill<?= $shopStatus['open'] ? '' : ' closed' ?>" aria-label="<?= e($shopStatus['label']) ?>">
      <i></i>
      <span class="open-text" aria-hidden="true">
        <b><?= $shopStatus['open'] ? 'Abierto' : 'Cerrado' ?></b>
        <?php if ($shopStatus['until'] !== ''): ?><small>hasta las <?= e($shopStatus['until']) ?></small><?php endif; ?>
      </span>
    </span>
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Cambiar a modo claro" title="Cambiar tema">
      <?= icon('sun', 'theme-ic-sun') ?><?= icon('moon', 'theme-ic-moon') ?>
    </button>
  </div>
</header>
