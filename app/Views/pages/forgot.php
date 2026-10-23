<?php
/**
 * pages/forgot.php — "Olvidé mi contraseña": DNI, el celular que dio al registrarse y la contraseña nueva (dos veces).
 * Si coinciden con una cuenta activa, Auth::recover guarda la contraseña y entra.
 * Variables: $error (string), $values (array dni, phone: lo ya escrito), $supportUrl (string: WhatsApp de soporte,
 *            para quien cambió de número o no tiene uno cargado).
 */
?>
<div class="login">
  <section class="login-side">
    <?= partial('components/brand') ?>
    <p class="login-tagline">Recuperá tu cuenta con tu celular.</p>
  </section>

  <main class="login-main">
    <form class="login-card" method="post" action="/recuperar" novalidate>
      <?= csrf_field() ?>
      <h1>Olvidé mi contraseña</h1>
      <p class="field-hint">Escribí tu DNI y el mismo celular que diste al crear la cuenta, y elegí una contraseña nueva.</p>

      <?php if ($error): ?>
        <p class="field-error" id="recover-error" role="alert"><?= e($error) ?></p>
      <?php endif; ?>

      <label class="field" for="dni">DNI</label>
      <input id="dni" name="dni" type="text" inputmode="numeric" autocomplete="username" placeholder="40.123.456"
        maxlength="10" required autofocus value="<?= $values['dni'] !== '' ? e(fmt_dni($values['dni'])) : '' ?>"
        <?= $error ? 'aria-describedby="recover-error"' : '' ?>>

      <label class="field" for="phone">Celular con el que te registraste</label>
      <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" maxlength="30" required
        placeholder="3781 40-1440" aria-describedby="phone-hint" value="<?= e($values['phone']) ?>">
      <small class="field-hint" id="phone-hint">Con código de área, sin el 0 ni el 15.</small>

      <label class="field" for="password">Contraseña nueva (mínimo 8 caracteres)</label>
      <input id="password" name="password" type="password" autocomplete="new-password" maxlength="72" required>

      <label class="field" for="password2">Repetí la contraseña nueva</label>
      <input id="password2" name="password2" type="password" autocomplete="new-password" maxlength="72" required>

      <button class="btn-primary block" type="submit">Cambiar contraseña y entrar</button>

      <?php // Plan B: cambió de número o la cuenta no tiene celular → la contraseña la cambia el administrador. ?>
      <p class="auth-links">
        <a class="link" href="/login">Volver al ingreso</a>
        <a class="link" href="<?= e($supportUrl) ?>" target="_blank" rel="noopener noreferrer">¿Cambiaste de número?</a>
      </p>
    </form>
  </main>
</div>
