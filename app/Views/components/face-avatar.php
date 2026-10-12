<?php
/**
 * components/face-avatar.php — Avatar ilustrado: la ilustración de barbero con aro rojo de neón
 * (public/img/avatar-barbero.webp, sacada de img/imagNombre.jfif sin las letras) y encima, en el rostro,
 * las iniciales del cliente. Es un enlace si se pasa $href. El tamaño lo da la clase (ver crew.css).
 *
 * Variables: $initials (string, ej. "MF"), $href (opcional), $label (aria-label, opcional), $class (opcional),
 *            $track (opcional: código data-track del mapa de calor, config/tracking.php)
 */
$href = $href ?? null;
$tag = $href ? 'a' : 'span';
?>
<<?= $tag ?> class="face-avatar <?= e($class ?? '') ?>"<?= !empty($track) ? ' data-track="' . e($track) . '"' : '' ?><?= $href ? ' href="' . e($href) . '"' : '' ?><?= !empty($label) ? ' aria-label="' . e($label) . '"' : '' ?>>
  <img src="/img/avatar-barbero.webp" alt="" width="320" height="320" decoding="async">
  <span class="face-initials" aria-hidden="true"><?= e($initials) ?></span>
</<?= $tag ?>>
