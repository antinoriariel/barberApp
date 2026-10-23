<?php
/**
 * components/qr-scanner.php — Diálogo del lector de QR (lo abre el botón central de la barra; ver assets/js/scanner.js).
 * Muestra la cámara trasera y, al leer el QR del mostrador, abre /fila para tomar número. Si el navegador no puede
 * leer QR (o la página no es segura, sin HTTPS), explica cómo hacerlo con la cámara del celular.
 */
?>
<dialog class="scanner" id="scanner" aria-labelledby="scanner-title">
  <div class="scanner-bar">
    <h2 id="scanner-title">Escaneá el QR del mostrador</h2>
    <button type="button" class="scanner-close" id="scanner-close" data-track="scanner.salir">Salir</button>
  </div>
  <div class="scanner-stage" data-track="scanner.camara">
    <video id="scanner-video" playsinline muted></video>
    <span class="scanner-frame" aria-hidden="true"></span>
  </div>
  <p class="scanner-hint" id="scanner-hint">Apuntá al código que está en el local. Te damos el siguiente número de la fila.</p>
</dialog>
