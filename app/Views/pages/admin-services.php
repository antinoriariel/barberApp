<?php
/**
 * pages/admin-services.php — Editor del catálogo para el administrador: cambia nombre, descripción, precio y
 * duración (la duración no se muestra al cliente, pero la agenda la usa para calcular horarios), oculta o muestra
 * servicios y agrega nuevos. Debajo, los productos que se venden en el local (nombre, descripción y precio; se
 * agregan, editan y ocultan igual que los servicios).
 *
 * Variables: $services (Service::allForAdmin()), $products (Product::allForAdmin()), $flash ('1'|''), $error (string)
 */
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Servicios y productos', 'sub' => 'Los cambios se ven al instante en la app.', 'active' => 'servicios']) ?>

  <?php if ($flash === '1'): ?><p class="admin-flash ok" role="status">Cambios guardados.</p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>

  <h2 class="admin-section-title">Servicios</h2>
  <?php foreach ($services as $s): ?>
    <section class="admin-card svc-edit<?= $s['active'] ? '' : ' off' ?>" aria-label="<?= e($s['name']) ?>">
      <div class="svc-edit-head">
        <strong><?= e($s['name']) ?></strong>
        <?php /* El propio badge es el interruptor: un click oculta el servicio del catálogo del cliente o lo vuelve a mostrar. */ ?>
        <form method="post" action="/admin/servicios/estado" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button type="submit" name="active" value="<?= $s['active'] ? '0' : '1' ?>"
                  class="status toggle <?= $s['active'] ? 'done' : 'cancelled' ?>"
                  title="<?= $s['active'] ? 'Click para ocultarlo del catálogo' : 'Click para mostrarlo en el catálogo' ?>"
                  aria-label="<?= e($s['name']) ?>: <?= $s['active'] ? 'visible, ocultar' : 'oculto, mostrar' ?>"><?= $s['active'] ? 'Visible' : 'Oculto' ?></button>
        </form>
      </div>
      <form method="post" action="/admin/servicios/guardar" class="svc-edit">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $s['id'] ?>">
        <div class="svc-fields">
          <label class="wide">Nombre<input type="text" name="name" value="<?= e($s['name']) ?>" maxlength="80" required></label>
          <label class="wide">Descripción<input type="text" name="description" value="<?= e($s['description']) ?>" maxlength="190"></label>
          <label>Precio ($)<input type="number" name="price" value="<?= $s['price'] ?>" min="0" max="10000000" step="1" inputmode="numeric" required></label>
          <label>Duración (min, agenda)<input type="number" name="minutes" value="<?= $s['minutes'] ?>" min="5" max="480" step="5" inputmode="numeric" required></label>
        </div>
        <div class="svc-row-actions">
          <button class="btn-small" type="submit">Guardar</button>
        </div>
      </form>
    </section>
  <?php endforeach; ?>

  <section class="admin-card svc-edit" aria-labelledby="svc-new">
    <h2 id="svc-new">Agregar servicio</h2>
    <form method="post" action="/admin/servicios/guardar" class="svc-edit">
      <?= csrf_field() ?>
      <div class="svc-fields">
        <label class="wide">Nombre<input type="text" name="name" maxlength="80" required placeholder="Ej: Corte infantil"></label>
        <label class="wide">Descripción<input type="text" name="description" maxlength="190" placeholder="Qué incluye"></label>
        <label>Precio ($)<input type="number" name="price" min="0" max="10000000" step="1" inputmode="numeric" required></label>
        <label>Duración (min, agenda)<input type="number" name="minutes" value="30" min="5" max="480" step="5" inputmode="numeric" required></label>
      </div>
      <div class="svc-row-actions">
        <button class="btn-small" type="submit">Agregar</button>
      </div>
    </form>
  </section>

  <h2 class="admin-section-title" id="productos">Productos</h2>
  <p class="admin-sub">Se muestran en la pestaña Productos de la app y se pagan en el local.</p>
  <?php foreach ($products as $p): ?>
    <section class="admin-card svc-edit<?= $p['active'] ? '' : ' off' ?>" id="producto-<?= $p['id'] ?>" aria-label="<?= e($p['name']) ?>">
      <div class="svc-edit-head">
        <strong><?= e($p['name']) ?></strong>
        <form method="post" action="/admin/productos/estado" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $p['id'] ?>">
          <button type="submit" name="active" value="<?= $p['active'] ? '0' : '1' ?>"
                  class="status toggle <?= $p['active'] ? 'done' : 'cancelled' ?>"
                  title="<?= $p['active'] ? 'Click para ocultarlo de la app' : 'Click para mostrarlo en la app' ?>"
                  aria-label="<?= e($p['name']) ?>: <?= $p['active'] ? 'visible, ocultar' : 'oculto, mostrar' ?>"><?= $p['active'] ? 'Visible' : 'Oculto' ?></button>
        </form>
      </div>
      <form method="post" action="/admin/productos/guardar" class="svc-edit">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $p['id'] ?>">
        <div class="svc-fields">
          <label class="wide">Nombre<input type="text" name="name" value="<?= e($p['name']) ?>" maxlength="80" required></label>
          <label class="wide">Descripción<input type="text" name="description" value="<?= e($p['description']) ?>" maxlength="190"></label>
          <label>Precio ($)<input type="number" name="price" value="<?= $p['price'] ?>" min="0" max="10000000" step="1" inputmode="numeric" required></label>
        </div>
        <div class="svc-row-actions">
          <button class="btn-small" type="submit">Guardar</button>
        </div>
      </form>
    </section>
  <?php endforeach; ?>

  <section class="admin-card svc-edit" aria-labelledby="prod-new">
    <h2 id="prod-new">Agregar producto</h2>
    <form method="post" action="/admin/productos/guardar" class="svc-edit">
      <?= csrf_field() ?>
      <div class="svc-fields">
        <label class="wide">Nombre<input type="text" name="name" maxlength="80" required placeholder="Ej: Shampoo para barba"></label>
        <label class="wide">Descripción<input type="text" name="description" maxlength="190" placeholder="Para qué sirve"></label>
        <label>Precio ($)<input type="number" name="price" min="0" max="10000000" step="1" inputmode="numeric" required></label>
      </div>
      <div class="svc-row-actions">
        <button class="btn-small" type="submit">Agregar</button>
      </div>
    </form>
  </section>
</main>
