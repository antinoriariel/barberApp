<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\DbSessionHandler;
use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;
use App\Models\Ban;
use App\Models\Barber;
use App\Models\Registration;
use App\Models\User;
use App\Models\UserActivity;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\Device;
use App\Support\Password;

/**
 * Panel del superusuario (/su): todas las cuentas con sus IP, navegadores (user agent), última conexión y últimas
 * 3 acciones; edición, contraseña, suspensión y borrado completo; bloqueo de IP (temporal o permanente) y "Permaban" de
 * navegadores; las altas de cuenta por navegador (/su/registros, con liberación del navegador). Además el registro de auditoría completo (/su/auditoria, con exportación CSV) y el panel de seguridad
 * (/su/seguridad: fuerza bruta, cuentas desde muchas IP, navegadores nuevos, accesos del superusuario y retención).
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

    /** Avisos de éxito de las acciones (clave que viaja en ?ok= => texto). */
    private const FLASHES = [
        'guardado'     => 'Cambios guardados.',
        'clave'        => 'Contraseña cambiada. Se cerraron las sesiones abiertas de esa cuenta.',
        'sesiones'     => 'Se cerró la sesión de esa cuenta en todos sus dispositivos.',
        'sesiones-sin' => 'Esa cuenta no tenía ninguna sesión abierta: no había nada para cerrar.',
        'borrado'      => 'Cuenta eliminada con todo su historial.',
        'ip'           => 'IP bloqueada: la app ya no le responde.',
        'desbloqueado' => 'Bloqueo levantado.',
        'permaban'     => 'Permaban aplicado: ese navegador ya no puede usar la app.',
        'permaban-sin' => 'Cuenta suspendida. No tenía navegadores registrados para bloquear (todavía no había entrado con el rastreo activo).',
        'limpiado'     => 'Registros viejos de auditoría borrados.',
        'liberado'     => 'Navegador liberado: desde ahí se puede crear una cuenta nueva.',
    ];

    /** Pantallas a las que puede volver una acción (campo POST "volver"); cualquier otra cosa vuelve a /su. */
    private const BACK = ['seguridad' => '/su/seguridad', 'auditoria' => '/su/auditoria', 'registros' => '/su/registros'];

    /** Dirección a la que vuelve la acción actual según el campo "volver" (solo valores de BACK: nada externo). */
    private function back(): string
    {
        $to = (new Request())->input('volver');
        return is_string($to) && isset(self::BACK[$to]) ? self::BACK[$to] : '/su';
    }

    /** Vuelve a la pantalla de origen con un aviso de éxito (clave de FLASHES), anclado a #aviso. */
    private function done(string $flash): never
    {
        $this->redirect($this->back() . '?ok=' . rawurlencode($flash) . '#aviso');
    }

    /** Vuelve a la pantalla de origen con un error, anclado a #aviso. */
    private function fail(string $message): never
    {
        $this->redirect($this->back() . '?error=' . rawurlencode($message) . '#aviso');
    }

    /** Datos comunes de las vistas del superusuario: título, scripts del panel y el aviso de la acción anterior. */
    private function page(Request $request, string $title, array $data): array
    {
        $ok = (string) $request->query('ok', '');
        return $data + [
            'title'     => $title . ' · Superusuario',
            'bodyClass' => 'admin-page',
            'scripts'   => ['js/admin.js', 'js/admin-users.js'],
            'notice'    => self::FLASHES[$ok] ?? '',
            'error'     => mb_substr((string) $request->query('error', ''), 0, 200),
        ];
    }

    /** Filtros de la lista de cuentas en /su (?ver=): clave => título de la lista. */
    public const VIEWS = ['en-linea' => 'En línea ahora', 'de-baja' => 'Dadas de baja'];

    /** Cuenta del campo POST "id" o vuelve con error si no existe. */
    private function target(Request $request): User
    {
        $id = (int) $request->input('id', 0);
        return ($id > 0 ? User::findById($id) : null) ?? $this->fail('Esa cuenta no existe.');
    }

    /**
     * GET /su — panel completo. `ok` y `error` traen el resultado de la acción anterior. Se marca "en línea" a quien
     * se vio en los últimos 5 minutos (Device::touch registra como mucho una vez por minuto).
     * ?ver=en-linea muestra solo las cuentas en línea (el click en la tarjeta "En línea") y ?ver=de-baja solo las
     * suspendidas; cada una con todas sus opciones, igual que en la lista completa.
     */
    public function index(Request $request): void
    {
        $this->requireSuper();
        $accounts = User::allForAdmin();
        $devices = UserActivity::devicesByUser();
        $onlineFrom = date('Y-m-d H:i:s', time() - 5 * 60);
        $online = array_values(array_filter($accounts, static fn (User $a) => ($devices[$a->id][0]['last_seen_at'] ?? '') >= $onlineFrom));
        $inactive = array_values(array_filter($accounts, static fn (User $a) => !$a->active));
        $view = (string) $request->query('ver', '');
        $view = isset(self::VIEWS[$view]) ? $view : '';
        $this->view('pages/su', $this->page($request, 'Cuentas', [
            'accounts'    => match ($view) { 'en-linea' => $online, 'de-baja' => $inactive, default => $accounts },
            'total'       => count($accounts),
            'onlineCount' => count($online),
            'inactive'    => count($inactive),
            'listView'    => $view,
            'devices'     => $devices,
            'actions'    => UserActivity::recentActions(3),
            'history'    => User::historyCounts(),
            'ipBans'     => Ban::ips(),
            'deviceBans' => Ban::devices(),
            'bannedSet'  => Ban::deviceSet(),
            'barbers'    => Barber::team(),
            'myIp'       => $request->ip(),
            'myDevice'   => Device::hash(),
            'onlineFrom' => $onlineFrom,
        ]));
    }

    /**
     * GET /su/registros — altas de cuenta hechas desde /registro (una por navegador, ver Auth::register): fecha, datos
     * con los que se registró (DNI, nombre, teléfono), IP, navegador y estado actual de la cuenta, con filtros (texto,
     * IP, desde/hasta) y paginado. Marca las IP desde las que salió más de una alta y los navegadores con Permaban;
     * arriba los totales, incluidos los intentos rechazados por navegador o IP (enlazan a la auditoría).
     */
    public function registrations(Request $request): void
    {
        $this->requireSuper();
        $filters = Registration::filters($_GET);
        $page = max(1, (int) $request->query('pagina', 1));
        $this->view('pages/su-registros', $this->page($request, 'Registros', [
            'filters'  => $filters,
            'result'   => Registration::search($filters, $page),
            'page'     => $page,
            'totals'   => Registration::totals(),
            'maxIp'    => (int) \App\Core\Config::get('security.register_max_ip_day'),
            'myIp'     => $request->ip(),
            'myDevice' => Device::hash(),
        ]));
    }

    /**
     * POST /su/registros/liberar — campo id (alta): libera ese navegador para que pueda crear otra cuenta (por ejemplo,
     * una familia que comparte el celular). El alta sigue en la lista con la fecha de liberación, y queda auditado.
     */
    public function releaseRegistration(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $id = (int) $request->input('id', 0);
        $row = $id > 0 ? Registration::find($id) : null;
        if ($row === null || !Registration::release($id)) {
            $this->fail('Esa alta no existe o su navegador ya estaba liberado.');
        }
        Audit::log('su_registration_release', null, 'alta ' . $id . ' · DNI ' . $row['dni'] . ' · navegador ' . substr($row['device_hash'], 0, 12));
        $this->done('liberado');
    }

    /**
     * GET /su/auditoria — registro completo con filtros (tipo, cuenta, IP, texto, desde/hasta; ver AuditLog::filters)
     * y paginado de a AuditLog::PER_PAGE. Incluye las acciones del superusuario y los logins fallidos.
     */
    public function audit(Request $request): void
    {
        $this->requireSuper();
        $filters = AuditLog::filters($_GET);
        $page = max(1, (int) $request->query('pagina', 1));
        $this->view('pages/su-auditoria', $this->page($request, 'Auditoría', [
            'filters' => $filters,
            'result'  => AuditLog::search($filters, $page),
            'page'    => $page,
            'account' => $filters['usuario'] > 0 ? User::findById($filters['usuario']) : null,
        ]));
    }

    /**
     * GET /su/auditoria/exportar — los mismos filtros en CSV (separado por ";" y con BOM, para que Excel en español lo
     * abra bien; hasta AuditLog::EXPORT_MAX filas). Las celdas que empiezan con = + - @ se prefijan con ' para que una
     * hoja de cálculo no las ejecute como fórmula (inyección CSV: el user agent o el detalle los escribe cualquiera).
     * La exportación queda auditada.
     */
    public function auditCsv(Request $request): void
    {
        $this->requireSuper();
        $filters = AuditLog::filters($_GET);
        $rows = AuditLog::export($filters);
        Audit::log('su_audit_export', null, count($rows) . ' filas');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="auditoria-' . date('Y-m-d-His') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $safe = static fn (?string $v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
        fputcsv($out, ['Fecha', 'Evento', 'Descripción', 'Quién', 'DNI', 'IP', 'Navegador', 'User agent', 'Detalle'], ';');
        foreach ($rows as $r) {
            fputcsv($out, array_map($safe, [
                $r['created_at'], $r['event'], UserActivity::label($r['event']), AuditLog::actor($r), $r['dni'],
                $r['ip'], UserActivity::browser((string) $r['user_agent']), $r['user_agent'], $r['detail'],
            ]), ';');
        }
        fclose($out);
        exit;
    }

    /**
     * GET /su/seguridad — alertas de las últimas 24 h: IP con muchos logins fallidos (con bloqueo en un click), cuentas
     * atacadas, cuentas usadas desde muchas IP, navegadores nuevos y accesos del propio superusuario; además el tamaño
     * del registro de auditoría y la limpieza de lo viejo.
     */
    public function security(Request $request): void
    {
        $this->requireSuper();
        $ips = AuditLog::suspiciousIps();
        $blocked = [];
        foreach ($ips as $r) {
            $blocked[$r['ip']] = Ban::ipBlocked($r['ip']);
        }
        $this->view('pages/su-seguridad', $this->page($request, 'Seguridad', [
            'totals'     => AuditLog::totals(),
            'ips'        => $ips,
            'blocked'    => $blocked,
            'targeted'   => AuditLog::targetedAccounts(),
            'multiIp'    => AuditLog::multiIpAccounts(),
            'newDevices' => AuditLog::newDevices(),
            'suAccess'   => AuditLog::superuserAccess(),
            'size'       => AuditLog::size(),
            'myIp'       => $request->ip(),
            'myDevice'   => Device::hash(),
        ]));
    }

    /** Antigüedades que se pueden borrar del registro de auditoría (días). */
    public const PURGE_DAYS = [90, 180, 365];

    /**
     * POST /su/auditoria/limpiar — campo days (uno de PURGE_DAYS): borra los registros de auditoría más viejos que eso.
     * La limpieza misma queda registrada (con cuántas filas se borraron), así que nunca desaparece sin rastro.
     */
    public function purgeAudit(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $days = (int) $request->input('days', 0);
        if (!in_array($days, self::PURGE_DAYS, true)) {
            $this->fail('Elegí una antigüedad válida.');
        }
        $deleted = AuditLog::purgeOlderThan($days);
        Audit::log('su_audit_purge', null, "más de {$days} días · {$deleted} filas");
        $this->done('limpiado');
    }

    /**
     * POST /su/usuarios/guardar — edita nombre, teléfono, rol y secciones de una cuenta (mismas reglas que
     * /admin/usuarios: User::validate + User::saveFromAdmin, que también resuelve la ficha del barbero).
     */
    public function saveUser(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $target = $this->target($request);

        [$data, $error] = User::validate([
            'full_name' => $request->input('full_name'), 'phone' => $request->input('phone'),
            'role' => $request->input('role'), 'sections' => $request->input('sections'),
        ], false);
        if ($error !== null) {
            $this->fail($error);
        }
        try {
            User::saveFromAdmin($target, $data);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $this->fail('Ese teléfono ya es de otra cuenta.');
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

    /**
     * POST /su/usuarios/sesiones — campo id: cierra la sesión de la cuenta en todos sus dispositivos. Si no tenía
     * ninguna abierta lo avisa distinto, para que no parezca que el botón no hizo nada.
     */
    public function revokeSessions(Request $request): void
    {
        $this->requireSuper();
        $this->requireCsrf($request);
        $target = $this->target($request);
        $closed = DbSessionHandler::revokeUser($target->id);
        Audit::log('su_user_logout', null, 'usuario ' . $target->id . ' · ' . $closed . ' sesión(es)');
        $this->done($closed > 0 ? 'sesiones' : 'sesiones-sin');
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
     * POST /su/ip/bloquear — campos ip, reason, duration (clave de Ban::DURATIONS; sin ella, permanente). Desde ese momento la app responde 403 a esa IP (ver public/index.php).
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
        $duration = Ban::DURATIONS[$request->string('duration', 'siempre', 10)] ?? Ban::DURATIONS['siempre'];
        Ban::banIp($ip, trim($request->string('reason', '', 255)), $duration[0]);
        Audit::log('su_ip_ban', null, $ip . ' · ' . $duration[1]);
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
