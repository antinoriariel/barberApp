<?php
/**
 * components/admin-week-table.php — Tabla de cortes atendidos por barbero, día por día (lunes a domingo), con total e
 * ingresos de la semana. La usan /admin/barberos (todo el equipo) y /admin/mi-semana (solo la fila del barbero).
 * La barrita debajo del nombre compara cada total con el mayor de la tabla.
 *
 * Variables: $days (string[] 'YYYY-MM-DD', los 7 días de Dashboard::week), $rows (filas de Dashboard::week()['barbers']:
 *            name, tone, perDay[7], total, revenue)
 */
$dayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
$maxTotal = max([1, ...array_column($rows, 'total')]);
?>
<div class="table-wrap">
  <?php // tabla ancha (7 días): se desliza de costado y la columna del barbero queda fija a la izquierda ?>
  <table class="admin-table sticky-first">
    <thead>
      <tr>
        <th>Barbero</th>
<?php foreach ($days as $i => $d): ?>
        <th class="num"><?= e($dayNames[$i]) ?> <small><?= date('j', strtotime($d)) ?></small></th>
<?php endforeach; ?>
        <th class="num">Total</th><th class="num">Ingresos</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $b): ?>
      <tr>
        <th scope="row">
          <span class="dot" style="--tone: <?= e(css_color($b['tone'])) ?>"></span><?= e($b['name']) ?>
          <span class="bar" aria-hidden="true"><i style="width: <?= (int) round($b['total'] / $maxTotal * 100) ?>%"></i></span>
        </th>
<?php foreach ($b['perDay'] as $n): ?>
        <td class="num<?= $n === 0 ? ' zero' : '' ?>"><?= $n ?></td>
<?php endforeach; ?>
        <td class="num"><b><?= $b['total'] ?></b></td>
        <td class="num"><?= money($b['revenue']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
