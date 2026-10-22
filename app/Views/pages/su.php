<?php
/**
 * pages/su.php — Panel del superusuario, pestaña Cuentas. Arriba los números (cuentas, en línea, de baja, bloqueos):
 * cada uno es un enlace; "En línea" y "De baja" filtran la lista de abajo (?ver=), los bloqueos llevan a su sección.
 * Después el bloqueo de IP (con duración) y las listas de IP y navegadores bloqueados con sus intentos rechazados;
 * debajo un buscador y una tarjeta por cuenta con su última conexión, cada navegador desde el que entró (IP, user agent
 * completo, primera y última vez, con Permaban y bloqueo de IP por navegador), sus últimas 3 acciones (con enlace a su
 * historial completo en Auditoría) y las herramientas: editar, contraseña, cerrar sesiones, Permaban total y eliminar.
 * El buscador, las confirmaciones y la validación los da js/admin-users.js (mismos data-* que /admin/usuarios).
 *
 * Variables: $accounts (cuentas a listar, ya filtradas por $listView), $total, $onlineCount, $inactive (int),
 *            $listView ('' | clave de SuperController::VIEWS), $devices (UserActivity::devicesByUser()),
 *            $actions (UserActivity::recentActions()), $history (User::historyCounts()), $ipBans (Ban::ips()),
 *            $deviceBans (Ban::devices()), $bannedSet (Ban::deviceSet()), $barbers (Barber::team()), $myIp (string),
 *            $myDevice (sha256 del navegador del superusuario), $onlineFrom ('Y-m-d H:i:s': visto desde entonces = en línea),
 *            $notice (aviso de éxito, '' si no hay), $error (string)
 */
use App\Controllers\SuperController;
use App\Models\Ban;
use App\Models\User;
use App\Models\UserActivity;

$barberNames = [];
foreach ($barbers as $b) {
    $barberNames[$b->key] = $b->name;
}
$minPassword = (int) \App\Core\Config::get('auth.min_password');
$maxPassword = \App\Support\Password::MAX;
$when = static fn (?string $dt): string => $dt ? date('d/m/Y H:i', strtotime($dt)) : '—';
$hits = static fn (array $ban): string => (int) $ban['hits'] > 0
    ? 'rechazado ' . (int) $ban['hits'] . ' ' . ((int) $ban['hits'] === 1 ? 'vez' : 'veces') . ', la última ' . date('d/m H:i', strtotime($ban['last_hit_at']))
    : 'sin intentos desde que se bloqueó';
?>
<main class="admin su">
  <?= partial('components/admin-header', ['heading' => 'Superusuario', 'sub' => 'Todas las cuentas, conexiones y bloqueos', 'sections' => ['su', 'su-registros', 'su-seguridad', 'su-auditoria'], 'active' => 'su']) ?>

  <?php if ($notice !== '' || $error !== ''): ?>
  <div class="admin-flashes" id="aviso">
    <?php if ($notice !== ''): ?><p class="admin-flash ok" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php // Cada número es un enlace: filtra la lista de cuentas o baja a su sección. ?>
  <nav class="admin-kpis su-kpis" aria-label="Resumen">
    <a class="kpi<?= $listView === '' ? ' is-current' : '' ?>" href="/su#cuentas"><h2>Cuentas</h2><p class="kpi-value"><?= $total ?></p><p class="kpi-prev">ver todas</p></a>
    <a class="kpi<?= $listView === 'en-linea' ? ' is-current' : '' ?>" href="/su?ver=en-linea#cuentas"><h2>En línea</h2><p class="kpi-value"><?= $onlineCount ?></p><p class="kpi-prev">vistas en los últimos 5 minutos</p></a>
    <a class="kpi<?= $listView === 'de-baja' ? ' is-current' : '' ?>" href="/su?ver=de-baja#cuentas"><h2>De baja</h2><p class="kpi-value"><?= $inactive ?></p><p class="kpi-prev">cuentas suspendidas</p></a>
    <a class="kpi" href="/su#su-ip"><h2>IP bloqueadas</h2><p class="kpi-value"><?= count($ipBans) ?></p><p class="kpi-prev">vigentes</p></a>
    <a class="kpi" href="/su#su-dev"><h2>Permaban</h2><p class="kpi-value"><?= count($deviceBans) ?></p><p class="kpi-prev">navegadores bloqueados</p></a>
  </nav>

  <section class="admin-card svc-edit" aria-labelledby="su-ip" id="su-ip">
    <h2>Bloquear una IP</h2>
    <form method="post" action="/su/ip/bloquear" class="svc-edit">
      <?= csrf_field() ?>
      <div class="svc-fields">
        <label>IP<input type="text" name="ip" maxlength="45" required autocomplete="off" placeholder="Ej: 181.45.12.9"></label>
        <label>Duración
          <select name="duration">
          <?php foreach (Ban::DURATIONS as $key => [, $label]): ?>
            <option value="<?= e($key) ?>"<?= $key === '24h' ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
          </select>
        </label>
        <label class="wide">Motivo<input type="text" name="reason" maxlength="255" autocomplete="off" placeholder="Opcional"></label>
      </div>
      <p class="perm-note">Tu IP actual es <?= e($myIp) ?> (no se puede bloquear). Si la barbería está detrás de un proxy o todos comparten el wifi del local, bloquear esa IP deja afuera a todos los que la usan: preferí un bloqueo temporal.</p>
      <div class="svc-row-actions"><button class="btn-small danger" type="submit">Bloquear IP</button></div>
    </form>

    <?php if ($ipBans): ?>
      <ul class="su-list">
      <?php foreach ($ipBans as $ban): ?>
        <li>
          <div>
            <strong><?= e($ban['ip']) ?> · <?= $ban['expires_at'] ? 'hasta ' . e($when($ban['expires_at'])) : 'permanente' ?></strong>
            <small><?= e($ban['reason'] ?: 'Sin motivo') ?> · desde <?= e($when($ban['created_at'])) ?></small>
            <small><?= e($hits($ban)) ?></small>
          </div>
          <div class="su-row-actions">
            <a class="btn-small ghost" href="/su/auditoria?ip=<?= rawurlencode($ban['ip']) ?>">Ver actividad</a>
            <form method="post" action="/su/ip/desbloquear" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="ip" value="<?= e($ban['ip']) ?>">
              <button class="btn-small ghost" type="submit">Desbloquear</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="admin-card" aria-labelledby="su-dev-title" id="su-dev">
    <h2 id="su-dev-title">Navegadores con Permaban</h2>
    <?php if (!$deviceBans): ?>
      <p class="admin-empty">Ninguno. El Permaban se aplica desde la tarjeta de cada cuenta.</p>
    <?php else: ?>
      <ul class="su-list">
      <?php foreach ($deviceBans as $ban): ?>
        <li>
          <div>
            <strong><?= e(UserActivity::browser($ban['user_agent'])) ?> · <?= e($ban['full_name'] ?? 'cuenta eliminada') ?></strong>
            <small class="su-ua"><?= e($ban['user_agent'] ?: 'Sin user agent') ?></small>
            <small>Navegador <?= e(substr($ban['device_hash'], 0, 10)) ?> · desde <?= e($when($ban['created_at'])) ?> · <?= e($hits($ban)) ?></small>
          </div>
          <form method="post" action="/su/permaban/quitar" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="device" value="<?= e($ban['device_hash']) ?>">
            <button class="btn-small ghost" type="submit">Quitar</button>
          </form>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <div class="su-list-head" id="cuentas">
    <h2 class="admin-section-title"><?= e($listView !== '' ? SuperController::VIEWS[$listView] : 'Cuentas') ?> (<?= count($accounts) ?>)</h2>
    <?php if ($listView !== ''): ?><a class="btn-small ghost" href="/su#cuentas">Ver todas</a><?php endif; ?>
  </div>
  <input class="user-search" type="search" placeholder="Buscar por nombre, DNI, teléfono o IP…" aria-label="Buscar cuenta" data-user-search autocomplete="off">
  <p class="admin-empty" data-user-none hidden>Ninguna cuenta coincide con la búsqueda.</p>
  <?php if (!$accounts): ?>
    <p class="admin-empty"><?= $listView === 'en-linea' ? 'Nadie usó la app en los últimos 5 minutos.' : 'No hay cuentas para mostrar.' ?></p>
  <?php endif; ?>

  <?php foreach ($accounts as $a): ?>
    <?php
    $list = $devices[$a->id] ?? [];
    $last = $list[0] ?? null;
    $isOnline = $last !== null && $last['last_seen_at'] >= $onlineFrom;
    $ips = array_values(array_unique(array_column($list, 'ip')));
    $records = $history[$a->id] ?? 0;
    ?>
    <section class="admin-card svc-edit user-card<?= $a->active ? '' : ' off' ?>" id="usuario-<?= $a->id ?>" aria-label="<?= e($a->fullName) ?>"
             data-user-text="<?= e(mb_strtolower($a->fullName . ' ' . $a->dni . ' ' . $a->phone . ' ' . implode(' ', $ips))) ?>">
      <div class="svc-edit-head">
        <div class="user-id">
          <strong><?= e($a->fullName) ?><?php if ($isOnline): ?> <span class="status done su-online">en línea</span><?php endif; ?></strong>
          <small>
            DNI <?= e(\App\Support\Dni::format($a->dni)) ?> · <?= e(User::ROLES[$a->role()]) ?>
            <?php if ($a->isBarber()): ?> · <?= e($barberNames[$a->barberId] ?? 'oculto en la app') ?><?php endif; ?>
            <?php if ($a->phone !== ''): ?> · <?= e(fmt_phone($a->phone)) ?><?php endif; ?>
          </small>
          <small>
            Última conexión: <?= e($when($last['last_seen_at'] ?? null)) ?>
            <?php if ($last): ?> · IP <?= e($last['ip']) ?> · <span title="<?= e($last['user_agent']) ?>"><?= e(UserActivity::browser($last['user_agent'])) ?></span><?php endif; ?>
          </small>
        </div>
        <form method="post" action="/su/usuarios/estado" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $a->id ?>">
          <button type="submit" name="active" value="<?= $a->active ? '0' : '1' ?>"
                  class="status toggle <?= $a->active ? 'done' : 'cancelled' ?>"
                  <?= $a->active ? 'data-confirm="¿Dar de baja a ' . e($a->fullName) . '? No va a poder entrar hasta que la reactives."' : '' ?>
                  title="<?= $a->active ? 'Click para dar de baja' : 'Click para reactivar' ?>"
                  aria-label="<?= e($a->fullName) ?>: <?= $a->active ? 'activa, dar de baja' : 'dada de baja, reactivar' ?>"><?= $a->active ? 'Activa' : 'De baja' ?></button>
        </form>
      </div>

      <div class="su-block">
        <div class="su-block-head">
          <h3>Últimas acciones</h3>
          <a class="link" href="/su/auditoria?usuario=<?= $a->id ?>">Historial completo</a>
        </div>
        <?php if (empty($actions[$a->id])): ?>
          <p class="admin-empty">Sin acciones registradas.</p>
        <?php else: ?>
          <ol class="su-actions">
          <?php foreach ($actions[$a->id] as $act): ?>
            <li>
              <strong><?= e(UserActivity::label($act['event'])) ?></strong>
              <small><?= e($when($act['created_at'])) ?> · IP <?= e($act['ip']) ?><?php if ($act['user_agent']): ?> · <span title="<?= e($act['user_agent']) ?>"><?= e(UserActivity::browser($act['user_agent'])) ?></span><?php endif; ?><?php if ($act['detail']): ?> · <?= e($act['detail']) ?><?php endif; ?></small>
            </li>
          <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>

      <details class="user-more">
        <summary>Navegadores e IP (<?= count($list) ?>)</summary>
        <?php if (!$list): ?>
          <p class="admin-sub">Todavía no se registró ninguna conexión de esta cuenta.</p>
        <?php else: ?>
          <ul class="su-list">
          <?php foreach ($list as $d): ?>
            <?php $banned = isset($bannedSet[$d['device_hash']]); $mine = $d['device_hash'] === $myDevice; ?>
            <li class="<?= $banned ? 'su-banned' : '' ?>">
              <div>
                <strong><?= e(UserActivity::browser($d['user_agent'])) ?> · IP <?= e($d['ip']) ?>
                  <?php if ($banned): ?> <span class="status cancelled">Permaban</span><?php endif; ?>
                  <?php if ($mine): ?> <span class="status booked">tu navegador</span><?php endif; ?></strong>
                <small class="su-ua"><?= e($d['user_agent'] ?: 'Sin user agent') ?></small>
                <small>Primera vez <?= e($when($d['first_seen_at'])) ?> · última <?= e($when($d['last_seen_at'])) ?> · navegador <?= e(substr($d['device_hash'], 0, 10)) ?></small>
              </div>
              <div class="su-row-actions">
                <?php if (!$banned && !$mine): ?>
                <form method="post" action="/su/permaban" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $a->id ?>">
                  <input type="hidden" name="device" value="<?= e($d['device_hash']) ?>">
                  <button class="btn-small danger" type="submit" data-confirm="¿Permaban a este navegador (<?= e(UserActivity::browser($d['user_agent'])) ?>)? No va a poder volver a usar la app, con ninguna cuenta.">Permaban</button>
                </form>
                <?php endif; ?>
                <?php if ($d['ip'] !== $myIp): ?>
                <form method="post" action="/su/ip/bloquear" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="ip" value="<?= e($d['ip']) ?>">
                  <input type="hidden" name="reason" value="<?= e('Cuenta ' . $a->fullName) ?>">
                  <input type="hidden" name="duration" value="24h">
                  <button class="btn-small ghost" type="submit" data-confirm="¿Bloquear la IP <?= e($d['ip']) ?>? Todo el que se conecte desde ahí queda afuera durante 24 horas.">Bloquear IP 24 h</button>
                </form>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </details>

      <details class="user-more">
        <summary>Editar datos y rol</summary>
        <form method="post" action="/su/usuarios/guardar" class="svc-edit" data-role-form>
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $a->id ?>">
          <div class="svc-fields">
            <label class="wide">Nombre y apellido<input type="text" name="full_name" value="<?= e($a->fullName) ?>" maxlength="120" autocapitalize="words" required data-validate="name"></label>
            <label class="wide">Celular<input type="tel" name="phone" inputmode="tel" value="<?= e($a->phone !== '' ? fmt_phone($a->phone) : '') ?>" maxlength="30" placeholder="Opcional" data-validate="phone"></label>
            <label>Rol
              <select name="role" data-role-select required>
              <?php foreach (User::ROLES as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $key === $a->role() ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
              </select>
            </label>
            <fieldset class="wide perm-box" data-barber-only>
              <legend>Secciones extra del panel</legend>
              <div class="perm-chips">
              <?php foreach (User::SECTIONS as $key => $label): ?>
                <label class="perm-chip">
                  <input type="checkbox" name="sections[]" value="<?= e($key) ?>"<?= in_array($key, $a->panelAccess, true) ? ' checked' : '' ?>>
                  <span><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
              </div>
            </fieldset>
          </div>
          <div class="svc-row-actions"><button class="btn-small" type="submit">Guardar</button></div>
        </form>
      </details>

      <details class="user-more">
        <summary>Cambiar contraseña</summary>
        <form method="post" action="/su/usuarios/clave" class="svc-edit">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $a->id ?>">
          <div class="svc-fields">
            <label>Nueva contraseña<input type="password" name="password" autocomplete="new-password" minlength="<?= $minPassword ?>" maxlength="<?= $maxPassword ?>" required data-validate="password"></label>
            <label>Repetila<input type="password" name="confirm" autocomplete="new-password" maxlength="<?= $maxPassword ?>" required data-validate="confirm"></label>
          </div>
          <div class="svc-row-actions"><button class="btn-small" type="submit">Cambiar contraseña</button></div>
        </form>
      </details>

      <details class="user-more">
        <summary>Sesiones, Permaban y eliminar</summary>
        <div class="su-tools">
          <form method="post" action="/su/usuarios/sesiones" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $a->id ?>">
            <button class="btn-small ghost" type="submit">Cerrar sus sesiones</button>
          </form>
          <form method="post" action="/su/permaban" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $a->id ?>">
            <button class="btn-small danger" type="submit" data-confirm="¿Permaban total a <?= e($a->fullName) ?>? Se bloquean para siempre sus <?= count($list) ?> navegador(es), se da de baja la cuenta y se cierran sus sesiones.">Permaban total</button>
          </form>
          <form method="post" action="/su/usuarios/borrar" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $a->id ?>">
            <button class="btn-small danger" type="submit" data-confirm="¿Eliminar para siempre la cuenta de <?= e($a->fullName) ?><?= $records > 0 ? ' y sus ' . $records . ' registros (turnos, premios y fila)? Cambian las estadísticas de turnos' : '' ?>? No se puede deshacer.">Eliminar cuenta</button>
          </form>
        </div>
        <p class="admin-sub">Permaban total: bloquea todos sus navegadores, la da de baja y cierra sus sesiones. Eliminar borra también <?= $records > 0 ? 'sus ' . $records . ' registros de turnos, premios y fila' : 'todo su historial (no tiene)' ?>; los egresos o ingresos que haya cargado quedan sin autor.</p>
      </details>
    </section>
  <?php endforeach; ?>
</main>
