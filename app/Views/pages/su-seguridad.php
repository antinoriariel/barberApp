<?php
/**
 * pages/su-seguridad.php — Alertas de seguridad de las últimas 24 h (superusuario):
 *  - números del día: logins correctos y fallidos, bloqueos por intentos, navegadores nuevos;
 *  - IP con muchos logins fallidos (posible fuerza bruta), con bloqueo de 1 h / 24 h en un click;
 *  - DNI atacados (muchos fallos sobre la misma cuenta, incluido "superuser");
 *  - cuentas usadas desde muchas IP (¿compartida o robada?) y navegadores nuevos en cuentas que ya tenían otros;
 *  - últimos accesos del superusuario (para detectar que alguien más usa o prueba su contraseña);
 *  - tamaño del registro de auditoría y limpieza de lo viejo.
 *
 * Variables: $totals (AuditLog::totals()), $ips (AuditLog::suspiciousIps()), $blocked (ip => bool, bloqueo vigente),
 *            $targeted (AuditLog::targetedAccounts()), $multiIp (AuditLog::multiIpAccounts()),
 *            $newDevices (AuditLog::newDevices()), $suAccess (AuditLog::superuserAccess()), $size (AuditLog::size()),
 *            $myIp (string), $myDevice (string), $notice (string), $error (string)
 */
use App\Controllers\SuperController;
use App\Models\AuditLog;
use App\Models\UserActivity;

$when = static fn (?string $dt): string => $dt ? date('d/m/Y H:i', strtotime($dt)) : '—';
$knownNew = array_values(array_filter($newDevices, static fn (array $d) => (bool) $d['known']));
?>
<main class="admin su">
  <?= partial('components/admin-header', ['heading' => 'Seguridad', 'sub' => 'Alertas de las últimas 24 horas', 'sections' => \App\Controllers\SuperController::TABS, 'active' => 'su-seguridad']) ?>

  <?php if ($notice !== '' || $error !== ''): ?>
  <div class="admin-flashes" id="aviso">
    <?php if ($notice !== ''): ?><p class="admin-flash ok" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <nav class="admin-kpis su-kpis" aria-label="Últimas 24 horas">
    <a class="kpi" href="/su/auditoria?grupo=login&amp;desde=<?= date('Y-m-d', time() - 86400) ?>"><h2>Ingresos</h2><p class="kpi-value"><?= $totals['logins'] ?></p><p class="kpi-prev">logins correctos</p></a>
    <a class="kpi<?= $totals['fails'] > 0 ? ' is-alert' : '' ?>" href="/su/auditoria?grupo=fallos&amp;desde=<?= date('Y-m-d', time() - 86400) ?>"><h2>Fallidos</h2><p class="kpi-value"><?= $totals['fails'] ?></p><p class="kpi-prev">desde <?= $totals['fail_ips'] ?> IP distinta<?= $totals['fail_ips'] === 1 ? '' : 's' ?></p></a>
    <a class="kpi<?= $totals['throttled'] > 0 ? ' is-alert' : '' ?>" href="/su/auditoria?grupo=fallos&amp;desde=<?= date('Y-m-d', time() - 86400) ?>"><h2>Bloqueos</h2><p class="kpi-value"><?= $totals['throttled'] ?></p><p class="kpi-prev">por exceso de intentos</p></a>
    <a class="kpi<?= $knownNew ? ' is-alert' : '' ?>" href="#nuevos"><h2>Navegadores nuevos</h2><p class="kpi-value"><?= $totals['new_devices'] ?></p><p class="kpi-prev"><?= count($knownNew) ?> en cuentas que ya tenían otro</p></a>
  </nav>

  <section class="admin-card" aria-labelledby="sec-ips">
    <h2 id="sec-ips">IP con muchos logins fallidos</h2>
    <p class="admin-sub">Desde <?= AuditLog::ALERT_FAILS ?> fallos en 24 h. Si probó muchos DNI distintos es un barrido de cuentas; si es uno solo, alguien intenta adivinar esa contraseña.</p>
    <?php if (!$ips): ?>
      <p class="admin-empty">Ninguna. Todo tranquilo.</p>
    <?php else: ?>
      <ul class="su-list">
      <?php foreach ($ips as $r): ?>
        <li class="<?= $blocked[$r['ip']] ? '' : 'su-warn' ?>">
          <div>
            <strong><?= e($r['ip']) ?> · <?= (int) $r['fails'] ?> fallo<?= (int) $r['fails'] === 1 ? '' : 's' ?>
              <?php if ($blocked[$r['ip']]): ?> <span class="status cancelled">bloqueada</span><?php endif; ?></strong>
            <small><?= (int) $r['dnis'] ?> DNI distinto<?= (int) $r['dnis'] === 1 ? '' : 's' ?> · último intento <?= e($when($r['last_at'])) ?></small>
          </div>
          <div class="su-row-actions">
            <a class="btn-small ghost" href="/su/auditoria?ip=<?= rawurlencode($r['ip']) ?>">Ver intentos</a>
            <?php if (!$blocked[$r['ip']] && $r['ip'] !== $myIp): ?>
              <?php foreach (['1h' => 'Bloquear 1 h', '24h' => 'Bloquear 24 h'] as $duration => $label): ?>
              <form method="post" action="/su/ip/bloquear" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="volver" value="seguridad">
                <input type="hidden" name="ip" value="<?= e($r['ip']) ?>">
                <input type="hidden" name="duration" value="<?= $duration ?>">
                <input type="hidden" name="reason" value="<?= e((int) $r['fails'] . ' logins fallidos') ?>">
                <button class="btn-small danger" type="submit"><?= $label ?></button>
              </form>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="admin-card" aria-labelledby="sec-dni">
    <h2 id="sec-dni">Cuentas atacadas</h2>
    <p class="admin-sub">DNI con <?= AuditLog::ALERT_FAILS ?> o más contraseñas equivocadas en 24 h. Si no fue el dueño, conviene avisarle o cambiarle la contraseña.</p>
    <?php if (!$targeted): ?>
      <p class="admin-empty">Ninguna.</p>
    <?php else: ?>
      <ul class="su-list">
      <?php foreach ($targeted as $r): ?>
        <li class="su-warn">
          <div>
            <strong><?= $r['dni'] === 'superuser' ? 'superuser (¡tu cuenta!)' : e($r['full_name'] ?? 'DNI ' . $r['dni'] . ' (no existe)') ?> · <?= (int) $r['fails'] ?> fallos</strong>
            <small><?= $r['dni'] !== 'superuser' ? 'DNI ' . e($r['dni']) . ' · ' : '' ?>desde <?= (int) $r['ips'] ?> IP distinta<?= (int) $r['ips'] === 1 ? '' : 's' ?> · último <?= e($when($r['last_at'])) ?></small>
          </div>
          <div class="su-row-actions">
            <a class="btn-small ghost" href="/su/auditoria?grupo=fallos&amp;q=<?= rawurlencode($r['dni']) ?>">Ver intentos</a>
            <?php if ($r['user_id']): ?><a class="btn-small ghost" href="/su#usuario-<?= (int) $r['user_id'] ?>">Ir a la cuenta</a><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="admin-card" aria-labelledby="sec-multi">
    <h2 id="sec-multi">Cuentas usadas desde muchas IP</h2>
    <p class="admin-sub"><?= AuditLog::ALERT_IPS ?> o más IP distintas en 24 h. Un celular que pasa de wifi a datos ya suma dos; muchas más pueden ser una cuenta compartida o robada.</p>
    <?php if (!$multiIp): ?>
      <p class="admin-empty">Ninguna.</p>
    <?php else: ?>
      <ul class="su-list">
      <?php foreach ($multiIp as $r): ?>
        <li>
          <div>
            <strong><?= e($r['full_name']) ?> · <?= (int) $r['ips'] ?> IP</strong>
            <small><?= e($r['list']) ?></small>
          </div>
          <div class="su-row-actions">
            <a class="btn-small ghost" href="/su/auditoria?usuario=<?= (int) $r['user_id'] ?>">Su historial</a>
            <a class="btn-small ghost" href="/su#usuario-<?= (int) $r['user_id'] ?>">Ir a la cuenta</a>
          </div>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="admin-card" aria-labelledby="sec-new" id="nuevos">
    <h2 id="sec-new">Navegadores nuevos</h2>
    <p class="admin-sub">Navegadores que entraron por primera vez en 24 h. Los marcados son de cuentas que ya usaban otro: alguien entró desde un equipo distinto.</p>
    <?php if (!$newDevices): ?>
      <p class="admin-empty">Ninguno.</p>
    <?php else: ?>
      <ul class="su-list">
      <?php foreach ($newDevices as $d): ?>
        <li class="<?= $d['known'] ? 'su-warn' : '' ?>">
          <div>
            <strong><?= e($d['full_name']) ?> · <?= e(UserActivity::browser($d['user_agent'])) ?> · IP <?= e($d['ip']) ?>
              <?php if ($d['device_hash'] === $myDevice): ?> <span class="status booked">tu navegador</span><?php endif; ?></strong>
            <small><?= $d['known'] ? 'Equipo nuevo en una cuenta que ya tenía otros' : 'Primer acceso registrado de la cuenta' ?> · <?= e($when($d['first_seen_at'])) ?></small>
            <small class="su-ua"><?= e($d['user_agent'] ?: 'Sin user agent') ?></small>
          </div>
          <a class="btn-small ghost" href="/su#usuario-<?= (int) $d['user_id'] ?>">Ir a la cuenta</a>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="admin-card" aria-labelledby="sec-su">
    <h2 id="sec-su">Accesos del superusuario</h2>
    <p class="admin-sub">Si ves una IP o un navegador que no son tuyos, cambiá la contraseña ya: <code>php bin/superuser.php</code> (cierra todas las sesiones de superusuario). Tu IP ahora: <?= e($myIp) ?>.</p>
    <?php if (!$suAccess): ?>
      <p class="admin-empty">Sin registros.</p>
    <?php else: ?>
      <ul class="su-list">
      <?php foreach ($suAccess as $r): ?>
        <?php $bad = $r['event'] !== 'su_login_ok' && $r['event'] !== 'su_logout'; ?>
        <li class="<?= $bad ? 'su-warn' : '' ?>">
          <div>
            <strong><?= e($bad ? ($r['event'] === 'login_throttled' ? 'Bloqueado por intentos' : 'Contraseña equivocada') : UserActivity::label($r['event'])) ?> · IP <?= e($r['ip']) ?></strong>
            <small><?= e($when($r['created_at'])) ?><?php if ($r['user_agent']): ?> · <span title="<?= e($r['user_agent']) ?>"><?= e(UserActivity::browser($r['user_agent'])) ?></span><?php endif; ?></small>
          </div>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="admin-card svc-edit" aria-labelledby="sec-ret">
    <h2 id="sec-ret">Retención del registro</h2>
    <p class="admin-sub">
      <?= number_format($size['rows'], 0, ',', '.') ?> eventos guardados<?= $size['oldest'] ? ', el más viejo del ' . e($when($size['oldest'])) : '' ?>.
      Guardar para siempre datos de conexión (IP, navegador) no hace falta y es un riesgo si alguien se lleva la base: conviene borrar lo viejo cada tanto. La limpieza queda registrada.
    </p>
    <form method="post" action="/su/auditoria/limpiar" class="svc-edit">
      <?= csrf_field() ?>
      <input type="hidden" name="volver" value="seguridad">
      <div class="svc-fields">
        <label>Borrar los de más de
          <select name="days">
          <?php foreach (SuperController::PURGE_DAYS as $days): ?>
            <option value="<?= $days ?>"<?= $days === 180 ? ' selected' : '' ?>><?= $days ?> días</option>
          <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="svc-row-actions">
        <button class="btn-small danger" type="submit" data-confirm="¿Borrar del registro de auditoría todo lo más viejo que eso? No se puede deshacer (exportalo antes si lo necesitás).">Borrar registros viejos</button>
        <a class="btn-small ghost" href="/su/auditoria/exportar">Exportar todo antes (CSV)</a>
      </div>
    </form>
  </section>
</main>
