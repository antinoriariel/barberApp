<?php
/**
 * pages/admin-usuarios.php — Cuentas de la barbería (solo administrador). Arriba el alta (DNI, nombre, email,
 * contraseña y rol); debajo un buscador y una tarjeta por cuenta con su estado (el badge suspende o da el alta),
 * la edición de datos y rol, el cambio de contraseña y la baja definitiva (solo cuentas sin historial). Si el rol es barbero se elige su ficha del equipo y qué
 * secciones del panel ve (admin-users.js oculta esos campos con otros roles; sin JS se ven siempre y el servidor
 * los ignora si el rol no es barbero).
 *
 * Variables: $user (User, el administrador), $accounts (User::allForAdmin()), $barbers (Barber::all()),
 *            $deletable (array id => índice, cuentas sin historial: User::deletableIds() invertido),
 *            $flash ('1' guardado | 'clave' contraseña cambiada | 'borrado' cuenta eliminada | ''), $error (string)
 */
use App\Models\User;

$barberNames = [];
foreach ($barbers as $b) {
    $barberNames[$b->key] = $b->name;
}

/**
 * Campos de rol de un formulario: selector de rol, ficha del equipo y secciones del panel (los tres cambian juntos).
 * $account es null en el alta.
 */
$roleFields = static function (?User $account) use ($barbers): void {
    $role = $account?->role() ?? 'cliente';
    ?>
        <label>Rol
          <select name="role" data-role-select required>
<?php foreach (User::ROLES as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $key === $role ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label data-barber-only>Ficha del equipo
          <select name="barber">
            <option value="">Elegí un barbero…</option>
<?php foreach ($barbers as $b): ?>
            <option value="<?= e($b->id) ?>"<?= $account?->barberId === $b->key ? ' selected' : '' ?>><?= e($b->name) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <fieldset class="wide perm-box" data-barber-only>
          <legend>Secciones del panel que ve</legend>
          <div class="perm-chips">
<?php foreach (User::SECTIONS as $key => $label): ?>
            <label class="perm-chip">
              <input type="checkbox" name="sections[]" value="<?= e($key) ?>"<?= in_array($key, $account?->panelAccess ?? [], true) ? ' checked' : '' ?>>
              <span><?= e($label) ?></span>
            </label>
<?php endforeach; ?>
          </div>
          <small>Sin ninguna tildada entra solo a la app, como un cliente. "Usuarios" es siempre exclusivo del administrador.</small>
        </fieldset>
    <?php
};
?>
<main class="admin">
  <?= partial('components/admin-header', ['heading' => 'Usuarios', 'sub' => count($accounts) . ' cuentas · altas, bajas, roles y contraseñas', 'sections' => $user->panelSections(), 'active' => 'usuarios']) ?>

  <?php if ($flash === '1'): ?><p class="admin-flash ok" role="status">Cambios guardados.</p><?php endif; ?>
  <?php if ($flash === 'clave'): ?><p class="admin-flash ok" role="status">Contraseña cambiada. Se cerraron las sesiones abiertas de esa cuenta.</p><?php endif; ?>
  <?php if ($flash === 'borrado'): ?><p class="admin-flash ok" role="status">Cuenta eliminada.</p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="admin-flash bad" role="alert"><?= e($error) ?></p><?php endif; ?>

  <section class="admin-card svc-edit user-card" id="usuario-nuevo" aria-labelledby="user-new">
    <h2 id="user-new">Agregar usuario</h2>
    <form method="post" action="/admin/usuarios/guardar" class="svc-edit" data-role-form>
      <?= csrf_field() ?>
      <div class="svc-fields">
        <label>DNI<input type="text" name="dni" inputmode="numeric" autocomplete="off" maxlength="12" required placeholder="Ej: 30.123.456"></label>
        <label>Contraseña<input type="password" name="password" autocomplete="new-password" minlength="<?= (int) \App\Core\Config::get('auth.min_password') ?>" maxlength="<?= \App\Support\Password::MAX ?>" required></label>
        <label class="wide">Nombre y apellido<input type="text" name="full_name" maxlength="120" autocomplete="off" required placeholder="Ej: Juan Pérez"></label>
        <label class="wide">Email<input type="email" name="email" maxlength="190" autocomplete="off" placeholder="Opcional: sirve para recuperar la contraseña"></label>
        <?php $roleFields(null); ?>
      </div>
      <div class="svc-row-actions"><button class="btn-small" type="submit">Agregar usuario</button></div>
    </form>
  </section>

  <h2 class="admin-section-title">Cuentas</h2>
  <input class="user-search" type="search" placeholder="Buscar por nombre, DNI o email…" aria-label="Buscar cuenta" data-user-search autocomplete="off">
  <p class="admin-empty" data-user-none hidden>Ninguna cuenta coincide con la búsqueda.</p>

  <?php foreach ($accounts as $a): ?>
    <?php $self = $a->id === $user->id; ?>
    <section class="admin-card svc-edit user-card<?= $a->active ? '' : ' off' ?>" id="usuario-<?= $a->id ?>"
             aria-label="<?= e($a->fullName) ?>"
             data-user-text="<?= e(mb_strtolower($a->fullName . ' ' . $a->dni . ' ' . $a->email)) ?>">
      <div class="svc-edit-head">
        <div class="user-id">
          <strong><?= e($a->fullName) ?><?= $self ? ' (vos)' : '' ?></strong>
          <small>
            DNI <?= e(\App\Support\Dni::format($a->dni)) ?> · <?= e(User::ROLES[$a->role()]) ?>
            <?php if ($a->isBarber()): ?> · <?= e($barberNames[$a->barberId] ?? 'ficha inactiva') ?><?php endif; ?>
            <?php if ($a->role() === 'cliente'): ?> · <?= $a->visits ?> <?= $a->visits === 1 ? 'visita' : 'visitas' ?><?php endif; ?>
          </small>
          <?php if ($a->isBarber() && !$a->isAdmin): ?>
            <small>Panel: <?= $a->panelAccess ? e(implode(', ', array_map(static fn (string $k) => User::SECTIONS[$k], $a->panelAccess))) : 'sin acceso' ?></small>
          <?php endif; ?>
        </div>
        <?php if ($self): ?>
          <span class="status done">Activa</span>
        <?php else: ?>
          <?php /* El badge es el interruptor: suspende la cuenta (no puede entrar, se cierran sus sesiones) o le da el alta de nuevo. */ ?>
          <form method="post" action="/admin/usuarios/estado" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $a->id ?>">
            <button type="submit" name="active" value="<?= $a->active ? '0' : '1' ?>"
                    class="status toggle <?= $a->active ? 'done' : 'cancelled' ?>"
                    <?= $a->active ? 'data-confirm="¿Suspender a ' . e($a->fullName) . '? No va a poder entrar hasta que le des el alta de nuevo."' : '' ?>
                    title="<?= $a->active ? 'Click para suspender' : 'Click para darle el alta' ?>"
                    aria-label="<?= e($a->fullName) ?>: <?= $a->active ? 'activa, suspender' : 'suspendida, dar de alta' ?>"><?= $a->active ? 'Activa' : 'Suspendida' ?></button>
          </form>
        <?php endif; ?>
      </div>

      <details class="user-more">
        <summary>Editar datos y rol</summary>
        <form method="post" action="/admin/usuarios/guardar" class="svc-edit" data-role-form>
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $a->id ?>">
          <div class="svc-fields">
            <label class="wide">Nombre y apellido<input type="text" name="full_name" value="<?= e($a->fullName) ?>" maxlength="120" required></label>
            <label class="wide">Email<input type="email" name="email" value="<?= e($a->email) ?>" maxlength="190" placeholder="Opcional"></label>
            <?php $roleFields($a); ?>
          </div>
          <div class="svc-row-actions"><button class="btn-small" type="submit">Guardar</button></div>
        </form>
      </details>

      <details class="user-more">
        <summary>Cambiar contraseña</summary>
        <form method="post" action="/admin/usuarios/clave" class="svc-edit">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $a->id ?>">
          <div class="svc-fields">
            <label>Nueva contraseña<input type="password" name="password" autocomplete="new-password" minlength="<?= (int) \App\Core\Config::get('auth.min_password') ?>" maxlength="<?= \App\Support\Password::MAX ?>" required></label>
            <label>Repetila<input type="password" name="confirm" autocomplete="new-password" maxlength="<?= \App\Support\Password::MAX ?>" required></label>
          </div>
          <div class="svc-row-actions"><button class="btn-small" type="submit">Cambiar contraseña</button></div>
        </form>
      </details>

      <?php if (!$self): ?>
      <details class="user-more">
        <summary>Dar de baja definitiva</summary>
        <?php if (isset($deletable[$a->id])): ?>
          <form method="post" action="/admin/usuarios/borrar" class="svc-edit">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $a->id ?>">
            <p class="admin-sub">Borra la cuenta para siempre. Solo se puede porque no tiene turnos ni movimientos.</p>
            <div class="svc-row-actions">
              <button class="btn-small danger" type="submit" data-confirm="¿Eliminar para siempre la cuenta de <?= e($a->fullName) ?>? No se puede deshacer.">Eliminar cuenta</button>
            </div>
          </form>
        <?php else: ?>
          <p class="admin-sub">Tiene historial (turnos, fila o movimientos), así que no se borra: suspendela con el botón “Activa” de arriba y su historial queda guardado.</p>
        <?php endif; ?>
      </details>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</main>
