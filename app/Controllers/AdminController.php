<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\DbSessionHandler;
use App\Core\Request;
use App\Core\Response;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\BarberHours;
use App\Models\Dashboard;
use App\Models\DeadHour;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Product;
use App\Models\QueueTicket;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\Audit;
use App\Services\BarberPhoto;
use App\Services\Qr;
use App\Support\Password;

/**
 * Panel de administración: métricas del día (ingresos y turnos atendidos por barbero, comparados con el
 * día anterior), cierre de los turnos del día, finanzas, catálogo y usuarios. Requiere base de datos. El administrador
 * (is_admin) entra a todo; un barbero solo a las secciones que el administrador le habilitó (ver requireSection).
 */
final class AdminController extends Controller
{
    /**
     * Renderiza una vista del panel sumando js/admin.js (selector de tema de la cabecera) a los scripts
     * que pida cada acción, así todas las vistas del panel lo tienen sin repetirlo en cada una.
     */
    protected function view(string $template, array $data = [], string $layout = 'layouts/base'): void
    {
        $data['scripts'] = array_merge(['js/admin.js'], $data['scripts'] ?? []);
        parent::view($template, $data, $layout);
    }

    /**
     * Exige sesión, base de datos y permiso sobre una sección del panel (ver User::canSee): el administrador entra
     * a todas; un barbero, solo a las que se le habilitaron en /admin/usuarios. Sin sesión redirige al login; sin base
     * de datos responde 503; sin permiso, 403 (sin pistas de qué hay). Excepción: si un barbero sin "resumen" entra a
     * /admin, se lo manda a la primera sección que sí tiene, así el enlace al panel le sirve igual.
     */
    private function requireSection(string $section): User
    {
        $user = $this->requireLogin();
        if (!Database::enabled()) {
            Response::error(503, 'Panel no disponible', 'El panel necesita la base de datos (DB_DRIVER=db).');
        }
        if (!$user->canSee($section)) {
            $first = $user->panelSections()[0] ?? null;
            if ($section === 'resumen' && $first !== null) {
                $this->redirect(self::SECTION_URLS[$first]);
            }
            Response::error(403, 'Sin permiso', 'Tu cuenta no tiene acceso a esta sección.');
        }
        return $user;
    }

    /** Dirección de cada sección del panel (para mandar a un barbero a la primera que tiene habilitada). */
    private const SECTION_URLS = [
        'semana' => '/admin/mi-semana', 'resumen' => '/admin', 'turnos' => '/admin/turnos', 'hora-muerta' => '/admin/hora-muerta', 'fila' => '/admin/fila', 'barberos' => '/admin/barberos',
        'finanzas' => '/admin/finanzas', 'ingresos' => '/admin/ingresos', 'egresos' => '/admin/egresos',
        'servicios' => '/admin/servicios', 'usuarios' => '/admin/usuarios',
    ];

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
     * GET /admin?fecha=YYYY-MM-DD — resumen: cortes e ingresos del día frente al anterior, egresos e ingresos
     * extra del día y resultado del día (ingresos + extra − egresos), ingresos y egresos del mes, resultado y
     * gráfico de los últimos 6 meses, desempeño de los barberos en el día y, al final, clientes nuevos contra
     * recurrentes del mes con el top 3 de los que más gastaron.
     */
    public function dashboard(Request $request): void
    {
        $user = $this->requireSection('resumen');
        $day = $this->day($request->query('fecha'));
        $today = Dashboard::day($day);
        $yesterday = Dashboard::day(date('Y-m-d', strtotime($day . ' -1 day')));
        $finance = Dashboard::finance($day);
        $dayExpenses = Expense::totalForDay($day);
        $dayExtra = Income::totalForDay($day);

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
            'dayExtra'     => $dayExtra,
            'dayNet'       => $today['total'] + $dayExtra - $dayExpenses,
            'revenueDelta' => Dashboard::delta($today['total'], $yesterday['total']),
            'servedDelta'  => Dashboard::delta($today['served'], $yesterday['served']),
            'verdict'      => Dashboard::verdict($today, $yesterday),
            'clients'      => Dashboard::clients($day),
        ]);
    }

    /**
     * GET /admin/mi-semana?fecha=YYYY-MM-DD — tablero propio del barbero: solo su fila de la tabla de cortes de la
     * semana (lunes a domingo de esa fecha), con lo atendido cada día, el total y los ingresos. Toma la ficha de la
     * sesión (users.barber_id), nunca de la URL, así un barbero no puede ver la semana de otro.
     */
    public function myWeek(Request $request): void
    {
        $user = $this->requireSection(User::MY_WEEK);
        $week = Dashboard::week($this->day($request->query('fecha')));
        $mine = array_values(array_filter($week['barbers'], static fn (array $b) => $b['id'] === $user->barberId));
        $this->view('pages/admin-mi-semana', [
            'title'     => 'Mi semana · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'week'      => $week,
            'rows'      => $mine,
            'prev'      => date('Y-m-d', strtotime($week['from'] . ' -7 days')),
            'next'      => date('Y-m-d', strtotime($week['from'] . ' +7 days')),
            'isCurrent' => $week['from'] === Dashboard::week(date('Y-m-d'))['from'],
        ]);
    }

    /** GET /admin/turnos?fecha=YYYY-MM-DD — agenda del día para marcar turnos como atendidos o ausentes. */
    public function appointments(Request $request): void
    {
        $user = $this->requireSection('turnos');
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

    /**
     * Exige poder modificar los días del local y los horarios de los barberos: solo el administrador. Un barbero con
     * la sección Barberos habilitada los ve en modo lectura y, si igual manda el formulario, recibe 403.
     */
    private function requireHoursEditor(): User
    {
        $user = $this->requireSection('barberos');
        if (!$user->isAdmin) {
            Response::error(403, 'Solo lectura', 'Los días del local y los horarios los carga y edita el administrador.');
        }
        return $user;
    }

    /**
     * GET /admin/barberos?fecha=YYYY-MM-DD — cortes atendidos por barbero en la semana (lunes a domingo) de esa fecha
     * y, debajo, el horario semanal de cada barbero (lo que usa la agenda para ofrecer turnos). Se listan también los
     * deshabilitados (Barber::team) para poder volver a habilitarlos. Para un barbero (canEdit = false) los días del
     * local, los horarios y el estado de cada barbero se muestran en modo lectura, sin formularios.
     */
    public function barbers(Request $request): void
    {
        $user = $this->requireSection('barberos');
        $week = Dashboard::week($this->day($request->query('fecha')));
        $this->view('pages/admin-barberos', [
            'title'     => 'Barberos · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'active'    => 'barberos',
            'canEdit'   => $user->isAdmin,
            'week'      => $week,
            'prev'      => date('Y-m-d', strtotime($week['from'] . ' -7 days')),
            'next'      => date('Y-m-d', strtotime($week['from'] . ' +7 days')),
            'isCurrent' => $week['from'] === Dashboard::week(date('Y-m-d'))['from'],
            'team'      => array_map(static fn (Barber $b) => ['barber' => $b, 'hours' => BarberHours::week($b->key),
                // Deshabilitado: cuántos turnos le quedan reservados (no se cancelan solos; la tarjeta avisa).
                'pending' => $b->enabled ? 0 : Barber::upcomingCount($b->key)], Barber::team()),
            'weekdays'  => Schedule::weekdayStatus(),
            'flash'     => (string) $request->query('ok', ''),
            'error'     => (string) $request->query('error', ''),
            'stranded'  => (int) $request->query('turnos', 0),
        ]);
    }

    /**
     * POST /admin/barberos/estado — campos: barber (slug), enabled ('1' habilitar | '0' deshabilitar). Deshabilitado,
     * el barbero no figura en el equipo de la app ni en la reserva (ni en "cualquiera"); sigue en el panel. Los turnos
     * que ya tenía reservados no se cancelan: su tarjeta avisa cuántos quedan para que el admin los revise en Turnos.
     */
    public function toggleBarber(Request $request): void
    {
        $user = $this->requireStaffEditor();
        $this->requireCsrf($request);

        $barber = Barber::find((string) $request->input('barber', ''), true);
        if ($barber === null) {
            $this->redirect('/admin/barberos?error=' . rawurlencode('Ese barbero no existe.'));
        }
        $enabled = $request->input('enabled') === '1';
        Barber::setEnabled($barber->key, $enabled);
        Audit::log($enabled ? 'admin_barber_enable' : 'admin_barber_disable', $user->id, 'barbero ' . $barber->key);
        $this->redirect('/admin/barberos#horario-' . rawurlencode($barber->id));
    }

    /**
     * POST /admin/barberos/nombre — campos: barber (slug), name. Cambia el nombre con el que el barbero figura en la
     * app, el panel y los tickets (2 a 80 caracteres). El slug no cambia (lo usan los enlaces y el horario) y la cuenta
     * vinculada, si tiene, conserva su propio nombre.
     */
    public function renameBarber(Request $request): void
    {
        $user = $this->requireStaffEditor();
        $this->requireCsrf($request);

        $barber = Barber::find((string) $request->input('barber', ''), true);
        if ($barber === null) {
            $this->redirect('/admin/barberos?error=' . rawurlencode('Ese barbero no existe.'));
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $request->input('name', '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $this->redirect('/admin/barberos?error=' . rawurlencode('El nombre tiene que tener entre 2 y 80 caracteres.') . '#horario-' . rawurlencode($barber->id));
        }
        Barber::rename($barber->key, $name);
        Audit::log('admin_barber_rename', $user->id, 'barbero ' . $barber->key);
        $this->redirect('/admin/barberos?ok=nombre#horario-' . rawurlencode($barber->id));
    }

    /**
     * POST /admin/barberos/dias — campo: open[] (weekdays 0–6 que abre el local). Los que no vienen quedan cerrados.
     * Si un día recién cerrado ya tenía turnos reservados no se cancelan: se avisa cuántos hay para que el admin decida.
     */
    public function saveShopDays(Request $request): void
    {
        $user = $this->requireHoursEditor();
        $this->requireCsrf($request);

        $input = $request->input('open', []);
        $open = array_values(array_unique(array_filter(
            array_map('intval', is_array($input) ? array_filter($input, 'is_string') : []),
            static fn (int $w) => $w >= 0 && $w <= 6
        )));
        Schedule::saveOpenWeekdays($open);
        Audit::log('admin_shop_days', $user->id, 'abre: ' . (implode(',', $open) ?: 'ninguno'));

        $stranded = Appointment::countUpcomingOnWeekdays(array_values(array_diff(range(0, 6), $open)));
        $this->redirect('/admin/barberos?ok=dias' . ($stranded > 0 ? '&turnos=' . $stranded : '') . '#dias-local');
    }

    /**
     * POST /admin/barberos/horario — campos: barber (slug), days[weekday][works|from|to]. Guarda el horario semanal
     * de un barbero; solo se aceptan los días en que el local da turnos (los demás no se usan para reservar).
     * Los turnos ya reservados no se tocan: el cambio vale para las reservas nuevas. Un barbero deshabilitado también
     * se puede editar (el horario queda listo para cuando se lo vuelva a habilitar).
     */
    public function saveBarberHours(Request $request): void
    {
        $user = $this->requireHoursEditor();
        $this->requireCsrf($request);

        $barber = Barber::find((string) $request->input('barber', ''), true);
        if ($barber === null) {
            $this->redirect('/admin/barberos?error=' . rawurlencode('Ese barbero no existe.'));
        }
        $bookable = array_keys(array_filter(Schedule::weekdayStatus(), static fn (string $s) => $s === 'open'));
        $input = $request->input('days');
        $input = is_array($input) ? array_intersect_key($input, array_flip($bookable)) : null;
        [$days, $error] = BarberHours::validate($input);
        if ($error !== null) {
            $this->redirect('/admin/barberos?error=' . rawurlencode($barber->firstName() . ': ' . $error) . '#horario-' . rawurlencode($barber->id));
        }
        BarberHours::save($barber->key, $days);
        Audit::log('admin_barber_hours', $user->id, 'barbero ' . $barber->key);
        $this->redirect('/admin/barberos?ok=1#horario-' . rawurlencode($barber->id));
    }

    /**
     * POST /admin/barberos/nuevo (multipart) — campos: name, role, years, rating, tone y photo (archivo, opcional).
     * Valida con Barber::validate; si vino foto se procesa antes de crear la ficha, así un archivo inválido no
     * deja un barbero a medias. El nuevo barbero aparece enseguida en la app y atiende la jornada completa hasta
     * que se le cargue un horario.
     */
    public function createBarber(Request $request): void
    {
        $user = $this->requireStaffEditor();
        $this->requireCsrf($request);

        [$data, $error] = Barber::validate([
            'name' => $request->input('name'), 'role' => $request->input('role'), 'years' => $request->input('years'),
            'rating' => $request->input('rating'), 'tone' => $request->input('tone'),
        ]);
        if ($error !== null) {
            $this->redirect('/admin/barberos?error=' . rawurlencode($error) . '#nuevo-barbero');
        }
        $photo = null;
        $file = $request->file('photo');
        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$photo, $error] = BarberPhoto::store($file, slugify($data['name'], 'barbero'));
            if ($error !== null) {
                $this->redirect('/admin/barberos?error=' . rawurlencode($error) . '#nuevo-barbero');
            }
        }
        $slug = Barber::create($data);
        $barber = Barber::find($slug);
        if ($photo !== null && $barber !== null) {
            Barber::setPhoto($barber->key, $photo);
        }
        Audit::log('admin_barber_create', $user->id, 'barbero ' . ($barber?->key ?? 0) . ' · ' . $data['name']);
        $this->redirect('/admin/barberos?ok=nuevo#horario-' . rawurlencode($slug));
    }

    /**
     * POST /admin/barberos/foto (multipart) — campos: barber (slug), photo (archivo) o remove=1 para sacarla.
     * Reemplaza la imagen del barbero; la foto subida anterior se borra del disco (las del repositorio no).
     */
    public function saveBarberPhoto(Request $request): void
    {
        $user = $this->requireStaffEditor();
        $this->requireCsrf($request);

        $barber = Barber::find((string) $request->input('barber', ''));
        if ($barber === null) {
            $this->redirect('/admin/barberos?error=' . rawurlencode('Ese barbero no existe.'));
        }
        $back = '#horario-' . rawurlencode($barber->id);
        $photo = null;
        if ($request->input('remove') !== '1') {
            [$photo, $error] = BarberPhoto::store($request->file('photo'), $barber->id);
            if ($error !== null) {
                $this->redirect('/admin/barberos?error=' . rawurlencode($barber->firstName() . ': ' . $error) . $back);
            }
        }
        Barber::setPhoto($barber->key, $photo);
        BarberPhoto::delete($barber->photo);
        Audit::log($photo === null ? 'admin_barber_photo_remove' : 'admin_barber_photo', $user->id, 'barbero ' . $barber->key);
        $this->redirect('/admin/barberos?ok=foto' . $back);
    }

    /** GET /admin/finanzas?mes=YYYY-MM — ingresos vs egresos del mes y gráfico de 6 meses (los egresos se cargan en /admin/egresos). */
    public function finance(Request $request): void
    {
        $user = $this->requireSection('finanzas');
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
        $user = $this->requireSection('egresos');
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
        $user = $this->requireSection('egresos');
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
        $user = $this->requireSection('egresos');
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        if ($id > 0 && Expense::delete($id)) {
            Audit::log('admin_expense_delete', $user->id, 'egreso ' . $id);
        }
        $this->redirect('/admin/egresos?mes=' . $this->month($request->input('mes')) . '&ok=1');
    }

    /**
     * GET /admin/ingresos?mes=YYYY-MM — ingresos extra del mes (lo cobrado fuera de los turnos): alta (fecha,
     * monto, categoría y observación), listado con baja y totales. Se suman al resultado del resumen y finanzas.
     */
    public function incomes(Request $request): void
    {
        $user = $this->requireSection('ingresos');
        $month = $this->month($request->query('mes'));
        $this->view('pages/admin-ingresos', [
            'title'     => 'Ingreso extra · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'active'    => 'ingresos',
            'scripts'   => ['js/money-input.js'],
            'month'     => $month,
            'prev'      => date('Y-m', strtotime($month . '-01 -1 month')),
            'next'      => date('Y-m', strtotime($month . '-01 +1 month')),
            'isCurrent' => $month === date('Y-m'),
            'totals'    => Dashboard::finance($month . '-01', 1)[0],
            'incomes'   => Income::forMonth($month),
            'flash'     => (string) $request->query('ok', ''),
            'error'     => (string) $request->query('error', ''),
        ]);
    }

    /** POST /admin/ingresos — registra un ingreso extra. Valida con Income::validate, exige CSRF y rol, y audita. */
    public function saveIncome(Request $request): void
    {
        $user = $this->requireSection('ingresos');
        $this->requireCsrf($request);

        [$data, $error] = Income::validate([
            'received_on' => $request->input('received_on'), 'category' => $request->input('category'),
            'amount' => $request->input('amount'), 'notes' => $request->input('notes'),
        ]);
        if ($error !== null) {
            $this->redirect('/admin/ingresos?mes=' . $this->month(substr($request->string('received_on'), 0, 7)) . '&error=' . rawurlencode($error));
        }
        $id = Income::create($data, $user->id);
        Audit::log('admin_income_create', $user->id, 'ingreso extra ' . $id . ' · $' . $data['amount']);
        $this->redirect('/admin/ingresos?mes=' . substr($data['received_on'], 0, 7) . '&ok=1');
    }

    /** POST /admin/ingresos/borrar — elimina un ingreso extra cargado por error (queda en la auditoría). */
    public function deleteIncome(Request $request): void
    {
        $user = $this->requireSection('ingresos');
        $this->requireCsrf($request);

        $id = (int) $request->string('id');
        if ($id > 0 && Income::delete($id)) {
            Audit::log('admin_income_delete', $user->id, 'ingreso extra ' . $id);
        }
        $this->redirect('/admin/ingresos?mes=' . $this->month($request->input('mes')) . '&ok=1');
    }

    /**
     * POST /admin/turnos/estado — marca un turno reservado como atendido ('done') o ausente ('no_show')
     * y vuelve a la agenda del mismo día. Exige CSRF y rol; queda en la auditoría.
     * Si lo marca un barbero y el turno era de otro, pasa a sus cortes (ver Dashboard::mark).
     */
    public function mark(Request $request): void
    {
        $user = $this->requireSection('turnos');
        $this->requireCsrf($request);

        $day = $this->day($request->input('fecha'));
        $status = (string) $request->input('status', '');
        $id = (int) $request->input('id', 0);

        $ok = $id > 0 && Dashboard::mark($id, $status, $user->barberId);
        if ($ok) {
            Audit::log('admin_appointment_' . $status, $user->id, 'turno ' . $id);
        }
        $this->redirect('/admin/turnos?fecha=' . $day . '&ok=' . ($ok ? '1' : '0'));
    }

    /**
     * GET /admin/hora-muerta — "Hora muerta": si hay una vigente muestra el descuento con la cuenta regresiva y el botón
     * para cortarla; si no, el formulario para activarla (porcentaje y duración). Abajo, las últimas que hubo y cuántos
     * turnos se reservaron con cada una.
     */
    public function deadHour(Request $request): void
    {
        $user = $this->requireSection('hora-muerta');
        $this->view('pages/admin-hora-muerta', [
            'title'     => 'Hora muerta · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'current'   => DeadHour::current(),
            'recent'    => DeadHour::recent(),
            'flash'     => (string) $request->query('ok', ''),
            'error'     => (string) $request->query('error', ''),
        ]);
    }

    /**
     * POST /admin/hora-muerta — campos: percent, minutes. Activa la hora muerta desde ahora (si había otra vigente, la
     * reemplaza). Desde ese momento los clientes ven el descuento y los turnos de hoy que reserven salen con él.
     */
    public function startDeadHour(Request $request): void
    {
        $user = $this->requireSection('hora-muerta');
        $this->requireCsrf($request);

        [$data, $error] = DeadHour::validate(['percent' => $request->input('percent'), 'minutes' => $request->input('minutes')]);
        if ($error !== null) {
            $this->redirect('/admin/hora-muerta?error=' . rawurlencode($error));
        }
        $id = DeadHour::start($data['percent'], $data['minutes'], $user->id);
        Audit::log('admin_dead_hour_start', $user->id, 'hora muerta ' . $id . ' · ' . $data['percent'] . '% · ' . $data['minutes'] . ' min');
        $this->redirect('/admin/hora-muerta?ok=inicio');
    }

    /** POST /admin/hora-muerta/terminar — corta la hora muerta vigente antes de tiempo ("Terminar ahora"). */
    public function stopDeadHour(Request $request): void
    {
        $user = $this->requireSection('hora-muerta');
        $this->requireCsrf($request);

        if (DeadHour::stop()) {
            Audit::log('admin_dead_hour_stop', $user->id, 'cortada a mano');
        }
        $this->redirect('/admin/hora-muerta?ok=fin');
    }

    /**
     * GET /admin/fila — fila por orden de llegada de hoy: el QR para imprimir y pegar en el mostrador (lleva la URL
     * pública de la app + el código de config queue.code), el número que se está atendiendo y la lista del día.
     */
    public function queue(Request $request): void
    {
        $user = $this->requireSection('fila');
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
        $user = $this->requireSection('fila');
        $this->requireCsrf($request);
        $number = QueueTicket::callNext();
        Audit::log('admin_queue_call', $user->id, $number === null ? 'fila vacía' : '#' . $number);
        $this->redirect('/admin/fila?ok=' . ($number === null ? 'vacia' : 'llamado'));
    }

    /** POST /admin/fila/ausente — campo id: marca un número de hoy como "no vino". */
    public function queueNoShow(Request $request): void
    {
        $user = $this->requireSection('fila');
        $this->requireCsrf($request);
        $id = (int) $request->input('id', 0);
        if ($id > 0 && QueueTicket::markNoShow($id)) {
            Audit::log('admin_queue_no_show', $user->id, 'número ' . $id);
        }
        $this->redirect('/admin/fila');
    }

    /**
     * Exige poder modificar el catálogo (servicios y productos): solo el administrador. Un barbero con la sección
     * Servicios habilitada la ve en modo lectura y, si igual manda el formulario, recibe 403.
     */
    private function requireCatalogEditor(): User
    {
        $user = $this->requireSection('servicios');
        if (!$user->isAdmin) {
            Response::error(403, 'Solo lectura', 'Los servicios y productos los carga y edita el administrador.');
        }
        return $user;
    }

    /**
     * Exige poder dar de alta barberos o cambiar sus fotos: solo el administrador. Un barbero con la sección
     * Barberos habilitada la ve, pero si igual manda el formulario recibe 403.
     */
    private function requireStaffEditor(): User
    {
        $user = $this->requireSection('barberos');
        if (!$user->isAdmin) {
            Response::error(403, 'Solo lectura', 'Los barberos y sus fotos los carga el administrador.');
        }
        return $user;
    }

    /**
     * GET /admin/servicios — editor del catálogo: precio, nombre, descripción y duración de cada servicio,
     * alta de servicios nuevos y ocultar/mostrar. Para un barbero (canEdit = false) es solo una lista de lo que
     * existe, sin formularios. `ok` y `error` traen el resultado de la acción anterior. `tab` elige la pestaña
     * (servicios por defecto, o productos); las acciones sobre productos vuelven con ?tab=productos.
     * Con "Hora muerta" en curso muestra, como la app del cliente, el precio de lista tachado y el precio con el
     * descuento de cada servicio (los productos no tienen descuento); salePct sigue la misma regla que ShellController.
     */
    public function services(Request $request): void
    {
        $user = $this->requireSection('servicios');
        $deadHour = DeadHour::locksToday() ? DeadHour::current() : null;
        $this->view('pages/admin-services', [
            'deadHour'  => $deadHour,
            'salePct'   => $deadHour !== null ? (int) $deadHour['percent'] : 0,
            'title'     => 'Servicios · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'active'    => 'servicios',
            'canEdit'   => $user->isAdmin,
            'services'  => Service::allForAdmin(),
            'products'  => Product::allForAdmin(),
            'tab'       => $request->query('tab') === 'productos' ? 'productos' : 'servicios',
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
        $user = $this->requireCatalogEditor();
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
        $user = $this->requireCatalogEditor();
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        $active = $request->input('active') === '1';
        if ($id > 0) {
            Service::setActive($id, $active);
            Audit::log($active ? 'admin_service_show' : 'admin_service_hide', $user->id, 'servicio ' . $id);
        }
        $this->redirect('/admin/servicios?ok=1');
    }

    /** POST /admin/productos/guardar — edita un producto existente (id) o crea uno nuevo (sin id): nombre, descripción y precio. */
    public function saveProduct(Request $request): void
    {
        $user = $this->requireCatalogEditor();
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        [$data, $error] = Product::validate([
            'name' => $request->input('name'), 'description' => $request->input('description'), 'price' => $request->input('price'),
        ]);
        if ($error !== null) {
            $this->redirect('/admin/servicios?tab=productos&error=' . rawurlencode($error));
        }
        if ($id > 0) {
            if (!Product::update($id, $data)) {
                $this->redirect('/admin/servicios?tab=productos&error=' . rawurlencode('Ese producto no existe.'));
            }
            Audit::log('admin_product_update', $user->id, 'producto ' . $id . ' · $' . $data['price']);
        } else {
            $id = Product::create($data);
            Audit::log('admin_product_create', $user->id, 'producto ' . $id . ' · ' . $data['name']);
        }
        $this->redirect('/admin/servicios?tab=productos&ok=1#producto-' . $id);
    }

    /** POST /admin/productos/estado — oculta ('0') o muestra ('1') un producto en la app. */
    public function toggleProduct(Request $request): void
    {
        $user = $this->requireCatalogEditor();
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        $active = $request->input('active') === '1';
        if ($id > 0) {
            Product::setActive($id, $active);
            Audit::log($active ? 'admin_product_show' : 'admin_product_hide', $user->id, 'producto ' . $id);
        }
        $this->redirect('/admin/servicios?tab=productos&ok=1#producto-' . $id);
    }

    /**
     * GET /admin/usuarios — cuentas de la barbería (solo administrador): alta con DNI, nombre, teléfono, contraseña y rol;
     * por cuenta, edición de datos/rol (al barbero se le eligen las secciones del panel que ve), cambio de contraseña
     * y baja/reactivación. `ok` y `error` traen el resultado de la acción anterior.
     */
    public function users(Request $request): void
    {
        $user = $this->requireSection('usuarios');
        $this->view('pages/admin-usuarios', [
            'title'     => 'Usuarios · Barbería',
            'bodyClass' => 'admin-page',
            'user'      => $user,
            'scripts'   => ['js/admin-users.js'],
            'accounts'  => User::allForAdmin(),
            'deletable' => array_flip(User::deletableIds()),
            'stranded'  => (int) $request->query('turnos', 0),
            'barbers'   => Barber::team(),
            'flash'     => (string) $request->query('ok', ''),
            'error'     => (string) $request->query('error', ''),
        ]);
    }

    /**
     * Vuelve a /admin/usuarios con un error. Como los avisos de guardado, se ancla a #aviso (arriba de todo) para que
     * el mensaje quede a la vista después de enviar cualquier formulario de la página.
     */
    private function usersError(string $message): never
    {
        $this->redirect('/admin/usuarios?error=' . rawurlencode($message) . '#aviso');
    }

    /**
     * POST /admin/usuarios/guardar — edita una cuenta (id) o crea una nueva (sin id; trae dni y password).
     * Valida con User::validate y además: el admin no puede quitarse su propio rol y nunca se le quita el rol al último
     * administrador activo (nadie podría entrar al panel). Con rol barbero, User::saveFromAdmin crea su ficha del equipo
     * con el nombre cargado (no se elige una existente); si deja de ser barbero su ficha se oculta y, si tenía turnos
     * reservados a futuro, se avisa cuántos (no se cancelan solos). Los permisos se leen en cada petición, así que el
     * cambio vale enseguida para el usuario.
     */
    public function saveUser(Request $request): void
    {
        $user = $this->requireSection('usuarios');
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        $target = $id > 0 ? User::findById($id) : null;
        if ($id > 0 && $target === null) {
            $this->usersError('Esa cuenta no existe.');
        }
        [$data, $error] = User::validate([
            'dni' => $request->input('dni'), 'full_name' => $request->input('full_name'), 'phone' => $request->input('phone'),
            'role' => $request->input('role'), 'sections' => $request->input('sections'),
            'password' => $request->input('password'),
        ], $target === null);
        if ($error !== null) {
            $this->usersError($error);
        }
        if ($target?->isAdmin && !$data['is_admin']) {
            if ($target->id === $user->id) {
                $this->usersError('No podés quitarte tu propio rol de administrador.');
            }
            if ($target->active && User::activeAdminCount() <= 1) {
                $this->usersError('Tiene que quedar al menos un administrador activo.');
            }
        }

        // Si deja de ser barbero, su ficha se oculta: se cuentan antes sus turnos reservados para avisar.
        $stranded = $target?->barberId !== null && !$data['is_barber'] ? Barber::upcomingCount($target->barberId) : 0;

        try {
            $id = User::saveFromAdmin($target, $data, $target === null ? Password::hash($data['password']) : null);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $this->usersError('Ya existe una cuenta con ese DNI o ese teléfono.');
            }
            throw $e;
        }
        $role = $request->string('role') . ($data['panel_access'] ? ' · ' . $data['panel_access'] : '');
        if ($target === null) {
            Audit::log('admin_user_create', $user->id, 'usuario ' . $id . ' · ' . $data['dni'] . ' · ' . $role);
        } else {
            Audit::log('admin_user_update', $user->id, 'usuario ' . $id . ' · ' . $role);
        }
        $this->redirect('/admin/usuarios?ok=1' . ($stranded > 0 ? '&turnos=' . $stranded : '') . '#aviso');
    }

    /**
     * POST /admin/usuarios/clave — campos: id, password, confirm. El administrador le pone una contraseña nueva a una
     * cuenta (es como se resuelve "Olvidé mi contraseña": el cliente escribe por WhatsApp). Borra sus enlaces de
     * recuperación viejos (de cuando se recuperaba por email) y cierra
     * todas sus sesiones abiertas, salvo que sea la propia cuenta (no se echa a sí mismo del panel).
     */
    public function userPassword(Request $request): void
    {
        $user = $this->requireSection('usuarios');
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        $target = $id > 0 ? User::findById($id) : null;
        if ($target === null) {
            $this->usersError('Esa cuenta no existe.');
        }
        $password = $request->string('password');
        if ($error = Password::validate($password)) {
            $this->usersError($error);
        }
        if (!hash_equals($password, $request->string('confirm'))) {
            $this->usersError('Las contraseñas no coinciden.');
        }
        Database::transaction(function () use ($target, $password, $user) {
            User::setPassword($target->id, Password::hash($password));
            Database::execute('DELETE FROM password_resets WHERE user_id = ?', [$target->id]);
            if ($target->id !== $user->id) {
                DbSessionHandler::revokeUser($target->id);
            }
        });
        Audit::log('admin_user_password', $user->id, 'usuario ' . $id);
        $this->redirect('/admin/usuarios?ok=clave#aviso');
    }

    /**
     * POST /admin/usuarios/estado — campos: id, active ('0' suspender, '1' dar el alta). Suspender no borra la cuenta
     * (sus turnos y movimientos se conservan): le impide entrar y cierra sus sesiones; si es barbero, además su ficha
     * deja de aparecer en la app y de tomar turnos (se avisa cuántos reservados le quedan), y vuelve con el alta.
     * No se puede suspender la propia cuenta ni al último administrador activo, y no se reactiva un barbero cuya
     * ficha ya usa otra cuenta activa.
     */
    public function toggleUser(Request $request): void
    {
        $user = $this->requireSection('usuarios');
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        $active = $request->input('active') === '1';
        $target = $id > 0 ? User::findById($id) : null;
        if ($target === null) {
            $this->usersError('Esa cuenta no existe.');
        }
        if (!$active) {
            if ($target->id === $user->id) {
                $this->usersError('No podés darte de baja a vos mismo.');
            }
            if ($target->isAdmin && $target->active && User::activeAdminCount() <= 1) {
                $this->usersError('Tiene que quedar al menos un administrador activo.');
            }
        } elseif ($target->barberId !== null && User::barberTaken($target->barberId, $id)) {
            $this->usersError('Su ficha del equipo ya está vinculada a otra cuenta activa: cambiale el rol antes de reactivarla.');
        }
        $stranded = !$active && $target->barberId !== null ? Barber::upcomingCount($target->barberId) : 0;
        Database::transaction(function () use ($id, $active, $target) {
            User::setActive($id, $active);
            if ($target->barberId !== null) {
                Barber::setActive($target->barberId, $active);
            }
        });
        if (!$active) {
            DbSessionHandler::revokeUser($id);
        }
        Audit::log($active ? 'admin_user_enable' : 'admin_user_disable', $user->id, 'usuario ' . $id);
        $this->redirect('/admin/usuarios?ok=1' . ($stranded > 0 ? '&turnos=' . $stranded : '') . '#aviso');
    }

    /**
     * POST /admin/usuarios/borrar — campo id: elimina del todo una cuenta sin historial (p. ej. creada por error).
     * Con turnos, premios, fila o movimientos no se borra (User::delete devuelve false): esas se suspenden.
     * No se puede eliminar la propia cuenta ni al último administrador activo.
     */
    public function deleteUser(Request $request): void
    {
        $user = $this->requireSection('usuarios');
        $this->requireCsrf($request);

        $id = (int) $request->input('id', 0);
        $target = $id > 0 ? User::findById($id) : null;
        if ($target === null) {
            $this->usersError('Esa cuenta no existe.');
        }
        if ($target->id === $user->id) {
            $this->usersError('No podés eliminar tu propia cuenta.');
        }
        if ($target->isAdmin && $target->active && User::activeAdminCount() <= 1) {
            $this->usersError('Tiene que quedar al menos un administrador activo.');
        }
        if (!User::delete($id)) {
            $this->usersError('Esta cuenta tiene historial (turnos, fila o movimientos): suspendela en lugar de eliminarla.');
        }
        // Su ficha del equipo se borra si nunca atendió turnos; si atendió, queda oculta para no perder ese historial.
        if ($target->barberId !== null) {
            Barber::retire($target->barberId);
        }
        Audit::log('admin_user_delete', $user->id, 'usuario ' . $id . ' · ' . $target->dni);
        $this->redirect('/admin/usuarios?ok=borrado');
    }
}
