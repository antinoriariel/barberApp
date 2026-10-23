<?php
/**
 * components/tabbar.php — Navegación principal.
 * Móvil: barra inferior con el círculo central (escanear el QR del local para tomar número en la fila).
 * Escritorio: barra lateral (el CSS la reacomoda); .side-user solo se ve ahí.
 *
 * Variables: $user (User)
 */
?>
<nav class="tabbar" aria-label="Navegación principal">
  <a href="#inicio" class="tab" data-tab="inicio" data-track="tabbar.inicio"><?= icon('home') ?><span>Inicio</span></a>
  <a href="#servicios" class="tab" data-tab="servicios" data-track="tabbar.servicios"><?= icon('scissors') ?><span>Servicios</span></a>
  <?php // Círculo central: abre la cámara para escanear el QR del mostrador y tomar número (components/qr-scanner, js/scanner.js). ?>
  <button type="button" class="fab" id="scan-open" data-track="tabbar.qr" aria-label="Escanear el QR del local para tomar número">
    <span class="fab-disc"><?= icon('qr') ?></span>
    <span class="fab-label">Tomar número</span>
    <?php // Aviso flotante al pasar el mouse (solo CSS, ver .fab-tip en tabbar.css). ?>
    <span class="fab-tip" aria-hidden="true">Reserva de turno</span>
  </button>
  <a href="#barberos" class="tab" data-tab="barberos" data-track="tabbar.barberos"><?= icon('chair') ?><span>Barberos</span></a>
  <a href="#perfil" class="tab" data-tab="perfil" data-track="tabbar.perfil"><?= icon('user') ?><span>Perfil</span></a>

  <div class="side-user">
    <span class="avatar sm" style="--tone:#EB1716"><?= initials($user->fullName) ?></span>
    <span class="side-user-text"><strong><?= e($user->name) ?></strong><small>DNI <?= e(fmt_dni($user->dni)) ?></small></span>
    <form class="inline-form" method="post" action="/logout">
      <?= csrf_field() ?>
      <button type="submit" class="side-logout" aria-label="Cerrar sesión" title="Cerrar sesión"><?= icon('logout') ?></button>
    </form>
  </div>
</nav>
