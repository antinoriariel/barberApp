<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\DbSessionHandler;
use App\Core\Request;
use App\Core\Response;
use App\Models\Ban;
use App\Models\Barber;
use App\Models\User;
use App\Models\UserActivity;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\Device;
use App\Support\Password;

/**
 * Panel del superusuario (/su): todas las cuentas con sus IP, navegadores (user agent), última conexión y últimas
 * 3 acciones; edición, contraseña, suspensión y borrado completo; bloqueo de IP y "Permaban" de navegadores.
 *
 * A diferencia de /admin/usuarios no tiene las salvaguardas de "último administrador" (el superusuario está por
 * encima y siempre puede volver a entrar), pero sí nunca deja que se bloquee su propia IP ni su propio navegador.
 * Las acciones se auditan con user_id NULL y eventos su_*.
 */
final class SuperController extends Controller
{
    /**
     * Exige base de datos y sesión de superusuario (Auth::isSuperuser). Sin ninguna sesión manda al login; con la
     * sesión de una cuenta común responde 404, así no se delata que el panel existe.
     */
    private function requireSuper(): void
    {
        if (!Database::enabled()) {
            Response::error(404, 'No encontramos esa página');
        }
        if (!Auth::isSuperuser()) {
            if (Auth::user() === null) {
                $this->redirect('/login');
            }
            Response::error(404, 'No encontramos esa página');
        }
    }

    /** Vuelve al panel con un aviso de éxito (clave de $flash en la vista), anclado a #aviso. */
    private function done(string $flash): never
    {
        $this->redirect('/su?ok=' . rawurlencode($flash) . '#aviso');
    }

    /** Vuelve al panel con un error, anclado a #aviso. */
    private function fail(string $message): never
    {
        $this->redirect('/su?error=' . rawurlencode($message) . '#aviso');
    }

    /** Cuenta del campo POST "id" o vuelve con error si no existe. */
    private function target(Request $request): User
    {
        $id = (int) $request->input('id', 0);
        return ($id > 0 ? User::findById($id) : null) ?? $this->fail('Esa cuenta no existe.');
    }

    /**
     * GET /su — panel completo. `ok` y `error` traen el resultado de la acción anterior. Se marca "en línea" a quien
     * se vio en los últimos 5 minutos (Device::touch registra como mucho una vez por minuto).
     */
    public function index(Request $request): void
    {
        $this->requireSuper();
        $this->view('pages/su', [
            'title'      => 'Superusuario · Barbería',
            'bodyClass'  => 'admin-page',
            'scripts'    => ['js/admin.js', 'js/admin-users.js'],
            'accounts'   => User::allForAdmin(),
            'devices'    => UserActivity::devicesByUser(),
            'actions'    => UserActivity::recentActions(3),
            'history'    => User::historyCounts(),
            'ipBans'     => Ban::ips(),
            'deviceBans' => Ban::devices(),
            'bannedSet'  => Ban::deviceSet(),
            'barbers'    => Barber::all(),
            'myIp'       => $request->ip(),
            'myDevice'   => Device::hash(),
            'onlineFrom' => date('Y-m-d H:i:s', time() - 5 * 60),
            'flash'      => (string) $request->query('ok', ''),
            'error'      => (string) $request->query('error', ''),
        ]);
    }

    /**
     * POST /su/usuarios/guardar — edita nombre, email, rol y secciones de una cuenta (mismas reglas que
     * /admin/usuarios: User::validate + User::saveFromAdmin, que también resuelve la ficha del barbero).
     */
    public function saveUser(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $target = $this->target($request);

        [$data, $error] = User::validate([
            'full_name' => $request->input('full_name'), 'email' => $request->input('email'),
            'role' => $request->input('role'), 'sections' => $request->input('sections'),
        ], false);
        if ($error !== null) {
            $this->fail($error);
        }
        try {
            User::saveFromAdmin($target, $data);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $this->fail('Ese email ya es de otra cuenta.');
            }
            throw $e;
        }
        Audit::log('su_user_update', null, 'usuario ' . $target->id . ' · ' . $request->string('role', '', 20));
        $this->done('guardado');
    }

    /** POST /su/usuarios/clave — campos id, password, confirm. Pone una contraseña nueva y cierra todas sus sesiones. */
    public function setPassword(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $target = $this->target($request);

        $password = $request->string('password');
        if ($error = Password::validate($password)) {
            $this->fail($error);
        }
        if (!hash_equals($password, $request->string('confirm'))) {
            $this->fail('Las contraseñas no coinciden.');
        }
        Database::transaction(function () use ($target, $password) {
            User::setPassword($target->id, Password::hash($password));
            Database::execute('DELETE FROM password_resets WHERE user_id = ?', [$target->id]);
            DbSessionHandler::revokeUser($target->id);
        });
        Audit::log('su_user_password', null, 'usuario ' . $target->id);
        $this->done('clave');
    }

    /**
     * POST /su/usuarios/estado — campos id, active ('0' dar de baja, '1' reactivar). Como en /admin/usuarios: la baja
     * le impide entrar, cierra sus sesiones y oculta su ficha si es barbero.
     */
    public function toggleUser(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $target = $this->target($request);
        $active = $request->input('active') === '1';

        if ($active && $target->barberId !== null && User::barberTaken($target->barberId, $target->id)) {
            $this->fail('Su ficha del equipo ya está vinculada a otra cuenta activa: cambiale el rol antes de reactivarla.');
        }
        $this->setActive($target, $active);
        Audit::log($active ? 'su_user_enable' : 'su_user_disable', null, 'usuario ' . $target->id);
        $this->done('guardado');
    }

    /** Suspende o reactiva una cuenta junto con su ficha de barbero; al suspender cierra todas sus sesiones. */
    private function setActive(User $target, bool $active): void
    {
        Database::transaction(function () use ($target, $active) {
            User::setActive($target->id, $active);
            if ($target->barberId !== null) {
                Barber::setActive($target->barberId, $active);
            }
        });
        if (!$active) {
            DbSessionHandler::revokeUser($target->id);
        }
    }

    /** POST /su/usuarios/sesiones — campo id: cierra la sesión de la cuenta en todos sus dispositivos. */
    public function revokeSessions(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $target = $this->target($request);
        DbSessionHandler::revokeUser($target->id);
        Audit::log('su_user_logout', null, 'usuario ' . $target->id);
        $this->done('sesiones');
    }

    /**
     * POST /su/usuarios/borrar — campo id: elimina la cuenta CON su historial (User::purge: turnos, premios y fila; los
     * movimientos de caja quedan sin autor). Si era barbero su ficha se retira (se borra o, si atendió, se oculta).
     */
    public function deleteUser(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $target = $this->target($request);

        User::purge($target->id);
        if ($target->barberId !== null) {
            Barber::retire($target->barberId);
        }
        Audit::log('su_user_delete', null, 'usuario ' . $target->id . ' · ' . $target->dni);
        $this->done('borrado');
    }

    /**
     * POST /su/ip/bloquear — campos ip, reason. Desde ese momento la app responde 403 a esa IP (ver public/index.php).
     * No deja bloquear la IP desde la que está conectado el superusuario (se quedaría afuera).
     */
    public function banIp(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);

        $ip = Ban::normalizeIp($request->string('ip', '', 64));
        if ($ip === null) {
            $this->fail('Esa no es una dirección IP válida.');
        }
        if ($ip === Ban::normalizeIp($request->ip())) {
            $this->fail('Esa es tu propia IP: si la bloqueás te quedás afuera.');
        }
        Ban::banIp($ip, trim($request->string('reason', '', 255)));
        Audit::log('su_ip_ban', null, $ip);
        $this->done('ip');
    }

    /** POST /su/ip/desbloquear — campo ip: vuelve a atender a esa IP. */
    public function unbanIp(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);

        $ip = Ban::normalizeIp($request->string('ip', '', 64));
        if ($ip === null || !Ban::unbanIp($ip)) {
            $this->fail('Esa IP no estaba bloqueada.');
        }
        Audit::log('su_ip_unban', null, $ip);
        $this->done('desbloqueado');
    }

    /**
     * POST /su/permaban — campos id (cuenta) y, opcional, device (sha256 de un navegador de esa cuenta).
     *  - Con device: bloquea para siempre ese navegador y cierra las sesiones de la cuenta.
     *  - Sin device: bloquea TODOS los navegadores desde los que entró la cuenta, la suspende y cierra sus sesiones
     *    (si borra la cookie y vuelve con un navegador "limpio", igual no puede entrar con esa cuenta).
     * Nunca bloquea el navegador del propio superusuario (por si entró a esa cuenta desde la misma PC).
     */
    public function permaban(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $target = $this->target($request);
        $mine = Device::hash();
        $reason = 'Permaban de ' . $target->fullName;

        $one = $request->string('device', '', 64);
        if ($one !== '') {
            $device = UserActivity::device($target->id, $one);
            if ($device === null) {
                $this->fail('Ese navegador no es de esta cuenta.');
            }
            if ($one === $mine) {
                $this->fail('Ese es tu propio navegador: si lo bloqueás te quedás afuera.');
            }
            Ban::banDevice($one, $target->id, $device['user_agent'], $reason);
            DbSessionHandler::revokeUser($target->id);
            Audit::log('su_permaban', null, 'usuario ' . $target->id . ' · 1 navegador');
            $this->done('permaban');
        }

        $count = 0;
        foreach (UserActivity::devicesOf($target->id) as $device) {
            if ($device['device_hash'] !== $mine) {
                Ban::banDevice($device['device_hash'], $target->id, $device['user_agent'], $reason);
                $count++;
            }
        }
        $this->setActive($target, false);
        Audit::log('su_permaban', null, 'usuario ' . $target->id . ' · ' . $count . ' navegador(es) · cuenta suspendida');
        $this->done($count > 0 ? 'permaban' : 'permaban-sin');
    }

    /** POST /su/permaban/quitar — campo device: levanta el Permaban de ese navegador. */
    public function unbanDevice(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);

        $device = $request->string('device', '', 64);
        if (!preg_match('/^[a-f0-9]{64}$/D', $device) || !Ban::unbanDevice($device)) {
            $this->fail('Ese navegador no estaba bloqueado.');
        }
        Audit::log('su_device_unban', null, substr($device, 0, 12));
        $this->done('desbloqueado');
    }
}
