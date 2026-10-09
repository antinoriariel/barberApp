<?php
/**
 * pages/reset.php — Elegir contraseña nueva con el enlace del email.
 * Variables: $token (string), $valid (bool: el enlace sirve), $error (string), $done (bool, opcional: ya se cambió).
 */
?>
<div class="login">
  <section class="login-side">
    <?= partial('components/brand') ?>
    <p class="login-tagline">Una nueva, y a cortarse.</p>
  </section>

  <main class="login-main">
    <?php if (!empty($done)): ?>
      <div class="login-card">
        <h1>Contraseña actualizada</h1>
        <p class="auth-ok" role="status">Ya podés ingresar con tu contraseña nueva. Cerramos tus otras sesiones por seguridad.</p>
        <a class="btn-primary block" href="/login">Ir a ingresar</a>
      </div>
    <?php elseif (!$valid): ?>
      <div class="login-card">
        <h1>Enlace vencido</h1>
        <p class="field-error" role="alert"><?= e(\App\Services\Auth::ERROR_RESET_INVALID) ?></p>
        <a class="btn-primary block" href="/recuperar">Pedir otro enlace</a>
      </div>
    <?php else: ?>
      <form class="login-card" method="post" action="/restablecer" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <h1>Elegí tu nueva contraseña</h1>
        <?php if ($error): ?>
          <p class="field-error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <label class="field" for="password">Contraseña nueva (mínimo 8 caracteres)</label>
        <input id="password" name="password" type="password" autocomplete="new-password" maxlength="72" required autofocus>

        <label class="field" for="password2">Repetila</label>
        <input id="password2" name="password2" type="password" autocomplete="new-password" maxlength="72" required>

        <button class="btn-primary block" type="submit">Guardar contraseña</button>
      </form>
    <?php endif; ?>
  </main>
</div>
