<?php
/**
 * components/price.php — Precio de un servicio. Con "Hora muerta" en curso ($pct > 0) muestra el precio de lista
 * tachado y al lado el precio con el descuento; si la cuenta regresiva llega a cero, countdown.js pone la clase
 * sale-over en <html> y el CSS (dead-hour.css) vuelve a dejar solo el precio de lista. Va dentro del elemento que
 * le da el tamaño (svc-price, rail-price). Solo para servicios: los productos no tienen descuento.
 * Variables: $price (int, pesos), $pct (int, % de descuento; 0 = sin descuento)
 */
?>
<?php if ($pct > 0): ?><s class="price-old"><?= money($price) ?></s> <span class="price-new"><?= money(sale_price($price, $pct)) ?></span><?php else: ?><?= money($price) ?><?php endif; ?>
