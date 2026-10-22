<?php
/**
 * pages/admin-services.php — Editor del catálogo para el administrador: cambia nombre, descripción, precio y
 * duración (la duración no se muestra al cliente, pero la agenda la usa para calcular horarios), oculta o muestra
 * servicios y agrega nuevos (el formulario de alta va primero). Debajo, los productos que se venden en el local
 * (nombre, descripción y precio; se agregan, editan y ocultan igual que los servicios).
 * Un barbero con la sección habilitada ($canEdit = false) ve solo dos tablas de lo que existe, sin formularios
 * (el servidor rechaza igual cualquier cambio que no venga del administrador).
 *
 * Variables: $services (Service::allForAdmin()), $products (Product::allForAdmin()), $canEdit (bool: es admin),
 *            $flash ('1'|''), $error (string)
 */
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Servicios y productos', 'sub' => $canEdit ? 'Los cambios se ven al instante en la app.' : 'Lo que ofrece la barbería. Los carga y edita el administrador.', 'sections' => $user->panelSections(), 'active' => 'servicios']) ?>

<?php if (!$canEdit): ?>
  <section class="admin-card" aria-labelledby="svc-list">
    <h2 id="svc-list">Servicios</h2>
    <div class="table-wrap">
      <table class="admin-table stack">
        <thead><tr><th>Servicio</th><th class="num">Duración</th><th class="num">Precio</th><th>Estado</th></tr></thead>
        <tbody>
<?php foreach ($services as $s): ?>
          <tr>
            <td class="cell-main"><b><?= e($s['name']) ?></b><?php if ($s['description'] !== ''): ?><br><small><?= e($s['description']) ?></small><?php endif; ?></td>
            <td class="num" data-label="Duración"><?= $s['minutes'] ?> min</td>
            <td class="num" data-label="Precio"><b><?= money($s['price']) ?></b></td>
            <td data-label="Estado"><span class="status <?= $s['active'] ? 'done' : 'cancelled' ?>"><?= $s['active'] ? 'Visible' : 'Oculto' ?></span></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="admin-card" aria-labelledby="prod-list">
    <h2 id="prod-list">Productos</h2>
<?php if (!$products): ?>
    <p class="admin-empty">No hay productos cargados.</p>
<?php else: ?>
    <div class="table-wrap">
      <table class="admin-table stack">
        <thead><tr><th>Producto</th><th class="num">Precio</th><th>Estado</th></tr></thead>
        <tbody>
<?php foreach ($products as $p): ?>
          <tr>
            <td class="cell-main"><b><?= e($p['name']) ?></b><?php if ($p['description'] !== ''): ?><br><small><?= e($p['description']) ?></small><?php endif; ?></td>
            <td class="num" data-label="Precio"><b><?= money($p['price']) ?></b></td>
            <td data-label="Estado"><span class="status <?= $p['active'] ? 'done' : 'cancelled' ?>"><?= $p['active'] ? 'Visible' : 'Oculto' ?></span></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>
  </section>
</main>
<?php return; endif; ?>

  <?php if ($flash === '1'): ?><p class="admin-flash ok" role="status">Cambios guardados.</p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>

  <h2 class="admin-section-title">Servicios</h2>

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

  <h2 class="admin-section-title" id="productos">Productos</h2>
  <p class="admin-sub">Se muestran en la pestaña Productos de la app y se pagan en el local.</p>

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
</main>
