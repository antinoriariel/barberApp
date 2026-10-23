<?php
/**
 * pages/su-auditoria.php — Registro de auditoría completo (superusuario). Filtros por tipo de evento, cuenta (llega
 * desde "Historial completo" de cada tarjeta), IP, texto (nombre, DNI o detalle) y rango de fechas; paginado y
 * exportación CSV con los mismos filtros. Cada fila tiene atajos para filtrar por esa IP o esa cuenta.
 *
 * Variables: $filters (AuditLog::filters()), $result (AuditLog::search(): rows, total, pages), $page (int),
 *            $account (?User: la cuenta filtrada, para mostrar su nombre), $notice (string), $error (string)
 */
use App\Models\AuditLog;
use App\Models\UserActivity;

$when = static fn (string $dt): string => date('d/m/Y H:i:s', strtotime($dt));
// Query string de los filtros actuales (sin los vacíos), para la paginación, la exportación y los atajos.
$query = static function (array $over = []) use ($filters): string {
    $q = array_filter(array_merge($filters, $over), static fn ($v) => $v !== '' && $v !== 0 && $v !== null);
    return $q ? '?' . http_build_query($q) : '';
};
$page = min($page, $result['pages']);
?>
<main class="admin su">
  <?= partial('components/admin-header', ['heading' => 'Auditoría', 'sub' => 'Todo lo que pasó en la app, quién, cuándo y desde dónde', 'role' => 'Superusuario', 'sections' => \App\Controllers\SuperController::TABS, 'active' => 'su-auditoria']) ?>

  <?php if ($notice !== '' || $error !== ''): ?>
  <div class="admin-flashes" id="aviso">
    <?php if ($notice !== ''): ?><p class="admin-flash ok" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <section class="admin-card svc-edit" aria-labelledby="audit-filters">
    <h2 id="audit-filters">Filtrar</h2>
    <form method="get" action="/su/auditoria" class="svc-edit">
      <?php if ($filters['usuario'] > 0): ?><input type="hidden" name="usuario" value="<?= $filters['usuario'] ?>"><?php endif; ?>
      <div class="svc-fields">
        <label>Tipo
          <select name="grupo">
            <option value="">Todos los eventos</option>
          <?php foreach (AuditLog::GROUPS as $key => [$label]): ?>
            <option value="<?= e($key) ?>"<?= $filters['grupo'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
          </select>
        </label>
        <label>IP<input type="text" name="ip" value="<?= e($filters['ip']) ?>" maxlength="45" autocomplete="off" placeholder="Exacta"></label>
        <label class="wide">Buscar<input type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="60" autocomplete="off" placeholder="Nombre, DNI, teléfono o detalle"></label>
        <label>Desde<input type="date" name="desde" value="<?= e($filters['desde']) ?>"></label>
        <label>Hasta<input type="date" name="hasta" value="<?= e($filters['hasta']) ?>"></label>
      </div>
      <?php if ($account !== null || $filters['usuario'] > 0): ?>
        <p class="perm-note">Solo la cuenta: <strong><?= e($account?->fullName ?? 'eliminada #' . $filters['usuario']) ?></strong> · <a class="link" href="/su/auditoria<?= e($query(['usuario' => 0, 'pagina' => 0])) ?>">quitar</a></p>
      <?php endif; ?>
      <div class="svc-row-actions">
        <button class="btn-small" type="submit">Filtrar</button>
        <a class="btn-small ghost" href="/su/auditoria">Limpiar filtros</a>
        <a class="btn-small ghost" href="/su/auditoria/exportar<?= e($query()) ?>">Exportar CSV</a>
      </div>
    </form>
  </section>

  <h2 class="admin-section-title"><?= number_format($result['total'], 0, ',', '.') ?> evento<?= $result['total'] === 1 ? '' : 's' ?></h2>

  <?php if (!$result['rows']): ?>
    <p class="admin-empty">No hay eventos con esos filtros.</p>
  <?php else: ?>
  <section class="admin-card">
    <ul class="su-list su-log">
    <?php foreach ($result['rows'] as $r): ?>
      <?php $bad = in_array($r['event'], ['login_fail', 'login_throttled', 'register_blocked_device', 'register_blocked_ip'], true); ?>
      <li class="<?= $bad ? 'su-warn' : '' ?>">
        <div>
          <strong><?= e(UserActivity::label($r['event'])) ?> · <?= e(AuditLog::actor($r)) ?></strong>
          <small>
            <?= e($when($r['created_at'])) ?>
            · IP <a class="link" href="/su/auditoria<?= e($query(['ip' => $r['ip'], 'pagina' => 0])) ?>" title="Ver todo lo de esta IP"><?= e($r['ip']) ?></a>
            <?php if ($r['user_agent']): ?> · <span title="<?= e($r['user_agent']) ?>"><?= e(UserActivity::browser($r['user_agent'])) ?></span><?php endif; ?>
            <?php if ($r['detail']): ?> · <?= e($r['detail']) ?><?php endif; ?>
          </small>
        </div>
        <?php if ($r['user_id'] && $filters['usuario'] !== (int) $r['user_id']): ?>
          <a class="btn-small ghost" href="/su/auditoria?usuario=<?= (int) $r['user_id'] ?>">Su historial</a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($result['pages'] > 1): ?>
  <nav class="admin-nav" aria-label="Páginas">
    <?php if ($page > 1): ?><a class="btn-small ghost" href="/su/auditoria<?= e($query(['pagina' => $page - 1])) ?>">← Más nuevos</a><?php endif; ?>
    <span class="admin-sub su-page">Página <?= $page ?> de <?= $result['pages'] ?></span>
    <?php if ($page < $result['pages']): ?><a class="btn-small ghost" href="/su/auditoria<?= e($query(['pagina' => $page + 1])) ?>">Más viejos →</a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</main>
