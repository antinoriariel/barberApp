<?php
/**
 * pages/login.php — Ingreso con DNI y contraseña.
 * Variables: $error (string, vacío si no hay), $dni (último DNI ingresado),
 *            $canSignUp (bool: hay base de datos y este navegador todavía no creó una cuenta),
 *            $forgotUrl (string: /recuperar, o el WhatsApp de soporte si no hay base de datos).
 */
?>
<div class="login">
  <section class="login-side">
    <?= partial('components/brand') ?>
    <?php // Portada como fondo CSS (no <img>): se funde con la página y no ofrece "guardar/copiar imagen" al mantenerla apretada. ?>
    <div class="login-hero" role="img" aria-label="Cero espera. Cero llamadas. Tu corte, al instante. Diseñamos tu estilo."></div>
  </section>

  <main class="login-main">
    <form class="login-card" method="post" action="/login" novalidate>
      <?= csrf_field() ?>
      <h1>Ingresa a tu cuenta</h1>

      <?php if ($error): ?>
        <p class="field-error" id="login-error" role="alert"><?= e($error) ?></p>
      <?php endif; ?>

      <label class="field" for="dni">DNI</label>
      <input
        id="dni" name="dni" type="text" inputmode="numeric" autocomplete="username"
        placeholder="40.123.456" maxlength="10" required autofocus
        value="<?= $dni !== '' ? e(fmt_dni($dni)) : '' ?>"
        <?= $error ? 'aria-invalid="true" aria-describedby="login-error"' : '' ?>>

      <label class="field" for="password">Contraseña</label>
      <input id="password" name="password" type="password" autocomplete="current-password" maxlength="72" required
        <?= $error ? 'aria-invalid="true" aria-describedby="login-error"' : '' ?>>

      <button class="btn-primary block" type="submit">Entrar</button>

      <?php // "Olvidé mi contraseña" va a /recuperar (o a WhatsApp sin base); "Crear cuenta" solo si este navegador no creó una ya. ?>
      <p class="auth-links">
        <a class="link" href="<?= e($forgotUrl) ?>"<?= str_starts_with($forgotUrl, 'https://') ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>Olvidé mi contraseña</a>
        <?php if ($canSignUp): ?><a class="link" href="/registro">Crear cuenta</a><?php endif; ?>
      </p>
    </form>
  </main>
</div>
