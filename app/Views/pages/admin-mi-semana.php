<?php
/**
 * pages/admin-mi-semana.php — Tablero propio del barbero: solo su fila de la tabla de cortes de la semana (lunes a
 * domingo), con lo atendido cada día, el total y los ingresos, y la navegación entre semanas. Nada más, a propósito.
 *
 * Variables: $user (User, el barbero), $week (Dashboard::week: from, to, days), $rows (su fila de $week['barbers'],
 *            o vacío si su ficha del equipo está inactiva), $prev/$next ('YYYY-MM-DD'), $isCurrent (bool)
 */
$range = date('j', strtotime($week['from'])) . ' de ' . month_es((int) date('n', strtotime($week['from'])))
    . ' al ' . date('j', strtotime($week['to'])) . ' de ' . month_es((int) date('n', strtotime($week['to'])));
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Mi semana', 'sub' => 'Semana del ' . $range . ($isCurrent ? ' · esta semana' : ''), 'sections' => $user->panelSections(), 'active' => 'semana']) ?>

  <nav class="admin-nav" aria-label="Cambiar de semana">
    <a class="btn-small" href="/admin/mi-semana?fecha=<?= e($prev) ?>">← Semana anterior</a>
    <?php if (!$isCurrent): ?><a class="btn-small" href="/admin/mi-semana">Esta semana</a><?php endif; ?>
    <a class="btn-small" href="/admin/mi-semana?fecha=<?= e($next) ?>">Semana siguiente →</a>
  </nav>

  <section class="admin-card" aria-labelledby="my-week-cuts">
    <h2 id="my-week-cuts">Mis cortes de la semana</h2>
<?php if ($rows): ?>
    <?= partial('components/admin-week-table', ['days' => $week['days'], 'rows' => $rows]) ?>
<?php else: ?>
    <p class="admin-empty">Tu ficha del equipo no está activa. Consultá con el administrador.</p>
<?php endif; ?>
  </section>
</main>
