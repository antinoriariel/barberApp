<?php
/**
 * components/arcade.php — Área de minijuegos de la pantalla de inicio ("matá el rato mientras esperás").
 * Cada tarjeta abre el juego en un diálogo (arcade.js). Los premios ganados se listan abajo. Al terminar una
 * partida el cartel de fin muestra el top del juego (los 6 mejores, ver Models\GameScore).
 * Variables: $games (array slug => [name, goal, promo, …] de config/games.php),
 *            $rewards (array de [code, label, expires] vigentes del cliente)
 */
$icons = ['flappy' => 'scissors', 'runner' => 'chair', 'pacman' => 'star'];
?>
<details class="arcade home-wide" id="arcade" <?= $rewards ? 'open' : '' ?>>
  <summary class="arcade-summary">
    <span>¿Esperando tu turno? <small>Jugá un rato</small></span>
    <span class="arcade-chevron" aria-hidden="true">▾</span>
  </summary>
  <p class="arcade-lead">Jugá un rato y sumá puntos. Y si limpiás el Pac-Barber, te llevás un cupón (un premio por día).</p>

  <div class="arcade-grid">
    <?php foreach ($games as $slug => $game): ?>
      <button type="button" class="arcade-card" data-game="<?= e($slug) ?>"
              data-name="<?= e($game['name']) ?>" data-goal="<?= e($game['goal']) ?>"
              data-target="<?= (int) ($game['target'] ?? 0) ?>">
        <span class="arcade-ic"><?= icon($icons[$slug] ?? 'star') ?></span>
        <span class="arcade-name"><?= e($game['name']) ?></span>
        <span class="arcade-goal"><?= e($game['goal']) ?></span>
        <?php $promo = !empty($game['reward']) ? $game['promo'] : 'Solo puntos'; ?>
        <?php // arcade.js reemplaza este texto por el último puntaje guardado; data-default es el texto sin partidas ?>
        <span class="arcade-promo" data-last data-default="<?= e($promo) ?>"><?= e($promo) ?></span>
      </button>
    <?php endforeach; ?>
  </div>

  <div class="arcade-rewards" id="arcade-rewards" <?= $rewards ? '' : 'hidden' ?>>
    <h3>Tus premios</h3>
    <ul id="arcade-rewards-list">
      <?php foreach ($rewards as $r): ?>
        <li><b><?= e($r['code']) ?></b> <?= e($r['label']) ?> <small>vence el <?= e($r['expires']) ?></small></li>
      <?php endforeach; ?>
    </ul>
    <p>Mostrá el código en el mostrador al pagar.</p>
  </div>

  <dialog class="arcade-dialog" id="arcade-dialog" aria-labelledby="arcade-title">
    <div class="arcade-bar">
      <h3 id="arcade-title"></h3>
      <span class="arcade-score" id="arcade-score" aria-live="off">0</span>
      <button type="button" class="arcade-close" id="arcade-close">Salir</button>
    </div>
    <p class="arcade-hint" id="arcade-hint"></p>
    <div class="arcade-stage">
      <canvas id="arcade-canvas" width="320" height="480"></canvas>
      <div class="arcade-over" id="arcade-over" hidden>
        <h4 id="arcade-over-title"></h4>
        <p id="arcade-over-msg"></p>
        <?php // Top del juego (arcade.js lo llena con textContent a partir de /juegos/fin; nunca HTML del servidor) ?>
        <div class="arcade-top" id="arcade-top" hidden>
          <h5>Mejores puntajes</h5>
          <ol id="arcade-top-list"></ol>
        </div>
        <button type="button" class="btn-primary" id="arcade-again">Jugar de nuevo</button>
      </div>
    </div>
  </dialog>
</details>
