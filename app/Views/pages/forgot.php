<?php
/**
 * pages/forgot.php — Pedido de recuperación de contraseña.
 * Variables: $error (string), $sent (bool, opcional: ya se pidió). El mensaje de éxito es el mismo exista o no la cuenta.
 */
?>
<div class="login">
  <section class="login-side">
    <?= partial('components/brand') ?>
    <p class="login-tagline">Pasa hasta a los mejores.</p>
  </section>

  <main class="login-main">
    <form class="login-card" method="post" action="/recuperar" novalidate>
      <?= csrf_field() ?>
      <h1>Recuperá tu contraseña</h1>
      <?php if ($error): ?>
        <p class="field-error" role="alert"><?= e($error) ?></p>
      <?php elseif (!empty($sent)): ?>
        <p class="auth-ok" role="status">Si ese email tiene una cuenta, te mandamos un enlace para elegir una contraseña nueva. Vale 1 hora.</p>
      <?php endif; ?>

      <label class="field" for="email">Email de tu cuenta</label>
      <input id="email" name="email" type="email" autocomplete="email" maxlength="190" required autofocus>

      <button class="btn-primary block" type="submit">Enviar enlace</button>
      <p class="auth-links"><a class="link" href="/login">Volver a ingresar</a></p>
    </form>
  </main>
</div>
