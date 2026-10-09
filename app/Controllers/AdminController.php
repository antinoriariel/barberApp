<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Dashboard;
use App\Models\Expense;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Services\Audit;
use App\Services\Qr;

/**
 * Panel de administración: métricas del día (ingresos y turnos atendidos por barbero, comparados con el
 * día anterior) y cierre de los turnos del día. Solo para usuarios con is_admin y con base de datos.
 */
final class AdminController extends Controller
{
    /**
     * Exige sesión, base de datos y rol de administrador.
     * Sin sesión redirige al login; sin base de datos responde 503; si no es admin, 403 (sin pistas de qué hay).
     */
    private function requireAdmin(): User
    {
        $user = $this->requireLogin();
        if (!Database::enabled()) {
            Response::error(503, 'Panel no disponible', 'El panel necesita la base de datos (DB_DRIVER=db).');
        }
        if (!$user->isAdmin) {
            Response::error(403, 'Sin permiso', 'Tu cuenta no tiene acceso a esta sección.');
        }
        return $user;
    }

    /** Valida una fecha "YYYY-MM-DD" real; cualquier otra cosa (o vacío) devuelve el día de hoy. */
    private function day(mixed $raw): string
    {
        $raw = is_string($raw) ? $raw : '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $d && $d->format('Y-m-d') === $raw ? $raw : date('Y-m-d');
    }

    /** Valida un mes "YYYY-MM"; cualquier otra cosa (o vacío) devuelve el mes actual. */
    private function month(mixed $raw): string
    {
        $raw = is_string($raw) ? $raw : '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m', $raw);
        return $d && $d->format('Y-m') === $raw ? $raw : date('Y-m');
    }

    /** Datos comunes de la navegación por día de las vistas del panel (fecha, anterior, siguiente, ¿es hoy?). */
    private function dayNav(string $day): array
    {
        return [
            'day'     => $day,
            'prev'    => date('Y-m-d', strtotime($day . ' -1 day')),
            'next'    => date('Y-m-d', strtotime($day . ' +1 day')),
            'isToday' => $day === date('Y-m-d'),
        ];
    }

    /**
     * GET /admin?fecha=YYYY-MM-DD — resumen: cortes e ingresos del día frente al anterior, egresos del día y
     * resultado del día (ingresos − egresos), ingresos y egresos del mes, resultado y gráfico de los últimos
     * 6 meses, y desempeño de los barberos en el día.
     */
    public function dashboard(Request $request): void
    {
        $user = $this->requireAdmin();
        $day = $this->day($request->query('fecha'));
        $today = Dashboard::day($day);
        $yesterday = Dashboard::day(date('Y-m-d', strtotime($day . ' -1 day')));
        $finance = Dashboard::finance($day);
        $dayExpenses = Expense::totalForDay($day);

        $this->view('pages/admin', $this->dayNav($day) + [
            'title'        => 'Panel · Barbería',
            'bodyClass'    => 'admin-page',
            'user'         => $user,
            'active'       => 'resumen',
            'finance'      => $finance,
            'monthDelta'   => Dashboard::delta($finance[5]['revenue'], $finance[4]['revenue']),
            'today'        => $today,
            'yesterday'    => $yesterday,
            'dayExpenses'  => $dayExpenses,
            'dayNet'       => $today['total'] - $dayExpenses,
            'revenueDelta' => Dashboard::delta($today['total'], $yesterday['total']),
            'servedDelta'  => Dashboard::delta($today['served'], $yesterday['served']),
            'verdict'      => Dashboard::verdict($today, $yesterday),
        ]);
    }

    /** GET /admin/turnos?fecha=YYYY-MM-DD — agenda del día para marcar turnos como atendidos o ausentes. */
    public function appointments(Request $request): void
    {
        $user = $this->requireAdmin();
        $day = $this->day($request->query('fecha'));
        $this->view('pages/admin-turnos', $this->dayNav($day) + [
            'title'        => 'Turnos · Barbería',
            'bodyClass'    => 'admin-page',
            'user'         => $user,
            'active'       => 'turnos',
            'appointments' => Dashboard::appointments($day),
            'flash'        => (string) $request->query('ok', ''),
        ]);
    }

    /** GET /admin/barberos?fecha=YYYY-MM-DD — cortes atendidos por barbero en la semana (lunes a domingo) de esa fecha. */
    public function barbers(Request $request): void
    {
        $user = $this->requireAdmin();
        $week = Dashboard::week($this->day($request->query('fecha')));
        $this->view('pages/admin-barberos', [
            'title'     => 'Barberos · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'active'    => 'barberos',
            'week'      => $week,
            'prev'      => date('Y-m-d', strtotime($week['from'] . ' -7 days')),
            'next'      => date('Y-m-d', strtotime($week['from'] . ' +7 days')),
            'isCurrent' => $week['from'] === Dashboard::week(date('Y-m-d'))['from'],
        ]);
    }

    /** GET /admin/finanzas?mes=YYYY-MM — ingresos vs egresos del mes y gráfico de 6 meses (los egresos se cargan en /admin/egresos). */
    public function finance(Request $request): void
    {
        $user = $this->requireAdmin();
        $month = $this->month($request->query('mes'));
        $this->view('pages/admin-finanzas', [
            'title'     => 'Finanzas · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'active'    => 'finanzas',
            'month'     => $month,
            'prev'      => date('Y-m', strtotime($month . '-01 -1 month')),
            'next'      => date('Y-m', strtotime($month . '-01 +1 month')),
            'isCurrent' => $month === date('Y-m'),
            'finance'   => Dashboard::finance($month . '-01'),
            'expenses'  => Expense::forMonth($month),
        ]);
    }

    /**
     * GET /admin/egresos?mes=YYYY-MM — egresos del mes: alta (gasto, categoría, monto y observación), listado
     * con baja y totales; el resultado (ingresos − egresos) es el mismo que usan el resumen y finanzas.
     */
    public function expenses(Request $request): void
    {
        $user = $this->requireAdmin();
        $month = $this->month($request->query('mes'));
        $this->view('pages/admin-egresos', [
            'title'     => 'Egresos · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'active'    => 'egresos',
            'scripts'   => ['js/money-input.js'],
            'month'     => $month,
            'prev'      => date('Y-m', strtotime($month . '-01 -1 month')),
            'next'      => date('Y-m', strtotime($month . '-01 +1 month')),
            'isCurrent' => $month === date('Y-m'),
            'totals'    => Dashboard::finance($month . '-01', 1)[0],
            'expenses'  => Expense::forMonth($month),
            'flash'     => (string) $request->query('ok', ''),
            'error'     => (string) $request->query('error', ''),
        ]);
    }

    /** POST /admin/egresos — registra un egreso. Valida con Expense::validate, exige CSRF y rol, y audita. */
    public function saveExpense(Request $request): void
    {
        $user = $this->requireAdmin();
        $this->requireCsrf($request);

        [$data, $error] = Expense::validate([
            'spent_on' => $request->input('spent_on'), 'category' => $request->input('category'),
            'amount' => $request->input('amount'), 'description' => $request->input('description'),
            'notes' => $request->input('notes'),
        ]);
        if ($error !== null) {
            $this->redirect('/admin/egresos?mes=' . $this->month(substr((string) $request->input('spent_on', ''), 0, 7)) . '&error=' . rawurlencode($error));
        }
        $id = Expense::create($data, $user->id);
        Audit::log('admin_expense_create', $user->id, 'egreso ' . $id . ' · $' . $data['amount']);
        $this->redirect('/admin/egresos?mes=' . substr($data['spent_on'], 0, 7) . '&ok=1');
    }

    /** POST /admin/egresos/borrar — elimina un egreso cargado por error (queda en la auditoría). */
    public function deleteExpense(Request $request): void
    {
        $user = $this->requireAdmin();
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        if ($id > 0 && Expense::delete($id)) {
            Audit::log('admin_expense_delete', $user->id, 'egreso ' . $id);
        }
        $this->redirect('/admin/egresos?mes=' . $this->month($request->input('mes')) . '&ok=1');
    }

    /**
     * POST /admin/turnos/estado — marca un turno reservado como atendido ('done') o ausente ('no_show')
     * y vuelve a la agenda del mismo día. Exige CSRF y rol; queda en la auditoría.
     */
    public function mark(Request $request): void
    {
        $user = $this->requireAdmin();
        $this->requireCsrf($request);

        $day = $this->day($request->input('fecha'));
        $status = (string) $request->input('status', '');
        $id = (int) $request->input('id', 0);

        $ok = $id > 0 && Dashboard::mark($id, $status);
        if ($ok) {
            Audit::log('admin_appointment_' . $status, $user->id, 'turno ' . $id);
        }
        $this->redirect('/admin/turnos?fecha=' . $day . '&ok=' . ($ok ? '1' : '0'));
    }

    /**
     * GET /admin/fila — fila por orden de llegada de hoy: el QR para imprimir y pegar en el mostrador (lleva la URL
     * pública de la app + el código de config queue.code), el número que se está atendiendo y la lista del día.
     */
    public function queue(Request $request): void
    {
        $user = $this->requireAdmin();
        $url = rtrim((string) Config::get('url'), '/') . '/fila?c=' . rawurlencode((string) Config::get('queue.code'));
        $this->view('pages/admin-fila', [
            'title'     => 'Fila · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'qr'      => Qr::svg($url, 260),
            'qrUrl'   => $url,
            'serving' => QueueTicket::serving(),
            'tickets' => QueueTicket::todayList(),
            'flash'   => (string) $request->query('ok', ''),
        ]);
    }

    /** POST /admin/fila/llamar — da por atendido al número actual y llama al siguiente que espera. */
    public function queueCall(Request $request): void
    {
        $user = $this->requireAdmin();
        $this->requireCsrf($request);
        $number = QueueTicket::callNext();
        Audit::log('admin_queue_call', $user->id, $number === null ? 'fila vacía' : '#' . $number);
        $this->redirect('/admin/fila?ok=' . ($number === null ? 'vacia' : 'llamado'));
    }

    /** POST /admin/fila/ausente — campo id: marca un número de hoy como "no vino". */
    public function queueNoShow(Request $request): void
    {
        $user = $this->requireAdmin();
        $this->requireCsrf($request);
        $id = (int) $request->input('id', 0);
        if ($id > 0 && QueueTicket::markNoShow($id)) {
            Audit::log('admin_queue_no_show', $user->id, 'número ' . $id);
        }
        $this->redirect('/admin/fila');
    }

    /**
     * GET /admin/servicios — editor del catálogo: precio, nombre, descripción y duración de cada servicio,
     * alta de servicios nuevos y ocultar/mostrar. `ok` y `error` traen el resultado de la acción anterior.
     */
    public function services(Request $request): void
    {
        $user = $this->requireAdmin();
        $this->view('pages/admin-services', [
            'title'     => 'Servicios · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'active'    => 'servicios',
            'services'  => Service::allForAdmin(),
            'flash'     => (string) $request->query('ok', ''),
            'error'     => (string) $request->query('error', ''),
        ]);
    }

    /**
     * POST /admin/servicios/guardar — edita un servicio existente (id) o crea uno nuevo (sin id).
     * Valida con Service::validate, exige CSRF y rol, y deja el cambio en la auditoría.
     */
    public function saveService(Request $request): void
    {
        $user = $this->requireAdmin();
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        [$data, $error] = Service::validate([
            'name' => $request->input('name'), 'description' => $request->input('description'),
            'price' => $request->input('price'), 'minutes' => $request->input('minutes'),
        ]);
        if ($error !== null) {
            $this->redirect('/admin/servicios?error=' . rawurlencode($error));
        }
        if ($id > 0) {
            if (!Service::update($id, $data)) {
                $this->redirect('/admin/servicios?error=' . rawurlencode('Ese servicio no existe.'));
            }
            Audit::log('admin_service_update', $user->id, 'servicio ' . $id . ' · $' . $data['price']);
        } else {
            $id = Service::create($data);
            Audit::log('admin_service_create', $user->id, 'servicio ' . $id . ' · ' . $data['name']);
        }
        $this->redirect('/admin/servicios?ok=1');
    }

    /** POST /admin/servicios/estado — oculta ('0') o muestra ('1') un servicio en el catálogo. */
    public function toggleService(Request $request): void
    {
        $user = $this->requireAdmin();
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        $active = $request->input('active') === '1';
        if ($id > 0) {
            Service::setActive($id, $active);
            Audit::log($active ? 'admin_service_show' : 'admin_service_hide', $user->id, 'servicio ' . $id);
        }
        $this->redirect('/admin/servicios?ok=1');
    }
}
