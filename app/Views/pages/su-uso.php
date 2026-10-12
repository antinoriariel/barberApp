<?php
/**
 * pages/su-uso.php — Uso de la app (superusuario): mapa de calor de clicks/taps o de la presencia del mouse en PC,
 * por pantalla y por capa, con el plano de componentes debajo, el ranking de componentes de la pantalla, el resumen
 * por pantalla y la retención de los datos. El mapa lo dibuja js/su-heatmap.js con el JSON de #hm-data (es un bloque
 * de datos, no un script: la CSP no lo ejecuta); sin JS se ven igual las tablas.
 *
 * Variables: $f (UiUsage::filters), $heat (UiUsage::heat), $boxes (UiUsage::boxes), $components (UiUsage::components),
 *            $screens (UiUsage::screens), $totals (UiUsage::totals), $size (UiUsage::size),
 *            $notice (string), $error (string)
 */
use App\Models\UiUsage;

$cfg = UiUsage::config();
$screenName = $cfg['screens'][$f['pantalla']];
$pct = static fn (int $part, int $all): string => $all > 0 ? number_format($part / $all * 100, 0) . '%' : '—';
$num = static fn (int $n): string => number_format($n, 0, ',', '.');
// Enlace a esta misma vista cambiando solo algunos filtros.
$link = static fn (array $change): string => '/su/uso?' . http_build_query(array_merge(
    array_diff_key($f, ['from' => 1]), $change
));
$layerNames = ['page' => 'Contenido', 'fixed' => 'Barras y diálogos'];
$what = $f['modo'] === 'mouse' ? 'muestras del mouse' : 'clicks';
?>
<main class="admin su su-uso">
  <?= partial('components/admin-header', ['heading' => 'Uso de la app', 'sub' => 'Dónde tocan y hacia dónde va el mouse de los clientes', 'sections' => \App\Controllers\SuperController::TABS, 'active' => 'su-uso']) ?>

  <?php if ($notice !== '' || $error !== ''): ?>
  <div class="admin-flashes" id="aviso">
    <?php if ($notice !== ''): ?><p class="admin-flash ok" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <form method="get" action="/su/uso" class="hm-filters" id="hm-filters" aria-label="Filtros del mapa">
    <label>Período
      <select name="dias">
      <?php foreach (UiUsage::PERIODS as $d => $label): ?>
        <option value="<?= $d ?>"<?= $f['dias'] === $d ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
      </select>
    </label>
    <label>Pantalla
      <select name="pantalla">
      <?php foreach ($cfg['screens'] as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $f['pantalla'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
      </select>
    </label>
    <label>Capa
      <select name="capa">
      <?php foreach ($layerNames as $key => $label): ?>
        <option value="<?= $key ?>"<?= $f['capa'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
      </select>
    </label>
    <label>Medir
      <select name="modo">
        <option value="clicks"<?= $f['modo'] === 'clicks' ? ' selected' : '' ?>>Clicks y taps</option>
        <option value="mouse"<?= $f['modo'] === 'mouse' ? ' selected' : '' ?>>Mouse (PC)</option>
      </select>
    </label>
    <label>Dispositivo
      <select name="disp"<?= $f['modo'] === 'mouse' ? ' disabled' : '' ?>>
        <option value="">Todos</option>
        <option value="touch"<?= $f['disp'] === 'touch' ? ' selected' : '' ?>>Celular (táctil)</option>
        <option value="mouse"<?= $f['disp'] === 'mouse' ? ' selected' : '' ?>>PC (mouse)</option>
      </select>
    </label>
    <label>Peso
      <select name="peso">
        <option value="usuario"<?= $f['peso'] === 'usuario' ? ' selected' : '' ?>>Promedio por usuario</option>
        <option value="total"<?= $f['peso'] === 'total' ? ' selected' : '' ?>>Total de <?= e($what) ?></option>
      </select>
    </label>
    <button class="btn-small" type="submit">Aplicar</button>
  </form>

  <nav class="admin-kpis su-kpis" aria-label="Totales del período">
    <div class="kpi"><h2>Clicks y taps</h2><p class="kpi-value"><?= $num($totals['clicks']) ?></p><p class="kpi-prev">en todas las pantallas</p></div>
    <div class="kpi"><h2>Personas</h2><p class="kpi-value"><?= $num($totals['users']) ?></p><p class="kpi-prev">tocaron algo</p></div>
    <div class="kpi"><h2>Desde el celular</h2><p class="kpi-value"><?= $pct($totals['touch'], $totals['clicks']) ?></p><p class="kpi-prev">el resto con mouse</p></div>
    <div class="kpi<?= $totals['clicks'] && $totals['dead'] / $totals['clicks'] > .1 ? ' is-alert' : '' ?>"><h2>Clicks muertos</h2><p class="kpi-value"><?= $pct($totals['dead'], $totals['clicks']) ?></p><p class="kpi-prev">en zonas que no hacen nada</p></div>
  </nav>

  <div class="hm-layout">
    <section class="admin-card hm-card" aria-labelledby="hm-title">
      <h2 id="hm-title"><?= e($screenName) ?> · <?= e($layerNames[$f['capa']]) ?></h2>
      <p class="admin-sub">
        <?php if ($f['modo'] === 'mouse'): ?>
          Dónde estuvo el mouse mientras se movía (solo PC; cada muestra son 250 ms).
        <?php elseif ($f['capa'] === 'fixed'): ?>
          Barra superior, barra inferior, diálogos y el resumen de la reserva: lo que queda fijo en la ventana mientras se hace scroll.
        <?php else: ?>
          El contenido de la pantalla de arriba hacia abajo, como si fuera una página larga en un celular.
        <?php endif; ?>
        <?= $f['peso'] === 'usuario' ? 'Cada persona pesa lo mismo: es el promedio de todos los usuarios.' : 'Suma cruda: quien más toca, más pesa.' ?>
      </p>
      <?php if (!$heat['points']): ?>
        <p class="admin-empty">Todavía no hay <?= e($what) ?> en esta pantalla y período.</p>
      <?php else: ?>
        <figure class="hm-figure">
          <div class="hm-stage" id="hm-stage" style="aspect-ratio: <?= UiUsage::REF_WIDTH ?> / <?= (int) $heat['height'] ?>">
            <div class="hm-boxes" id="hm-boxes" aria-hidden="true"></div>
            <canvas class="hm-canvas" id="hm-canvas" role="img"
                    aria-label="Mapa de calor de <?= e($screenName) ?>: <?= $num($heat['events']) ?> <?= e($what) ?> de <?= $num($heat['users']) ?> personas. El detalle está en la tabla de componentes."></canvas>
            <p class="hm-tip" id="hm-tip" hidden></p>
          </div>
          <figcaption class="hm-legend">
            <span>Menos</span><i class="hm-ramp" aria-hidden="true"></i><span>Más</span>
            <small><?= $num($heat['events']) ?> <?= e($what) ?> · <?= $num($heat['users']) ?> persona<?= $heat['users'] === 1 ? '' : 's' ?></small>
          </figcaption>
        </figure>
        <script type="application/json" id="hm-data"><?= json_encode([
            'points' => $heat['points'], 'height' => $heat['height'], 'layer' => $f['capa'],
            'boxes' => $boxes, 'fixedHeight' => UiUsage::FIXED_HEIGHT,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
      <?php endif; ?>
    </section>

    <section class="admin-card" aria-labelledby="comp-title">
      <h2 id="comp-title">Componentes de <?= e($screenName) ?></h2>
      <p class="admin-sub">Clicks por componente en las dos capas. "Cada 100 visitas" = cuántas veces se toca cada vez que 100 personas abren la pantalla. Pasá el mouse por una fila para ubicarlo en el mapa.</p>
      <?php if (!$components): ?>
        <p class="admin-empty">Sin clicks en este período.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="admin-table hm-table">
            <thead><tr><th>Componente</th><th class="num">Clicks</th><th class="num">%</th><th class="num">Personas</th><th class="num">c/100 visitas</th><th>Celular / PC</th><th>Va a</th></tr></thead>
            <tbody>
            <?php foreach ($components as $c): ?>
              <tr data-comp="<?= e($c['comp']) ?>"<?= $c['comp'] === 'fondo' || str_starts_with($c['comp'], 'auto:') ? ' class="hm-warn"' : '' ?>>
                <td>
                  <strong><?= e($c['label']) ?></strong>
                  <small class="hm-code"><?= e($c['comp']) ?><?= $c['layer'] === 'fixed' ? ' · fijo' : '' ?></small>
                  <?php if ($c['items']): ?>
                    <small class="hm-items"><?= e(implode(' · ', array_map(static fn (array $i) => $i[0] . ' (' . $i[1] . ')', $c['items']))) ?></small>
                  <?php endif; ?>
                </td>
                <td class="num"><?= $num($c['clicks']) ?></td>
                <td class="num"><span class="hm-share"><i style="width: <?= min(100, (int) round($c['share'])) ?>%"></i></span><?= number_format($c['share'], 1, ',', '') ?></td>
                <td class="num"><?= $num($c['users']) ?></td>
                <td class="num"><?= $c['per100'] === null ? '—' : number_format($c['per100'], 1, ',', '') ?></td>
                <td><?= $pct($c['touch'], $c['clicks']) ?> / <?= $pct($c['mouse'], $c['clicks']) ?></td>
                <td><?= $c['dest'] !== '' ? '<code>' . e($c['dest']) . '</code>' : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="admin-sub">En rojo: clicks muertos (zonas que no hacen nada: la gente creyó que se podían tocar) y elementos sin código (<code>auto:</code>, conviene agregarles <code>data-track</code>; ver <code>config/tracking.php</code>).</p>
      <?php endif; ?>
    </section>
  </div>

  <section class="admin-card" aria-labelledby="screens-title">
    <h2 id="screens-title">Pantallas</h2>
    <div class="table-wrap">
      <table class="admin-table">
        <thead><tr><th>Pantalla</th><th class="num">Visitas</th><th class="num">Personas</th><th class="num">Clicks</th><th class="num">Clicks muertos</th><th class="num">Mouse</th></tr></thead>
        <tbody>
        <?php foreach ($screens as $key => $s): ?>
          <tr<?= $key === $f['pantalla'] ? ' aria-current="true"' : '' ?>>
            <td><a href="<?= e($link(['pantalla' => $key])) ?>"><?= e($s['label']) ?></a></td>
            <td class="num"><?= $num($s['views']) ?></td>
            <td class="num"><?= $num($s['viewers']) ?></td>
            <td class="num"><?= $num($s['clicks']) ?></td>
            <td class="num"><?= $pct($s['dead'], $s['clicks']) ?></td>
            <td class="num"><?= e(\App\Models\GameStats::duration(intdiv($s['mouse'], 4))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="admin-card svc-edit" aria-labelledby="uso-ret">
    <h2 id="uso-ret">Retención y privacidad</h2>
    <p class="admin-sub">
      <?= $num($size['clicks']) ?> clicks y <?= $num($size['moves']) ?> celdas de mouse guardados<?= $size['oldest'] ? ', desde el ' . e(date('d/m/Y', strtotime($size['oldest']))) : '' ?>.
      Solo se mide a clientes (no al personal), nunca textos ni lo que se escribe, y no se mide a quien tiene activado
      "Global Privacy Control" en su navegador. Los datos se borran con la cuenta.
    </p>
    <form method="post" action="/su/uso/limpiar" class="svc-edit">
      <?= csrf_field() ?>
      <input type="hidden" name="volver" value="uso">
      <div class="svc-fields">
        <label>Borrar los de más de
          <select name="days">
          <?php foreach ($cfg['retention'] as $days): ?>
            <option value="<?= $days ?>"<?= $days === 90 ? ' selected' : '' ?>><?= $days ?> días</option>
          <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="svc-row-actions">
        <button class="btn-small danger" type="submit" data-confirm="¿Borrar los datos de uso más viejos que eso? No se puede deshacer.">Borrar datos viejos</button>
      </div>
    </form>
  </section>
</main>
