<?php
/**
 * screens/servicios.php — Catálogo en dos pestañas: Servicios (se reservan) y Productos (estáticos, se compran
 * en el local). El cambio de pestaña lo hace assets/js/catalog.js; sin JS se ve solo Servicios.
 * Variables: $services (Service[]), $products (Product[])
 */
?>
<section class="view" id="view-servicios" data-title="Servicios" data-skel="list" hidden>
  <h1 class="page-title">Servicios</h1>

  <div class="catalog-tabs" role="tablist" aria-label="Categorías">
    <button type="button" class="catalog-tab" role="tab" id="tab-servicios" aria-controls="panel-servicios" aria-selected="true" data-track="servicios.tab-servicios">Servicios</button>
    <button type="button" class="catalog-tab" role="tab" id="tab-productos" aria-controls="panel-productos" aria-selected="false" tabindex="-1" data-track="servicios.tab-productos">Productos</button>
  </div>

  <div class="catalog-panel" id="panel-servicios" role="tabpanel" aria-labelledby="tab-servicios">
    <p class="svc-intro">Elegí lo que necesitás y reservá tu turno en un toque.</p>
    <ul class="svc-grid">
      <?php foreach ($services as $s): ?>
        <li>
          <button class="svc-card" data-book-service="<?= e($s->id) ?>" data-track="servicios.reservar" data-track-item="<?= e($s->id) ?>" data-track-to="#reservar">
            <span class="svc-icon"><?= icon('scissors') ?></span>
            <span class="svc-text">
              <strong><?= e($s->name) ?></strong>
              <small><?= e($s->description) ?></small>
            </span>
            <span class="svc-foot">
              <b class="svc-price"><?= money($s->price) ?></b>
              <span class="svc-cta">Reservar <?= icon('plus') ?></span>
            </span>
          </button>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="catalog-panel" id="panel-productos" role="tabpanel" aria-labelledby="tab-productos" hidden>
    <p class="svc-intro">Para mantener tu peinado en casa. Pedilos en el mostrador cuando vengas.</p>
    <ul class="svc-grid">
      <?php foreach ($products as $p): ?>
        <li>
          <article class="svc-card product-card" data-track="servicios.producto" data-track-item="<?= e($p->id) ?>">
            <span class="svc-icon"><?= icon('jar') ?></span>
            <span class="svc-text">
              <strong><?= e($p->name) ?></strong>
              <small><?= e($p->description) ?></small>
            </span>
            <span class="svc-foot">
              <b class="svc-price"><?= money($p->price) ?></b>
              <span class="product-tag"><?= icon('pin') ?> En el local</span>
            </span>
          </article>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
