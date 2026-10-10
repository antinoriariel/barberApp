<?php
/**
 * pages/error.php — Página de error con la impronta de la barbería (404, 405, 419, 429, 500…). La arma Core\Response::error().
 * Variables: $status (int, código HTTP), $heading (string), $message (string, puede ir vacío). Nunca mostrar detalles internos acá.
 *
 * El código se dibuja en grande con la tipografía de títulos; en un 404 el "0" es un poste de barbero girando
 * (CSS puro, se detiene con prefers-reduced-motion). Debajo, una "línea de corte" punteada con tijera, como los tickets.
 * Si el 404 no trae mensaje se usa uno propio de la casa.
 */
$status = (int) ($status ?? 500);
$digits = str_split((string) $status);
$is404 = $status === 404;
if ($message === '' && $is404) {
    $message = 'Esta página se fue sin sacar turno. Acá no queda nada para cortar.';
}
?>
<main class="error">
  <?= partial('components/brand', ['href' => '/']) ?>

  <section class="error-card" aria-labelledby="error-title">
    <p class="error-code" aria-label="Error <?= e((string) $status) ?>">
      <?php foreach ($digits as $i => $d): ?>
        <?php if ($is404 && $i === 1): ?>
          <span class="error-pole" aria-hidden="true"><span class="error-pole-stripes"></span></span>
        <?php else: ?>
          <span aria-hidden="true"><?= e($d) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </p>

    <div class="error-cut" aria-hidden="true">
      <svg class="error-scissors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/>
        <path d="M20 4 8.12 15.88M14.47 14.48 20 20M8.12 8.12 12 12"/>
      </svg>
    </div>

    <h1 id="error-title"><?= e($heading) ?></h1>
    <?php if ($message !== ''): ?><p class="error-message"><?= e($message) ?></p><?php endif; ?>

    <div class="error-actions">
      <a class="btn-primary block" href="/">Volver al inicio</a>
      <?php if ($is404): ?><a class="error-link" href="/#reservar">Mejor reservá tu turno</a><?php endif; ?>
    </div>
  </section>

  <p class="error-foot">Navaja &amp; Co. · Barbershop</p>
</main>
