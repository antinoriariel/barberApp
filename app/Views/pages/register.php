<?php
/**
 * pages/register.php — Alta de cuenta (nombre y apellido, DNI, email y contraseña).
 * Variables: $error (string), $values (array dni, full_name, email: lo ya escrito).
 * El DNI lleva el rango aceptado (Dni::MIN_REGISTRO..MAX_REGISTRO) en data-min/data-max para que login.js
 * avise antes de enviar; el servidor lo vuelve a validar en Auth::register.
 */
?>
<div class="login">
  <section class="login-side">
    <?= partial('components/brand') ?>
    <p class="login-tagline">Tu turno, a un toque.</p>
  </section>

  <main class="login-main">
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
        maxlength="10" required value="<?= $values['dni'] !== '' ? e(fmt_dni($values['dni'])) : '' ?>"
        data-min="<?= \App\Support\Dni::MIN_REGISTRO ?>" data-max="<?= \App\Support\Dni::MAX_REGISTRO ?>"
        data-error="<?= e(\App\Services\Auth::dniRangeError()) ?>" aria-describedby="dni-error">
      <p class="field-error" id="dni-error" role="alert" hidden></p>

      <label class="field" for="email">Email</label>
      <input id="email" name="email" type="email" autocomplete="email" maxlength="190" required
        value="<?= e($values['email']) ?>">

      <label class="field" for="password">Contraseña (mínimo 8 caracteres)</label>
      <input id="password" name="password" type="password" autocomplete="new-password" maxlength="72" required>

      <label class="field" for="password2">Repetí la contraseña</label>
      <input id="password2" name="password2" type="password" autocomplete="new-password" maxlength="72" required>

      <button class="btn-primary block" type="submit">Crear cuenta</button>
      <p class="auth-links"><a class="link" href="/login">Ya tengo cuenta</a></p>
    </form>
  </main>
</div>
