<?php
/**
 * pages/admin-services.php — Editor del catálogo para el administrador, en dos pestañas: Servicios (nombre,
 * descripción, precio y duración; la duración no se muestra al cliente, pero la agenda la usa para calcular
 * horarios) y Productos que se venden en el local (nombre, descripción y precio). En las dos se agregan (el
 * formulario de alta va primero), se editan y se ocultan/muestran.
 * Las pestañas son links (?tab=productos), así funcionan sin JavaScript y las acciones vuelven a la pestaña
 * en la que se estaba.
 * Un barbero con la sección habilitada ($canEdit = false) ve, con las mismas pestañas, solo la tabla de lo que
 * existe, sin formularios (el servidor rechaza igual cualquier cambio que no venga del administrador).
 * Con "Hora muerta" en curso ($salePct > 0) la pestaña Servicios avisa con la cuenta regresiva y muestra el precio
 * de lista tachado y el precio con el descuento de cada servicio (components/price.php), igual que lo ve el cliente;
 * los productos quedan igual. Al llegar a cero, countdown.js pone sale-over en <html> y el CSS lo vuelve a ocultar.
 *
 * Variables: $services (Service::allForAdmin()), $products (Product::allForAdmin()), $canEdit (bool: es admin),
 *            $tab ('servicios'|'productos'), $flash ('1'|''), $error (string),
 *            $deadHour (DeadHour::current() o null), $salePct (int, 0 sin descuento)
 */
$tabs = ['servicios' => ['Servicios', '/admin/servicios', count($services)], 'productos' => ['Productos', '/admin/servicios?tab=productos', count($products)]];
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Servicios y productos', 'sub' => $canEdit ? 'Los cambios se ven al instante en la app.' : 'Lo que ofrece la barbería. Los carga y edita el administrador.', 'sections' => $user->panelSections(), 'active' => 'servicios']) ?>

  <?php // Segmented control (.seg en admin.css), no .admin-tabs: es un filtro dentro de la sección, no otro menú. ?>
  <nav class="seg" aria-label="Catálogo">
<?php foreach ($tabs as $key => [$label, $href, $count]): ?>
    <a class="seg-item" href="<?= e($href) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="seg-count"><?= $count ?></span></a>
<?php endforeach; ?>
  </nav>

<?php if ($salePct > 0 && $tab === 'servicios'): ?>
  <p class="admin-flash svc-sale-note" role="status">
    <span class="dh-badge"><span class="dh-pulse" aria-hidden="true"></span>Hora muerta <?= $salePct ?>% OFF</span>
    Los servicios se muestran con el descuento (los productos no). Termina en
    <b data-countdown="<?= (int) $deadHour['ends'] * 1000 ?>" data-now="<?= time() * 1000 ?>" data-hide=".svc-sale-note" data-end-class="sale-over"
       role="timer" aria-live="off"><?= e(countdown_text($deadHour['ends'] - time())) ?></b>.
  </p>
<?php endif; ?>

<?php if (!$canEdit): ?>
<?php if ($tab === 'servicios'): ?>
  <section class="admin-card" aria-labelledby="svc-list">
    <h2 id="svc-list">Servicios</h2>
    <div class="table-wrap">
      <?php // .stack: en celular cada servicio es una tarjeta (nombre arriba, cada dato con su rótulo); tabla común desde 720px (ver admin.css). ?>
      <table class="admin-table stack svc-ro">
        <thead><tr><th>Servicio</th><th>Descripción</th><th class="num">Duración</th><th class="num">Precio</th><th>Estado</th></tr></thead>
        <tbody>
<?php foreach ($services as $s): ?>
          <tr>
            <td class="cell-main"><b><?= e($s['name']) ?></b></td>
            <td data-label="Descripción"><small><?= $s['description'] !== '' ? e($s['description']) : '—' ?></small></td>
            <td class="num" data-label="Duración"><?= $s['minutes'] ?> min</td>
            <td class="num" data-label="Precio"><b><?= partial('components/price', ['price' => $s['price'], 'pct' => $salePct]) ?></b></td>
            <td data-label="Estado"><span class="status <?= $s['active'] ? 'done' : 'cancelled' ?>"><?= $s['active'] ? 'Visible' : 'Oculto' ?></span></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php else: ?>
  <section class="admin-card" aria-labelledby="prod-list">
    <h2 id="prod-list">Productos</h2>
<?php if (!$products): ?>
    <p class="admin-empty">No hay productos cargados.</p>
<?php else: ?>
    <div class="table-wrap">
      <?php // .stack: en celular cada producto es una tarjeta (nombre arriba, cada dato con su rótulo); tabla común desde 720px (ver admin.css). ?>
      <table class="admin-table stack svc-ro">
        <thead><tr><th>Producto</th><th>Descripción</th><th class="num">Precio</th><th>Estado</th></tr></thead>
        <tbody>
<?php foreach ($products as $p): ?>
          <tr>
            <td class="cell-main"><b><?= e($p['name']) ?></b></td>
            <td data-label="Descripción"><small><?= $p['description'] !== '' ? e($p['description']) : '—' ?></small></td>
            <td class="num" data-label="Precio"><b><?= money($p['price']) ?></b></td>
            <td data-label="Estado"><span class="status <?= $p['active'] ? 'done' : 'cancelled' ?>"><?= $p['active'] ? 'Visible' : 'Oculto' ?></span></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>
  </section>
<?php endif; ?>
</main>
<?php return; endif; ?>

  <?php if ($flash === '1'): ?><p class="admin-flash ok" role="status">Cambios guardados.</p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if ($tab === 'servicios'): ?>
  <section class="admin-card svc-edit invert" aria-labelledby="svc-new">
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
      <?php if ($salePct > 0): ?><p class="svc-sale">Con hora muerta: <b><?= partial('components/price', ['price' => $s['price'], 'pct' => $salePct]) ?></b></p><?php endif; ?>
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
<?php else: ?>
  <p class="admin-sub">Se muestran en la pestaña Productos de la app y se pagan en el local.</p>

  <section class="admin-card svc-edit invert" aria-labelledby="prod-new">
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
<?php endif; ?>
</main>
