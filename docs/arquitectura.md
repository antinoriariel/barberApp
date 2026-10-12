# Arquitectura

Aplicación MVC en PHP puro. Una petición recorre siempre el mismo camino:

```
navegador ─► public/index.php ─► Router ─► Controller ─► Model(s)
                (front controller)  (config/routes.php)      │
                                                             ▼
navegador ◄── HTML ◄── Layout ◄── Page ◄── Screens/Components ◄─ datos
```

1. `public/index.php` carga `app/bootstrap.php` (autoload, manejo de errores, helpers, sesión), envía las cabeceras de
   seguridad (`Security::sendHeaders()`), responde **403** si la IP o el navegador (cookie `navaja_dev`) están
   bloqueados (`Models\Ban::blocks`, solo con base de datos) y construye el `Router` con las rutas de `config/routes.php`.
2. El `Router` busca `(método, ruta)` y ejecuta `[Controlador::class, 'acción']`; ruta con otro método → 405, ruta
   desconocida → 404 (ambos vía `Response::error()`).
3. El **controlador** valida la entrada (en `POST`: `requireCsrf()`), pide datos a los **modelos** y llama a `view()`.
4. La **vista** se renderiza dentro de un layout y vuelve como HTML.
5. Si algo lanza una excepción, `index.php` la registra en el log y responde una página 500 genérica.

## Capas

### Modelo — `app/Models/`

Representan los datos del dominio y no conocen HTTP ni HTML.

| Modelo        | Responsabilidad |
|---------------|-----------------|
| `User`        | Cuenta (cliente, barbero o admin). `find($dni)`, `findById()`, `isBarber()`, `role()`; permisos del panel: `canSee()`, `panelSections()`, `hasPanel()`; gestión: `validate()`, `saveFromAdmin()`, `setActive()`, `delete()` (sin historial), `purge()` (con historial, solo superusuario) |
| `LoyaltyCard` | Progreso al corte gratis por ciclos: `stamps()`, `visitsLeft()`, `percent()`, `isComplete()`, `status()` |
| `Service`     | Catálogo: `all()`, `popular($n)`; edición del admin: `allForAdmin()`, `validate()`, `update()`, `create()`, `setActive()` |
| `Product`     | Productos del mostrador: `all()`, `allForAdmin()`, `validate()`, `create()`, `update()`, `setActive()` (tabla `products`) |
| `Barber`      | Equipo: `all()`, `find()`, `firstName()`, `isNew()`; ficha automática de las cuentas de barbero: `provision()`, `retire()` |
| `BarberHours` | Horario semanal de cada barbero: `week()`, `window($barbero, $fecha)`, `validate()`, `save()` (tabla `barber_hours`) |
| `Appointment` | `next($user)`, `history($user)` y las consultas/escrituras de turnos (las reglas viven en `Services\Booking`) |
| `Schedule`    | `openDays($n)` (salta cierres y feriados), `isOpen($fecha)`, `slotTimes()`, `slots($fecha, $barbero, $minutos)` (disponibilidad real, respeta el horario del barbero), `weekdayStatus()`, `saveOpenWeekdays()` |
| `Shop`        | Datos del local |
| `QueueTicket` | Fila por orden de llegada: `take()`, `currentFor()`, `leave()`, `callNext()` |
| `GameReward`  | Premios de los minijuegos de Inicio |
| `Dashboard`   | Números del panel: `day()`, `week()` (cortes por barbero), `finance()` (turnos + extra − egresos por mes), `delta()` |
| `Expense` / `Income` | Egresos e ingresos extra: `validate()`, `create()`, `delete()`, `forMonth()`, `totalForDay()` |
| `Superuser`   | Cuenta `superuser`: `matches()`, `passwordHash()`, `setPassword()`, `sessionVersion()` |
| `Ban`         | Bloqueos de IP (con vencimiento) y de navegador: `blocks()`, `banIp()`, `banDevice()`, `unban*()`, `normalizeIp()` |
| `AuditLog`    | Lectura de `audit_log` para el superusuario: `filters()`, `search()`, `actor()`, alertas (`suspiciousIps()`, `targetedAccounts()`…) |
| `UserActivity`| Navegadores (`devicesByUser()`) y últimas acciones (`recentActions()`) de cada cuenta; `browser()` resume el user agent |

Los modelos son la única frontera con el origen de datos. Con `database.driver = db` consultan MySQL/MariaDB a través de
`Core\Database` (PDO, sentencias preparadas, `transaction()`); con `files` leen `data/<nombre>.php` vía `Core\Data::get()`.
Las firmas públicas son las mismas en ambos modos, así que controladores y vistas no saben cuál está activo.
Las reglas de reservar, cancelar y reprogramar están en `Services\Booking` (valida todo en el servidor, usa transacciones y
la restricción única `uq_barber_slot` contra dobles reservas) y se exponen por `AppointmentController` como API JSON.

### Vista — `app/Views/`

PHP plano, sin motor de plantillas. Se organiza de mayor a menor:

| Carpeta       | Qué contiene | Ejemplo |
|---------------|--------------|---------|
| `layouts/`    | Documento HTML completo (`<head>`, CSS, scripts) | `base.php` |
| `pages/`      | Una página por ruta | `shell.php`, `login.php` |
| `screens/`    | Pantallas de la app (cada una es una `<section class="view">`) | `inicio.php`, `reservar.php` |
| `components/` | Piezas reutilizables, con las variables que reciben documentadas arriba | `ticket.php`, `loyalty.php`, `tabbar.php` |

Reglas:

- Los componentes reciben **todo** por parámetro (`partial('components/ticket', ['appointment' => $next])`); no
  consultan modelos ni leen la sesión.
- `partial()`, `e()` (escapar HTML), `money()`, `icon()`, `sk()`, `csrf_field()` y `css_color()` están en `app/Support/helpers.php`.
- Siempre escapar con `e()` todo texto que venga de datos; los colores que van a `style=""` pasan por `css_color()`.
- Todo `<form method="post">` incluye `<?= csrf_field() ?>`.
- Páginas de error: `pages/error.php` (la usa `Response::error()`).

### Controlador — `app/Controllers/`

| Controlador       | Acciones |
|-------------------|----------|
| `ShellController` | `index`: reúne los datos de todos los modelos y renderiza `pages/shell` (el personal va a `/admin`) |
| `AuthController`  | Login (también del superusuario), alta, recuperación y cierre de sesión |
| `AppointmentController` | API JSON de turnos: disponibilidad, reservar, cancelar, reprogramar |
| `QueueController` | Fila: página del QR (`/fila`), tomar y dejar número, estado en JSON |
| `GameController`  | Minijuegos de Inicio (iniciar partida y reclamar premio) |
| `AdminController` | Panel: cada acción empieza con `requireSection('<clave>')`; las de escritura del catálogo y de los horarios con `requireCatalogEditor()` / `requireHoursEditor()` (solo admin) |
| `SuperController` | Panel del superusuario (`requireSuper()`; 404 para el resto): cuentas, bloqueos, seguridad y auditoría |
| `PwaController`   | `manifest`, `icon` |

Heredan de `Core\Controller`, que ofrece `view()` (con `Cache-Control: no-store`), `redirect()` (solo rutas internas),
`requireLogin()` y `requireCsrf()`. Roles y permisos en detalle: [`panel-y-roles.md`](panel-y-roles.md).

### Núcleo — `app/Core/`

| Clase        | Función |
|--------------|---------|
| `Router`     | Tabla (método, ruta) → acción; 404 y 405 |
| `Request`    | Método, ruta, query, campos POST e IP del cliente |
| `Response`   | Páginas de error (`error()`) |
| `View`       | `render()` de plantillas y `page()` (plantilla + layout) |
| `Controller` | Base de los controladores |
| `Session`    | Envoltorio de `$_SESSION` (30 días, cookie endurecida) |
| `Security`   | Cabeceras de seguridad (CSP…) y token CSRF |
| `RateLimiter`| Contador de intentos por clave: tabla `login_attempts` (driver db) o `storage/ratelimit/` (files) |
| `Config`     | Lectura de `config/app.php` con notación de puntos |
| `Data`       | Carga y cachea `data/*.php` |

### Servicios y soporte

- `Services\Auth`: login por DNI + contraseña con límite de intentos. `attempt($dni, $password, $ip)` devuelve `null`
  si entró o el mensaje de error; si el DNI es `superuser` verifica contra `Models\Superuser` y marca la sesión
  (`isSuperuser()`). `user()` ignora cuentas suspendidas y registra el navegador (`Device::touch`). También `register()`,
  `requestReset()` y `resetPassword()`.
- `Services\Booking` (+ `BookingException` con el código HTTP): reglas de reservar, cancelar y reprogramar.
- `Services\Audit`: `log($evento, $userId, $detalle)` en `audit_log` con IP y user agent.
- `Services\Device`: cookie `navaja_dev` por navegador (`hash()` = su sha256) y registro en `user_devices`.
- `Services\BarberPhoto`: recodifica con GD las fotos subidas de los barberos (WebP 400×400 en `public/img/barberos/subidas/`).
- `Services\Integrity`: firma HMAC de las filas de estadísticas de juegos (`storage/integrity.key` o `APP_KEY`).
- `Services\Qr`: códigos QR en SVG sin dependencias (versiones 1–6, nivel L).
- `Services\IconGenerator`: dibuja el ícono PNG de la PWA con GD.
- `Support\Dni`: normalizar, validar y formatear DNI. `Support\Password`: hash Argon2id, verificación y reglas.

## Front-end

### Pantallas y navegación

La app es **una sola página** que contiene todas las pantallas; `navigation.js` muestra una según el hash
(`#inicio`, `#servicios`, `#reservar`, `#barberos`, `#perfil`). Cada pantalla declara:

- `id="view-<nombre>"`
- `data-title`: título de la pestaña
- `data-skel`: qué skeleton mostrar mientras "carga" (`components/skeletons.php`)

### CSS (`public/assets/css/`)

Una hoja por módulo; el orden de carga está en `config/app.php` → `assets.styles`.

```
base.css  layout.css  components/*.css  pages/login.css  pages/admin.css  pages/su.css  a11y.css
```

La interfaz es **mobile first** y tiene un solo layout: en una PC se muestra como una columna del ancho de un celular, centrada. El layout de escritorio anterior (barra lateral y grillas) quedó archivado en `docs/archivo/desktop.css` y ya no se carga.

### JS (`public/assets/js/`)

Módulos ES, cada uno con una responsabilidad. `main.js` solo los inicializa.

| Módulo          | Responsabilidad |
|-----------------|-----------------|
| `navigation.js` | Hash, skeletons y pestaña activa |
| `toast.js`      | Avisos (`showToast`, atributo `data-toast`) |
| `booking.js`    | Resumen en vivo, confirmar y atajos `data-book-service` / `data-book-barber` |
| `pwa.js`        | Registro del service worker |
| `login.js`      | Formato del DNI, botones de DNI de prueba y purga de páginas cacheadas del service worker |
| `api.js`        | Llamadas a la API de turnos: las POST llevan `X-CSRF-Token`, un 401 manda al login y nunca lanza: devuelve `{ ok, status, body }` |
| `catalog.js`    | Pestañas Servicios / Productos |
| `queue.js`      | Consulta periódica de `/api/fila` para el número de la fila en Inicio |
| `scanner.js`    | Cámara + `BarcodeDetector` para leer el QR del mostrador |
| `arcade.js`, `games/*` | Minijuegos de Inicio (motor común + flappy, pacman, runner) |
| `theme.js`, `theme-init.js` | Tema claro/oscuro (el `init` se carga antes del CSS para no parpadear) |
| `money-input.js`| Formato de montos con punto de miles en los formularios |
| `admin.js`      | Panel: selector de tema |
| `admin-users.js`| `/admin/usuarios` y `/su`: rol ↔ secciones, buscador, validación en vivo y diálogo de confirmación propio |

## Cómo extender

**Agregar un servicio o un barbero**: editar `data/services.php` o `data/barbers.php`. No hay que tocar código. Con
base de datos, los servicios y productos se cargan desde `/admin/servicios`, y la ficha de un barbero se crea sola al
darle a una cuenta el rol barbero en `/admin/usuarios`.

**Agregar una sección al panel**: clave en `User::SECTIONS` (si el admin puede concedérsela a un barbero), su URL en
`AdminController::SECTION_URLS`, la pestaña en `components/admin-header.php`, la ruta y una acción que empiece con
`requireSection('<clave>')`.

**Agregar una pantalla** (p. ej. "Promos"):
1. Crear `app/Views/screens/promos.php` con `<section class="view" id="view-promos" data-title="…" data-skel="list" hidden>`.
2. Incluirla en `pages/shell.php` con `partial('screens/promos', …)`.
3. Agregar la pestaña en `components/tabbar.php` con `data-tab="promos"`.
4. Si necesita datos nuevos, pedirlos en `ShellController::index`.

**Agregar una página con su propia ruta**: crear el método en un controlador, la vista en `pages/` y registrar
la ruta en `config/routes.php`.

**Agregar un componente**: crear `app/Views/components/<nombre>.php`, documentar arriba las variables que recibe, y
si necesita estilos, crear `assets/css/components/<nombre>.css` y sumarlo a `config/app.php`
y a `SHELL` en `public/sw.js`.

**Agregar una acción que cambie estado** (cancelar, guardar…): ruta `POST` en `config/routes.php`, formulario con
`csrf_field()` y `$this->requireCsrf($request)` al inicio de la acción. Nunca por GET. Ver `docs/seguridad.md`.

**Cambiar el esquema**: agregar `database/migrations/NNN_nombre.sql` y correr `php bin/migrate.php`. Plan completo
(esquema, fases, riesgos) en `docs/plan-base-de-datos.md`. Roles y cuentas de prueba: `users.md`.

**Cambios de CSS/JS en producción**: subir `CACHE` en `public/sw.js`.
