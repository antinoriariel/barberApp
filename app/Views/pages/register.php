<?php
/**
 * pages/register.php — Alta de cuenta (nombre y apellido, DNI, celular argentino y contraseña).
 * Si este navegador ya creó una cuenta ($allowed = false) no muestra el formulario: solo el aviso y el enlace al login
 * (el servidor lo frena igual en Auth::register).
 * Variables: $error (string), $values (array dni, full_name, phone: lo ya escrito), $allowed (bool),
 *            $forgotUrl (string: WhatsApp de soporte).
 */
?>
<div class="login">
  <section class="login-side">
    <?= partial('components/brand') ?>
    <p class="login-tagline">Tu turno, a un toque.</p>
  </section>

  <main class="login-main">
  <?php if (!$allowed): ?>
    <div class="login-card">
      <h1>Ya tenés una cuenta</h1>
      <p class="field-error" role="alert">Desde este navegador ya se creó una cuenta, y solo se permite una por navegador.</p>
      <a class="btn-primary block" href="/login">Ingresar con mi DNI</a>
      <p class="auth-links">
        <a class="link" href="<?= e($forgotUrl) ?>" target="_blank" rel="noopener noreferrer">Olvidé mi contraseña</a>
      </p>
    </div>
  <?php else: ?>
    <form class="login-card" method="post" action="/registro" novalidate>
      <?= csrf_field() ?>
      <h1>Creá tu cuenta</h1>
      <?php if ($error): ?>
        <p class="field-error" role="alert"><?= e($error) ?></p>
      <?php endif; ?>

      <label class="field" for="full_name">Nombre y apellido</label>
      <input id="full_name" name="full_name" type="text" autocomplete="name" maxlength="120" required autofocus
        value="<?= e($values['full_name']) ?>">

      <label class="field" for="dni">DNI</label>
      <input id="dni" name="dni" type="text" inputmode="numeric" autocomplete="off" placeholder="40.123.456"
        maxlength="10" required value="<?= $values['dni'] !== '' ? e(fmt_dni($values['dni'])) : '' ?>">

      <label class="field" for="phone">Celular (con código de área)</label>
      <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel-national" maxlength="30" required
        placeholder="3781 40-1440" aria-describedby="phone-hint" value="<?= e($values['phone']) ?>">
      <small class="field-hint" id="phone-hint">Solo números de Argentina. Sin el 0 ni el 15. Un número por cuenta.</small>

      <label class="field" for="password">Contraseña (mínimo 8 caracteres)</label>
      <input id="password" name="password" type="password" autocomplete="new-password" maxlength="72" required>

      <label class="field" for="password2">Repetí la contraseña</label>
      <input id="password2" name="password2" type="password" autocomplete="new-password" maxlength="72" required>

      <button class="btn-primary block" type="submit">Crear cuenta</button>
      <p class="auth-links"><a class="link" href="/login">Ya tengo cuenta</a></p>
    </form>
  <?php endif; ?>
  </main>
</div>
