<?php
/**
 * pages/admin-mi-semana.php — Tablero propio del barbero: sus cortes de la semana (lunes a domingo), con el total y los
 * ingresos arriba y lo atendido cada día en una tira de 7 casillas (hoy resaltado), y la navegación entre semanas.
 * No usa la tabla de admin-week-table: con una sola fila, en el celular obligaba a deslizar de costado para ver el
 * total. Nada más, a propósito.
 *
 * Variables: $user (User, el barbero), $week (Dashboard::week: from, to, days), $rows (su fila de $week['barbers'],
 *            o vacío si su ficha del equipo está inactiva), $prev/$next ('YYYY-MM-DD'), $isCurrent (bool)
 */
$dayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
$today = date('Y-m-d');
$range = date('j', strtotime($week['from'])) . ' de ' . month_es((int) date('n', strtotime($week['from'])))
    . ' al ' . date('j', strtotime($week['to'])) . ' de ' . month_es((int) date('n', strtotime($week['to'])));
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Mi semana', 'sub' => 'Semana del ' . $range . ($isCurrent ? ' · esta semana' : ''), 'sections' => $user->panelSections(), 'role' => $user->panelRole(), 'active' => 'semana']) ?>

  <nav class="admin-nav" aria-label="Cambiar de semana">
    <a class="btn-small" href="/admin/mi-semana?fecha=<?= e($prev) ?>" aria-label="Semana anterior">← <span class="nav-long">Semana anterior</span><span class="nav-short">Anterior</span></a>
    <?php if (!$isCurrent): ?><a class="btn-small" href="/admin/mi-semana">Esta semana</a><?php endif; ?>
    <a class="btn-small" href="/admin/mi-semana?fecha=<?= e($next) ?>" aria-label="Semana siguiente"><span class="nav-long">Semana siguiente</span><span class="nav-short">Siguiente</span> →</a>
  </nav>

  <section class="admin-card" aria-labelledby="my-week-cuts">
    <h2 id="my-week-cuts">Mis cortes de la semana</h2>
<?php if ($rows): $b = $rows[0]; ?>
    <div class="my-week-totals">
      <p><b><?= $b['total'] ?></b><span>corte<?= $b['total'] === 1 ? '' : 's' ?></span></p>
      <p><b><?= money($b['revenue']) ?></b><span>ingresos</span></p>
    </div>
    <ol class="my-week-days">
<?php foreach ($week['days'] as $i => $d): $n = $b['perDay'][$i]; ?>
      <li class="<?= trim(($n === 0 ? 'zero ' : '') . ($d === $today ? 'today' : '')) ?>"<?= $d === $today ? ' aria-current="date"' : '' ?>>
        <span class="mw-day"><?= e($dayNames[$i]) ?></span><span class="mw-date"><?= date('j', strtotime($d)) ?></span><b><?= $n ?></b>
      </li>
<?php endforeach; ?>
    </ol>
<?php else: ?>
    <p class="admin-empty">Tu ficha del equipo no está activa. Consultá con el administrador.</p>
<?php endif; ?>
  </section>
</main>
