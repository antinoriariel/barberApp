# Seguridad

Estado de la seguridad de la app, qué se implementó y qué falta antes de producción.

> **Advertencia:** la autenticación sigue siendo por DNI sin contraseña (demo). Todo lo de este documento reduce el
> riesgo, pero **la app no está lista para datos reales** hasta resolver la sección [Pendiente antes de producción](#pendiente-antes-de-producción).

## Controles implementados

| Riesgo | Control | Dónde |
|--------|---------|-------|
| XSS | Todo texto de datos se escapa con `e()`. Política CSP sin scripts inline (`script-src 'self'`). Los colores que van a `style=""` se validan con `css_color()`. | `helpers.php`, `Core\Security` |
| Clickjacking | `frame-ancestors 'none'` + `X-Frame-Options: DENY` | `Core\Security::sendHeaders()` |
| CSRF | Token por sesión (`csrf_field()`), verificado con `hash_equals`. Login y logout son **POST** y responden **419** sin token válido. | `Security`, `Controller::requireCsrf()` |
| Contraseñas y sesiones | Argon2id, mínimo 8 y máximo 72 caracteres, mensaje de login único (con hash falso para igualar tiempos), sesiones en la tabla `sessions` revocables por usuario, recuperación con token de un solo uso guardado hasheado (vence en 1 h y cierra las sesiones abiertas). | `Support\Password`, `Services\Auth`, `Core\DbSessionHandler` |
| Trazabilidad | Login, altas, recuperaciones, cierres de sesión y turnos en `audit_log` (sin contraseñas ni tokens). | `Services\Audit` |
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
8. No loguear DNI, emails ni tokens completos.

## Pendiente antes de producción

| Prioridad | Tema | Qué hacer |
|-----------|------|-----------|
| **Crítica** | Cuentas de demostración | `bin/seed.php` crea al administrador (Andres), al barbero (Nico) y a los clientes Mateo y Camila con la contraseña pública `Navaja2026!` (lista en `users.md`). Borrarlas o cambiarla (`DEMO_PASSWORD`) antes de producción. |
| Alta | Email de recuperación | `MAIL_DRIVER=log` solo escribe en `storage/mail.log`. En producción usar `mail` (o un SMTP) y fijar `APP_URL`: el enlace se arma con esa URL, no con el encabezado Host. |
| Alta | HTTPS | Servir solo por HTTPS; así se activan `Secure` en la cookie y HSTS. Redirigir HTTP → HTTPS en el servidor. |
| Alta | DocumentRoot | Apuntar el servidor a `public/` (no a la raíz del proyecto). El `.htaccess` es una segunda barrera, no la primera. |
| Media | Enumeración en el alta | `/registro` avisa si el DNI o email ya existen (inevitable con DNI como usuario); lo frena el límite por IP. Considerar verificar el email antes de activar la cuenta. |
| Media | Fuentes de Google | Terceros en `style-src`/`font-src` y filtración de IP. Autoalojar las fuentes en `public/assets/fonts/` y cerrar la CSP. |
| Media | Proxy / balanceador | `Request::ip()` usa `REMOTE_ADDR`. Detrás de un proxy propio hay que configurar la IP confiable o todos los usuarios compartirán el límite de intentos. |
| Baja | Limitador | Ya usa la tabla `login_attempts`; si hace falta más escala, pasar a Redis. |
| Baja | Expiración por inactividad | La sesión dura 30 días fijos (decisión de producto por la PWA instalada). Evaluar un tope por inactividad o "recordarme". |
| Baja | Retención de auditoría | `audit_log` crece sin límite: definir cuánto se conserva y purgar. |

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

Editor de servicios (`/admin/servicios`): solo administradores (un barbero con la sección Servicios la ve en modo lectura y sus POST reciben 403, ver `requireCatalogEditor`), POST con CSRF, validación en `Service::validate` y auditoría (`admin_service_update/create/hide/show`). Los turnos ya reservados conservan el precio con el que se tomaron.

API de turnos: sin sesión responde 401 JSON; las POST exigen CSRF (campo `_csrf` o cabecera `X-CSRF-Token`) y las escrituras filtran siempre por el usuario de la sesión. El servidor revalida servicio, barbero, fecha y hora, y recalcula precio y duración; un cliente no puede tener más de `booking.max_active` turnos futuros.

Credenciales: van en `.env` (git-ignorado, bloqueado por `.htaccess`). La app debe usar un usuario de base con solo SELECT/INSERT/UPDATE/DELETE y las migraciones otro aparte. **En el entorno de desarrollo actual el `.env` usa `root`** porque las tablas de privilegios del MariaDB local están dañadas: no replicarlo en producción.
