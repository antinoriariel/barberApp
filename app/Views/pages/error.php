<?php
/**
 * pages/error.php — Página de error genérica (404, 405, 419, 429, 500…). La arma Core\Response::error().
 * Variables: $heading (string), $message (string, puede ir vacío). Nunca mostrar detalles internos acá.
 */
?>
<main style="min-height:100dvh;display:grid;place-content:center;gap:12px;padding:24px;text-align:center;background:#111111;color:#FFFFFF">
  <h1><?= e($heading) ?></h1>
  <?php if ($message !== ''): ?><p><?= e($message) ?></p><?php endif; ?>
  <p><a href="/" style="color:#EB1716;font-weight:600">Volver al inicio</a></p>
</main>
