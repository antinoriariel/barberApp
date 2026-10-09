<?php
/**
 * components/service-card.php — Tarjeta de servicio del carrusel de Inicio.
 * Al tocarla abre Reservar con ese servicio elegido (data-book-service).
 * Variables: $service (Models\Service)
 */
?>
<button class="rail-card" data-book-service="<?= e($service->id) ?>">
  <span class="rail-price"><?= money($service->price) ?></span>
  <span class="rail-name"><?= e($service->name) ?></span>
</button>
