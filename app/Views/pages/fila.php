<?php
/**
 * pages/fila.php — Se abre al escanear el QR del mostrador: confirma "Tomar número" (POST, con CSRF) o, si el
 * cliente ya tiene uno hoy, se lo muestra. A un administrador no le ofrece número: lo manda a /admin/fila. Mismo marco que el login.
 * Variables: $code (código del QR, viaja en el formulario), $ticket (QueueTicket::currentFor o null), $user
 */
?>
<div class="login">
  <section class="login-side">
    <?= partial('components/brand') ?>
    <p class="login-tagline">Hola, <?= e($user->name) ?>.</p>
  </section>

  <main class="login-main">
    <div class="login-card queue-take">
      <?php if ($user->isAdmin): ?>
        <h1>Sos administrador</h1>
        <p class="muted">Las cuentas de administrador no pueden tomar número. La fila del día se maneja desde el panel.</p>
        <a class="btn-primary block" href="/admin/fila">Ver la fila</a>
      <?php elseif ($ticket): ?>
        <h1>Ya tenés tu número</h1>
        <p class="queue-take-number"><?= $ticket['number'] ?></p>
        <p class="muted">Lo ves y lo seguís desde el inicio de la app.</p>
        <a class="btn-primary block" href="/#inicio">Ir a la app</a>
      <?php else: ?>
        <h1>Tomá tu número</h1>
        <p class="muted">Te damos el siguiente de la fila. En el inicio de la app vas a ver detrás de qué número estás y cuándo te toca.</p>
        <form method="post" action="/fila">
          <?= csrf_field() ?>
          <input type="hidden" name="c" value="<?= e($code) ?>">
          <button class="btn-primary block" type="submit">Tomar número</button>
        </form>
        <p class="auth-links"><a class="link" href="/#inicio">Ahora no</a></p>
      <?php endif; ?>
    </div>
  </main>
</div>
