<?php
/**
 * components/brand.php — Logo: navaja roja en SVG + texto "BARBERSHOP" (se ve sobre fondo oscuro).
 * Variables: $href (opcional) — si viene, el logo es un enlace (en la app, '#inicio' vuelve al Inicio);
 * si no, es un bloque estático (pantallas de login/registro).
 */
$href = $href ?? null;
$tag = $href ? 'a' : 'div';
?>
<<?= $tag ?> class="brand"<?= $href ? ' href="' . e($href) . '" aria-label="Ir al inicio"' : '' ?>>
  <span class="barber-logo-icon" role="img" aria-label="Logo Barber"></span>
  <span class="brand-name">BARBERSHOP</span>
</<?= $tag ?>>
