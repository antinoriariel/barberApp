<?php
/**
 * pages/su-registros.php — Altas de cuenta (superusuario). Cada navegador puede crear una sola cuenta (Auth::register);
 * acá se ve cada alta con los datos con los que se hizo (nombre, DNI, teléfono), fecha, IP, navegador y el estado actual
 * de la cuenta. Señales de spam: varias altas desde la misma IP (se marcan) y los intentos rechazados (tarjeta que
 * lleva a la auditoría). Por alta: ir a la cuenta, ver todo lo de esa IP, Permaban del navegador, bloquear la IP 24 h y
 * "Liberar navegador" (vuelve a permitir una alta desde ahí, p. ej. un celular compartido en familia).
 *
 * Variables: $filters (Registration::filters()), $result (Registration::search(): rows, total, pages), $page (int),
 *            $totals (Registration::totals()), $maxIp (int: altas por IP cada 24 h), $myIp (string),
 *            $myDevice (sha256 del navegador del superusuario), $notice (string), $error (string)
 */
use App\Models\UserActivity;

$when = static fn (?string $dt): string => $dt ? date('d/m/Y H:i', strtotime($dt)) : '—';
// Query string de los filtros actuales (sin los vacíos), para la paginación y los atajos.
$query = static function (array $over = []) use ($filters): string {
    $q = array_filter(array_merge($filters, $over), static fn ($v) => $v !== '' && $v !== 0 && $v !== null);
    return $q ? '?' . http_build_query($q) : '';
};
$page = min($page, $result['pages']);
$weekAgo = date('Y-m-d', time() - 7 * 86400);
?>
<main class="admin su">
  <?= partial('components/admin-header', ['heading' => 'Registros', 'sub' => 'Cuentas creadas desde la app: una por navegador', 'role' => 'Superusuario', 'sections' => ['su', 'su-registros', 'su-seguridad', 'su-auditoria'], 'active' => 'su-registros']) ?>

  <?php if ($notice !== '' || $error !== ''): ?>
  <div class="admin-flashes" id="aviso">
    <?php if ($notice !== ''): ?><p class="admin-flash ok" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <nav class="admin-kpis su-kpis" aria-label="Resumen de altas">
    <a class="kpi" href="/su/registros?desde=<?= date('Y-m-d', time() - 86400) ?>"><h2>Últimas 24 h</h2><p class="kpi-value"><?= $totals['day'] ?></p><p class="kpi-prev">cuentas creadas</p></a>
    <a class="kpi" href="/su/registros?desde=<?= $weekAgo ?>"><h2>Últimos 7 días</h2><p class="kpi-value"><?= $totals['week'] ?></p><p class="kpi-prev">cuentas creadas</p></a>
    <a class="kpi<?= $totals['blocked'] > 0 ? ' is-alert' : '' ?>" href="/su/auditoria?grupo=registro&amp;desde=<?= $weekAgo ?>"><h2>Rechazadas</h2><p class="kpi-value"><?= $totals['blocked'] ?></p><p class="kpi-prev">en 7 días: navegador ya usado o tope de <?= $maxIp ?> por IP</p></a>
    <div class="kpi"><h2>Liberados</h2><p class="kpi-value"><?= $totals['released'] ?></p><p class="kpi-prev">navegadores habilitados de nuevo</p></div>
  </nav>

  <section class="admin-card svc-edit" aria-labelledby="reg-filters">
    <h2 id="reg-filters">Filtrar</h2>
    <form method="get" action="/su/registros" class="svc-edit">
      <div class="svc-fields">
        <label class="wide">Buscar<input type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="60" autocomplete="off" placeholder="Nombre, DNI o teléfono"></label>
        <label>IP<input type="text" name="ip" value="<?= e($filters['ip']) ?>" maxlength="45" autocomplete="off" placeholder="Exacta"></label>
        <label>Desde<input type="date" name="desde" value="<?= e($filters['desde']) ?>"></label>
        <label>Hasta<input type="date" name="hasta" value="<?= e($filters['hasta']) ?>"></label>
      </div>
      <div class="svc-row-actions">
        <button class="btn-small" type="submit">Filtrar</button>
        <a class="btn-small ghost" href="/su/registros">Limpiar filtros</a>
      </div>
    </form>
  </section>

  <h2 class="admin-section-title"><?= number_format($result['total'], 0, ',', '.') ?> alta<?= $result['total'] === 1 ? '' : 's' ?></h2>

  <?php if (!$result['rows']): ?>
    <p class="admin-empty">No hay altas con esos filtros.</p>
  <?php else: ?>
  <section class="admin-card">
    <ul class="su-list">
    <?php foreach ($result['rows'] as $r): ?>
      <?php
      $gone = $r['user_id'] === null;
      $sameIp = (int) $r['ip_count'];
      $banned = (bool) $r['banned'];
      $released = $r['released_at'] !== null;
      $mine = $r['device_hash'] === $myDevice;
      $state = $gone ? 'cuenta eliminada' : ((int) $r['account_active'] === 1 ? 'activa' : 'suspendida');
      ?>
      <li class="<?= $banned ? 'su-banned' : ($sameIp > 1 ? 'su-warn' : '') ?>">
        <div>
          <strong>
            <?= e($r['full_name']) ?> · DNI <?= e(fmt_dni($r['dni'])) ?>
            <span class="status <?= $state === 'activa' ? 'done' : 'cancelled' ?>"><?= e($state) ?></span>
            <?php if ($banned): ?> <span class="status cancelled">Permaban</span><?php endif; ?>
          </strong>
          <small>
            Tel. <?= e(fmt_phone($r['phone'])) ?>
            <?php if (!$gone && $r['account_phone'] !== null && $r['account_phone'] !== $r['phone']): ?> (hoy: <?= e(fmt_phone($r['account_phone'])) ?>)<?php endif; ?>
            · <?= e($when($r['created_at'])) ?>
          </small>
          <small>
            IP <a class="link" href="/su/registros<?= e($query(['ip' => $r['ip'], 'pagina' => 0])) ?>" title="Ver las altas de esta IP"><?= e($r['ip']) ?></a>
            <?php if ($sameIp > 1): ?> · <strong><?= $sameIp ?> altas desde esta IP</strong><?php endif; ?>
            · <span title="<?= e($r['user_agent']) ?>"><?= e(UserActivity::browser($r['user_agent'])) ?></span>
            · navegador <?= e(substr($r['device_hash'], 0, 10)) ?>
            · <?= $released ? 'liberado el ' . e($when($r['released_at'])) . ' (puede crear otra cuenta)' : 'no puede crear otra cuenta' ?>
          </small>
        </div>
        <div class="su-row-actions">
          <?php if (!$gone): ?>
            <a class="btn-small ghost" href="/su#usuario-<?= (int) $r['user_id'] ?>">Ir a la cuenta</a>
            <a class="btn-small ghost" href="/su/auditoria?usuario=<?= (int) $r['user_id'] ?>">Su historial</a>
          <?php endif; ?>
          <a class="btn-small ghost" href="/su/auditoria?ip=<?= rawurlencode($r['ip']) ?>">Todo de esta IP</a>
          <?php if (!$released): ?>
          <form method="post" action="/su/registros/liberar" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="volver" value="registros">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn-small ghost" type="submit" data-confirm="¿Liberar este navegador? Desde ahí se va a poder crear otra cuenta.">Liberar navegador</button>
          </form>
          <?php endif; ?>
          <?php if (!$gone && !$banned && !$mine): ?>
          <form method="post" action="/su/permaban" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="volver" value="registros">
            <input type="hidden" name="id" value="<?= (int) $r['user_id'] ?>">
            <input type="hidden" name="device" value="<?= e($r['device_hash']) ?>">
            <button class="btn-small danger" type="submit" data-confirm="¿Permaban a este navegador (<?= e(UserActivity::browser($r['user_agent'])) ?>)? No va a poder volver a usar la app, con ninguna cuenta.">Permaban</button>
          </form>
          <?php endif; ?>
          <?php if ($r['ip'] !== $myIp): ?>
          <form method="post" action="/su/ip/bloquear" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="volver" value="registros">
            <input type="hidden" name="ip" value="<?= e($r['ip']) ?>">
            <input type="hidden" name="duration" value="24h">
            <input type="hidden" name="reason" value="<?= e('Altas desde esta IP (' . $sameIp . ')') ?>">
            <button class="btn-small danger" type="submit" data-confirm="¿Bloquear la IP <?= e($r['ip']) ?> por 24 horas? Si es una IP compartida (wifi, datos del celular) también deja afuera a otros.">Bloquear IP 24 h</button>
          </form>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($result['pages'] > 1): ?>
  <nav class="admin-nav" aria-label="Páginas">
    <?php if ($page > 1): ?><a class="btn-small ghost" href="/su/registros<?= e($query(['pagina' => $page - 1])) ?>">← Más nuevas</a><?php endif; ?>
    <span class="admin-sub su-page">Página <?= $page ?> de <?= $result['pages'] ?></span>
    <?php if ($page < $result['pages']): ?><a class="btn-small ghost" href="/su/registros<?= e($query(['pagina' => $page + 1])) ?>">Más viejas →</a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</main>
