<?php
/**
 * components/service-card.php — Tarjeta de servicio del carrusel de Inicio.
 * Al tocarla abre Reservar con ese servicio elegido (data-book-service).
 * Variables: $service (Models\Service)
 */
?>
<button class="rail-card" data-book-service="<?= e($service->id) ?>" data-track="inicio.popular" data-track-item="<?= e($service->id) ?>" data-track-to="#reservar">
  <span class="rail-price"><?= money($service->price) ?></span>
  <span class="rail-name"><?= e($service->name) ?></span>
</button>
