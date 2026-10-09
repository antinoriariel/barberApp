<?php
/**
 * components/loyalty.php — Tarjeta de fidelidad en formato "sellos": una casilla por visita y la última es el premio.
 * Los sellos son los de la tarjeta actual ($card->stamps()): al cobrarse el corte gratis vuelve a empezar vacía.
 * Variables: $card (Models\LoyaltyCard)
 */
?>
<div class="loyalty">
  <div class="loyalty-head">
    <h2><?= e($card->title()) ?></h2>
    <p><?= e($card->status()) ?></p>
  </div>
  <ol class="stamps" role="progressbar" aria-label="Visitas acumuladas"
      aria-valuemin="0" aria-valuemax="<?= $card->paid() ?>" aria-valuenow="<?= min($card->stamps(), $card->paid()) ?>">
    <?php for ($i = 1; $i <= $card->goal; $i++): ?>
      <li class="stamp<?= $i <= $card->stamps() || ($i === $card->goal && $card->isComplete()) ? ' on' : '' ?><?= $i === $card->goal ? ' prize' : '' ?>">
        <?= $i === $card->goal || $i <= $card->stamps() ? icon('scissors') : $i ?>
      </li>
    <?php endfor; ?>
  </ol>
  <p class="loyalty-foot"><b><?= min($card->stamps(), $card->paid()) ?></b> de <?= $card->paid() ?> visitas</p>
</div>
