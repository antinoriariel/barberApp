<?php
/**
 * components/service-card.php — Tarjeta de servicio del carrusel de Inicio.
 * Al tocarla abre Reservar con ese servicio elegido (data-book-service).
 * Variables: $service (Models\Service), $salePct (opcional: % de la hora muerta en curso, tacha el precio)
 */
?>
<button class="rail-card" data-book-service="<?= e($service->id) ?>">
  <span class="rail-price"><?= partial('components/price', ['price' => $service->price, 'pct' => $salePct ?? 0]) ?></span>
  <span class="rail-name"><?= e($service->name) ?></span>
</button>
