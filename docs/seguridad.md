# Seguridad

Estado de la seguridad de la app, qué se implementó y qué falta antes de producción.

> **Advertencia:** la autenticación es por DNI + contraseña (Argon2id), pero la semilla crea cuentas de demostración
> con contraseñas públicas. **La app no está lista para datos reales** hasta resolver la sección
> [Pendiente antes de producción](#pendiente-antes-de-producción).

## Controles implementados

| Riesgo | Control | Dónde |
|--------|---------|-------|
| XSS | Todo texto de datos se escapa con `e()`. Política CSP sin scripts inline (`script-src 'self'`). Los colores que van a `style=""` se validan con `css_color()`. | `helpers.php`, `Core\Security` |
| Clickjacking | `frame-ancestors 'none'` + `X-Frame-Options: DENY` | `Core\Security::sendHeaders()` |
| CSRF | Token por sesión (`csrf_field()`), verificado con `hash_equals`. Login y logout son **POST** y responden **419** sin token válido. | `Security`, `Controller::requireCsrf()` |
| Contraseñas y sesiones | Argon2id, mínimo 8 y máximo 72 caracteres, mensaje de login único (con hash falso para igualar tiempos), sesiones en la tabla `sessions` revocables por usuario. "Olvidé mi contraseña" (`/recuperar`, `Auth::recover`): DNI + el mismo celular del registro + contraseña nueva; solo cuentas activas con celular, mensaje único si no coinciden, 3 intentos por DNI y 5 por IP por ventana (`security.recover_max_attempts_*`), cierra todas las sesiones de la cuenta y queda auditado (`password_reset`, `password_reset_fail`). **Riesgo aceptado**: el celular no se verifica con SMS, así que quien conozca el DNI y el celular de alguien puede cambiarle la contraseña; para cerrarlo haría falta un código por SMS/WhatsApp. Sin celular cargado: WhatsApp de soporte (`support.whatsapp`) y el administrador pone una nueva en `/admin/usuarios`. | `Support\Password`, `Services\Auth`, `Core\DbSessionHandler` |
| Trazabilidad | Login, altas (y altas rechazadas), cierres de sesión y turnos en `audit_log` (sin contraseñas ni tokens). | `Services\Audit` |
| Cuentas spam en el registro | Celular argentino obligatorio, normalizado a 10 dígitos (`Support\Phone`: acepta +54 9 / 0 / 15 y rechaza otros países) con índice único en `users.phone`: dos cuentas no pueden tener el mismo número aunque lo escriban distinto. **Una cuenta por navegador**: el alta exige la cookie `navaja_dev` ya presente y se anota en `device_registrations` en la misma transacción (índice único sobre el navegador sin liberar, así ni dos pedidos en paralelo crean dos cuentas); desde ese momento `/registro` y "Crear cuenta" desaparecen para ese navegador, aunque se borre la cuenta. Además, como mucho `security.register_max_ip_day` (3) altas por IP cada 24 h, por si borran la cookie o usan incógnito. Los rechazos se auditan (`register_blocked_device` / `register_blocked_ip`) y `/su/registros` lista cada alta (datos, IP, navegador, altas desde la misma IP) con Permaban, bloqueo de IP y "Liberar navegador". | `Services\Auth::register`, `Models\Registration`, `SuperController` |
| Fuerza bruta de DNI | Máximo 5 fallos por DNI y 10 por IP cada 15 min → **429** con `Retry-After`. El intento se reserva de forma atómica *antes* de verificar la contraseña, así que mandar contraseñas en paralelo no esquiva el límite. IPv6 se cuenta por prefijo /64. Un login exitoso no gasta cupo de la IP, pero tampoco borra sus fallos previos. | `Services\Auth`, `Core\RateLimiter` |
| Entradas malformadas | Los campos de los formularios de cuenta se leen con `Request::string()`: un arreglo (`password[]=a&password[]=b`) se toma como vacío y cuenta como un solo intento; todo se corta en 1024 bytes. Contraseñas vacías, de más de 72 caracteres o con bytes nulos nunca se verifican (cuentan como fallo, con el mismo tiempo de respuesta). | `Core\Request`, `Services\Auth`, `Support\Password` |
| DNI del alta | `/registro` solo acepta DNI entre 16.500.000 y 70.000.000 (`Dni::MIN_REGISTRO`/`MAX_REGISTRO`, ambos incluidos). `login.js` avisa antes de enviar, pero la regla vale por `Auth::register` → `Dni::isRegistrable()`. El login sigue aceptando cualquier DNI de 7 u 8 números para no dejar afuera cuentas existentes. | `Support\Dni`, `Services\Auth`, `pages/register.php` |
| Fijación de sesión | `session_regenerate_id(true)` al entrar, modo estricto de sesiones y rotación del token CSRF | `Session`, `Auth::attempt()` |
| Robo de cookie de sesión | `HttpOnly`, `SameSite=Lax`, `Secure` bajo HTTPS, solo por cookie (no en URL), nombre propio `navaja_sid` | `Session::start()` |
| Cierre de sesión incompleto | `logout` vacía la sesión, la destruye y expira la cookie | `Session::destroy()` |
| Redirección abierta | `Controller::redirect()` solo acepta rutas internas (`/algo`, nunca `//host`) | `Controller` |
| Fuga de información | Errores solo al log (`display_errors=0` salvo `APP_DEBUG=1`), página 500 genérica, sin `X-Powered-By` | `bootstrap.php`, `public/index.php` |
| Sniffing de tipos MIME / referrer / permisos | `X-Content-Type-Options`, `Referrer-Policy: same-origin`, `Permissions-Policy` (sin micrófono ni ubicación; cámara solo para el propio sitio, la usa el lector del QR de la fila), COOP | `Security` |
| Downgrade a HTTP | `Strict-Transport-Security` (solo se envía bajo HTTPS) | `Security` |
| Acceso a archivos internos (Apache) | `.htaccess` en la raíz niega todo; `public/.htaccess` habilita solo `public/`; `storage/` bloqueado | `.htaccess` |
| Ejecución directa de `.php` en el servidor embebido | `public/router.php` nunca sirve `.php` estáticos | `public/router.php` |
| Métodos incorrectos | 405 con `Allow` en vez de 404 | `Core\Router` |
| Datos de usuario en caché del navegador | `Cache-Control: no-store` en páginas dinámicas; el service worker borra las páginas cacheadas al llegar al login | `Controller::view()`, `sw.js`, `login.js` |
| DoS por parámetros | El tamaño del ícono se limita a 48–1024 px | `IconGenerator` |
| Superusuario | Cuenta única `superuser` fuera de `users` (contraseña en la tabla `superuser`, fijada con `php bin/superuser.php`). Mismo límite de intentos y mismo mensaje de error que el login común. Su sesión vence tras 2 h sin actividad (`security.superuser_idle`) y se invalida al cambiar la contraseña (`session_version`). `/su` responde 404 a cualquier cuenta común. | `Services\Auth`, `Models\Superuser`, `SuperController` |
| Detección de ataques | `/su/seguridad` muestra en las últimas 24 h las IP con 3+ logins fallidos (con bloqueo de 1 h / 24 h en un click), los DNI atacados (incluido `superuser`), las cuentas usadas desde 3+ IP, los navegadores nuevos en cuentas que ya tenían otros y los accesos del superusuario. `/su/auditoria` filtra y exporta todo el registro (CSV con las celdas `= + - @` neutralizadas contra inyección de fórmulas). | `Models\AuditLog`, `SuperController` |
| Escalada de privilegios en el panel | Cada acción de `AdminController` exige su sección en el servidor (`requireSection`); las escrituras del catálogo y de los días/horarios exigen además ser administrador (`requireCatalogEditor`, `requireHoursEditor` → **403** a un barbero aunque tenga la sección). `panel_access` solo guarda claves de `User::SECTIONS` ("usuarios" nunca es concedible). Nadie puede quitarse a sí mismo el rol de admin ni dejar el local sin administradores activos. | `AdminController`, `Models\User` |
| Cuenta suspendida con sesión abierta | `users.active = 0` hace que `Auth::user()` devuelva `null` en la siguiente petición y que el login falle con el mismo mensaje que una contraseña mala; además se borran sus sesiones. | `Services\Auth`, `Models\User` |
| Abuso desde una IP o un navegador | El superusuario bloquea IP (`ip_bans`) o navegadores ("Permaban", `device_bans`): el front controller responde **403** antes de cualquier ruta. El navegador se identifica con la cookie `navaja_dev` (32 bytes aleatorios, HttpOnly, 10 años; en la base solo su sha256). No se puede bloquear la IP ni el navegador propios; `php bin/superuser.php --desbloquear-todo` es la salida de emergencia. Los archivos estáticos que sirve Apache no pasan por PHP, así que no quedan bloqueados (no tienen datos). | `public/index.php`, `Models\Ban`, `Services\Device` |

### Política CSP

```
default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; style-src-attr 'unsafe-inline';
font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; manifest-src 'self'; worker-src 'self';
object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'
```

- `style-src-attr 'unsafe-inline'` existe solo porque los avatares y la barra de fidelidad pasan variables CSS
  (`--tone`, `--pct`) por `style=""`. Los `<style>`/`<link>` siguen restringidos. Si se migran a clases o a
  `data-*` + JS, se puede quitar.
- Si se agrega un script inline o un recurso de otro dominio (analytics, mapas, etc.), hay que ampliar la política
  a propósito; no hacerlo "por las dudas".
- Las cabeceras las envía PHP, así que **no llegan a los archivos estáticos** (css, js, png). Con Apache,
  `public/.htaccess` agrega las más importantes; con otro servidor (nginx) hay que replicarlas.

## Reglas para escribir código nuevo

1. **Escapar siempre** con `e()` lo que venga de datos o del usuario. Valores numéricos del modelo (`int`) se imprimen directo.
2. **Todo formulario `POST` lleva `<?= csrf_field() ?>`** y su acción empieza con `$this->requireCsrf($request)`.
3. **Nada que cambie estado va por GET** (ni logout, ni cancelar turno, ni confirmar).
4. Las consultas a base de datos usan **sentencias preparadas** (ver `docs/plan-base-de-datos.md`); nunca concatenar entrada en SQL.
5. Validar en el servidor aunque el JS ya valide: el JS lo controla el usuario.
6. Un `fetch` desde JS que cambie estado envía el token de `<meta name="csrf-token">` (cabecera `X-CSRF-Token`)
   y el controlador lo verifica igual que el campo `_csrf`.
7. Toda ruta autenticada empieza con `$this->requireLogin()`; con varios roles, además se verifica que el recurso
   pertenezca al usuario (un cliente no puede cancelar el turno de otro).
8. No loguear DNI, teléfonos ni tokens completos (el detalle de auditoría lleva solo lo justo para investigar).

## Pendiente antes de producción

| Prioridad | Tema | Qué hacer |
|-----------|------|-----------|
| **Crítica** | Cuentas de demostración | `bin/seed.php` crea al administrador (Andres), al barbero (Nico) y a los clientes Mateo y Camila con la contraseña pública `Navaja2026!` (lista en `users.md`). Borrarlas o cambiarla (`DEMO_PASSWORD`) antes de producción. |
| Alta | HTTPS | Servir solo por HTTPS; así se activan `Secure` en la cookie y HSTS. Redirigir HTTP → HTTPS en el servidor. |
| Alta | DocumentRoot | Apuntar el servidor a `public/` (no a la raíz del proyecto). El `.htaccess` es una segunda barrera, no la primera. |
| Media | Enumeración en el alta | `/registro` avisa si el DNI o el teléfono ya existen (inevitable con DNI como usuario); lo frenan el límite por IP y el de una cuenta por navegador. El teléfono no se verifica (no hay SMS): para eso haría falta un código por SMS/WhatsApp. |
| Media | Una cuenta por navegador | Se basa en una cookie: borrar los datos del sitio o usar otro navegador/incógnito da un navegador "nuevo". Lo acotan el tope de altas por IP y la revisión en `/su/registros`; las compañías de celular comparten IP entre muchos clientes, por eso el tope no es 1. |
| Media | Fuentes de Google | Terceros en `style-src`/`font-src` y filtración de IP. Autoalojar las fuentes en `public/assets/fonts/` y cerrar la CSP. |
| Media | Proxy / balanceador | `Request::ip()` usa `REMOTE_ADDR`. Detrás de un proxy propio hay que configurar la IP confiable o todos los usuarios compartirán el límite de intentos. |
| Baja | Limitador | Ya usa la tabla `login_attempts`; si hace falta más escala, pasar a Redis. |
| Baja | Expiración por inactividad | La sesión dura 30 días fijos (decisión de producto por la PWA instalada). Evaluar un tope por inactividad o "recordarme". |
| Media | Retención de auditoría | `/su/seguridad` permite borrar lo de más de 90/180/365 días (queda registrado). No se hace solo: definir una política (p. ej. 180 días) y aplicarla periódicamente. |
| Media | Alcance del Permaban | Borrar los datos del sitio o usar modo incógnito genera una cookie `navaja_dev` nueva. Por eso el Permaban total también da de baja la cuenta; combinarlo con el bloqueo de IP si hace falta. Bloquear una IP compartida (wifi del local, CGNAT del proveedor) deja afuera a todos los que la usan. |

## Verificación manual rápida

Con el servidor corriendo (`php -S 127.0.0.1:8000 -t public public/router.php`):

```bash
curl -si http://127.0.0.1:8000/login | grep -iE "content-security|x-frame|set-cookie"   # cabeceras y cookie
curl -s -o /dev/null -w "%{http_code}\n" -d "dni=40123456" http://127.0.0.1:8000/login # 419: falta el token CSRF
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/logout                  # 405: logout ya no es GET
```

Para ver el bloqueo por intentos: repetir 5 veces un `POST /login` con un DNI inexistente y un token válido → cada fallo avisa cuántos intentos quedan y el quinto ya responde **429** con "Podés volver a intentar en N minutos" (y `Retry-After` en segundos). El conteo es el mismo exista o no el DNI, así que no revela cuentas.
Los contadores viven en la tabla `login_attempts` (con `DB_DRIVER=files`, en `storage/ratelimit/`); para desbloquear en desarrollo se pueden borrar sin riesgo.

Usuarios (`/admin/usuarios`): exclusivo del administrador (nunca se le habilita a un barbero). Alta, edición de rol, cambio de contraseña y baja son POST con CSRF y quedan en la auditoría (`admin_user_create/update/password/enable/disable/delete`); la contraseña nunca se registra. El cambio de contraseña borra los enlaces de recuperación y cierra las sesiones de esa cuenta. La baja es lógica (`users.active = 0`): el login falla con el mismo mensaje que una contraseña mala, se borran sus sesiones y `Auth::user()` ignora cualquier sesión que quede. La baja definitiva (`/admin/usuarios/borrar`) solo borra cuentas sin turnos, premios, fila ni movimientos. No se puede suspender, eliminar ni quitar el rol a uno mismo ni al último administrador activo.

Permisos del panel: cada acción de `AdminController` exige su sección (`requireSection`, ver `User::canSee`); el admin ve todas y el barbero solo las de `users.panel_access`, comprobadas en cada petición (también en los POST), no solo ocultando pestañas.

Días del local y horarios (`/admin/barberos`): un barbero con la sección Barberos los ve como texto (sin formularios); si igual manda `POST /admin/barberos/dias` o `/horario` recibe 403 (`requireHoursEditor`). Los cambios quedan en la auditoría (`admin_shop_days`, `admin_barber_hours`).

Superusuario: con una sesión de cuenta común, `GET /su` responde **404** (no 403: no revela que existe); sin ninguna sesión, redirige a `/login` como cualquier página. Para probar el bloqueo, desde `/su` bloquear una IP de prueba por 1 hora y pedir cualquier página desde esa IP → 403; `/su/seguridad` muestra el contador de rechazos. Si el superusuario se queda afuera: `php bin/superuser.php --desbloquear-todo`.

Editor de servicios (`/admin/servicios`): solo administradores (un barbero con la sección Servicios la ve en modo lectura y sus POST reciben 403, ver `requireCatalogEditor`), POST con CSRF, validación en `Service::validate` y auditoría (`admin_service_update/create/hide/show`). Los turnos ya reservados conservan el precio con el que se tomaron.

Minijuegos (`/juegos/*`, `GameController`): el navegador no es confiable, así que el servidor no acepta ni el tiempo ni el puntaje "porque sí". Cada partida se abre en la base con la hora del servidor y un token aleatorio de un solo uso (se guarda solo su sha256); cerrarla es una transacción con la fila bloqueada, atada al usuario y al juego, así un token ajeno, inventado o repetido no sirve. El tiempo jugado lo calcula el servidor (tope `max_session`). El puntaje tiene que ser un entero de hasta 9 cifras y creíble para la duración real (`config/games.php`: `max_score`, `rate`/`burst`, `step`); si no, la partida queda `rejected`, no entra al ranking y se audita (`game_score_rejected`). El premio se pide sobre una partida cerrada y válida, con el puntaje que guardó el servidor, una sola vez por partida y con la cuenta bloqueada para que dos pedidos simultáneos no saquen dos cupones. `game_sessions` y `game_highscores` llevan una firma HMAC (`Services\Integrity`, clave fuera de la base: `APP_KEY` o `storage/integrity.key`): una fila editada a mano deja de contar. El ranking expone solo nombre de pila + inicial y puntaje, y el JS lo inserta con `textContent`. Límite: 20 partidas por minuto por usuario. Es una barrera contra pedidos armados a mano y edición de la base, no contra alguien que reescriba el juego en su navegador: por eso los topes por juego y que los premios sean descuentos chicos.

API de turnos: sin sesión responde 401 JSON; las POST exigen CSRF (campo `_csrf` o cabecera `X-CSRF-Token`) y las escrituras filtran siempre por el usuario de la sesión. El servidor revalida servicio, barbero, fecha y hora, y recalcula precio y duración; un cliente no puede tener más de `booking.max_active` turnos futuros.

Credenciales: van en `.env` (git-ignorado, bloqueado por `.htaccess`). La app debe usar un usuario de base con solo SELECT/INSERT/UPDATE/DELETE y las migraciones otro aparte. **En el entorno de desarrollo actual el `.env` usa `root`** porque las tablas de privilegios del MariaDB local están dañadas: no replicarlo en producción.
