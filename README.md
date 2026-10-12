# Barbería — PWA de barbería

App de turnos para una barbería, hecha en PHP puro (sin framework) con arquitectura MVC.
Los datos viven en MySQL/MariaDB (PDO); con `DB_DRIVER=files` vuelve a leer los datos de ejemplo de `data/` (sin guardar nada).
Funciona como PWA: se puede instalar y abre sin conexión.

## Cómo correrla

Requiere PHP 8.2+ con la extensión **GD** (viene con XAMPP).

```bash
php -S 0.0.0.0:8000 -t public public/router.php
```

### Base de datos

1. Crear la base y los usuarios (como administrador de MySQL/MariaDB):
   ```sql
   CREATE DATABASE navaja CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'navaja_app'@'localhost' IDENTIFIED BY '...';        -- la app: solo lectura/escritura de datos
   GRANT SELECT, INSERT, UPDATE, DELETE ON navaja.* TO 'navaja_app'@'localhost';
   CREATE USER 'navaja_migrator'@'localhost' IDENTIFIED BY '...';   -- migraciones: puede crear tablas
   GRANT ALL PRIVILEGES ON navaja.* TO 'navaja_migrator'@'localhost';
   ```
2. Crear `.env` en la raíz (está en `.gitignore` y bloqueado por `.htaccess`) con `DB_DRIVER=db`, `DB_HOST`, `DB_NAME`,
   `DB_USER`, `DB_PASSWORD`, `DB_MIGRATE_USER` y `DB_MIGRATE_PASSWORD` (ver `config/database.php`).
3. `php bin/migrate.php` aplica los `database/migrations/*.sql` pendientes y `php bin/seed.php` carga los datos de `data/`.
   Ambos se pueden repetir sin efectos secundarios.

Para volver a la maqueta sin base de datos: `DB_DRIVER=files`.

Después abrí http://localhost:8000. Si no hay sesión, redirige a `/login`.

> La raíz web es `public/`. El servidor embebido necesita `public/router.php` para enviar las rutas
> dinámicas (`/login`, `/manifest.webmanifest`, `/icon.png`…) al front controller.

**Modo depuración**: por defecto los errores solo van al log y el usuario ve una página 500 genérica. Para verlos en
pantalla en desarrollo, arrancá con la variable `APP_DEBUG=1` (PowerShell: `$env:APP_DEBUG=1; php -S …`).

**Apache/XAMPP**: apuntá el `DocumentRoot` a `public/`. El `.htaccess` de la raíz bloquea el resto como segunda barrera.
En producción, ver [`docs/seguridad.md`](docs/seguridad.md).

## Cuentas y sesión

Se entra con **DNI + contraseña** (el DNI se acepta con o sin puntos). Con base de datos cualquiera puede crear una
cuenta en `/registro` con nombre, DNI, **celular argentino** (único: no puede haber dos cuentas con el mismo número) y
contraseña; se permite **una sola cuenta por navegador** (y hasta 3 por IP cada 24 h), y el superusuario las audita en
`/su/registros`. **"Olvidé mi contraseña"** abre WhatsApp al número de soporte (`support.whatsapp` en `config/app.php`,
o `SUPPORT_WHATSAPP` en `.env`) con el mensaje ya escrito; la contraseña nueva la pone el administrador en
`/admin/usuarios`. Las sesiones viven en la tabla `sessions` y desde el perfil se puede cerrar la sesión en todos los
dispositivos.

Cuentas de demostración que crea `bin/seed.php` (contraseña `Navaja2026!`, configurable con `DEMO_PASSWORD`; **borrarlas o
cambiarlas antes de producción**):

| Rol           | DNI        | Usuario       | Notas |
|---------------|------------|---------------|-------|
| Administrador | 33.202.388 | Andres Sena | Panel `/admin` completo, incluida la gestión de cuentas en `/admin/usuarios` |
| Barbero       | 32.333.444 | Nico Ledesma  | Ficha del barbero `nico`; entra a "Mi semana" y a las secciones que el admin le habilite |
| Cliente       | 40.123.456 | Mateo         | 7 visitas |
| Cliente       | 35.987.654 | Camila        | 10 visitas (corte gratis) |

La lista con roles y cómo agregar más está en [`users.md`](users.md); permisos de cada rol en
[`docs/panel-y-roles.md`](docs/panel-y-roles.md).

**Superusuario**: cuenta de soporte fuera de la tabla `users`. Se configura con `php bin/superuser.php` (muestra una
contraseña aleatoria una sola vez) y se entra escribiendo `superuser` en el campo DNI. Su panel `/su` lista todas las
cuentas con sus IP y navegadores, permite bloquear IP o navegadores ("Permaban") y revisar la auditoría.

Todos los fallos de login muestran el mismo mensaje. Tras 5 intentos fallidos con un mismo DNI (o 10 desde una misma IP)
el login se bloquea 15 minutos (HTTP 429). Login, altas, recuperaciones, cierres de sesión y movimientos de turnos quedan en `audit_log`.
Con `DB_DRIVER=files` solo existe el login (con la misma contraseña de demostración).

## Pruebas y respaldos

```bash
composer install          # solo para desarrollo: instala PHPUnit (la app no usa Composer en ejecución)
vendor/bin/phpunit        # 65 pruebas: unitarias + integración sobre la base aparte navaja_test
php bin/backup.php        # respaldo comprimido en storage/backups/ (conserva los últimos 14; BACKUP_KEEP lo cambia)
php bin/restore-check.php # restaura el último respaldo en una base temporal, compara tablas y la borra
```

PHPUnit crea y siembra `navaja_test` solo (necesita que el usuario de migraciones pueda crear bases) y nunca toca la base
real. Incluye una carrera de doble reserva con dos procesos PHP simultáneos. Si falla al sembrar con un email
duplicado, la base `navaja_test` quedó con datos de una versión vieja: borrala (`DROP DATABASE navaja_test`) y PHPUnit
la vuelve a crear. Programá `bin/backup.php` a diario (cron en
Linux, Programador de tareas en Windows) y corré `bin/restore-check.php` después de programarlo y cada tanto.

## Qué hace

**App del cliente** (una sola página, navegación por pestañas):

- **Inicio**: próximo turno, número en la fila por orden de llegada, tarjeta de fidelidad, servicios más pedidos y minijuegos.
- **Servicios**: catálogo de servicios y, en otra pestaña, productos que se venden en el local.
- **Reservar**: uno o varios servicios → barbero (o "Cualquiera") → día → horario, con disponibilidad real según los
  turnos tomados, los días del local y el horario de cada barbero. Reservar, cancelar y reprogramar se guardan en la base.
- **Barberos**: el equipo, con acceso directo a reservar con cada uno.
- **Perfil**: datos, últimas visitas y cierre de sesión (en este dispositivo o en todos).
- **Fila**: el botón central de la barra abre la cámara para leer el QR del mostrador y sacar número.

**Panel** (`/admin`, administrador y barberos): resumen del día, turnos, fila, cortes por barbero y horarios, finanzas,
ingresos extra, egresos, servicios y productos, y cuentas de usuario. Cada barbero ve "Mi semana" más las secciones que
le habilite el administrador. Detalle en [`docs/panel-y-roles.md`](docs/panel-y-roles.md).

**Superusuario** (`/su`): cuentas con sus navegadores e IP, bloqueos, alertas de seguridad y auditoría completa.

## Diseño: mobile first

La interfaz está pensada para el celular (barra inferior con círculo central) y es la misma en todos los dispositivos:
en una PC se muestra centrada, con el ancho de un celular. El layout de escritorio anterior (barra lateral, grillas y
tipografía Inter) quedó archivado en `docs/archivo/desktop.css` y ya no se carga.

## Estructura del proyecto

```
app/
  Controllers/   Reciben la petición, piden datos a los modelos y eligen la vista
  Models/        Entidades y consultas: User, Barber, BarberHours, Service, Product, Appointment, Schedule, Shop,
                 LoyaltyCard, QueueTicket, GameReward, Dashboard, Expense, Income, Superuser, Ban, AuditLog, UserActivity
  Views/         Plantillas PHP: layouts/, pages/, screens/, components/
  Core/          Mini-framework: Router, Request, Response, View, Controller, Session, Config, Env, Data,
                 Database (PDO), DbSessionHandler, Security (cabeceras y CSRF), RateLimiter (límite de intentos)
  Services/      Lógica transversal: Auth (login, alta, superusuario), Audit, Booking (reservar/cancelar/reprogramar),
                 Device (navegadores de cada cuenta), Qr (QR en SVG), BarberPhoto (fotos de barberos),
                 Integrity (firma de las estadísticas de juegos), IconGenerator (íconos PNG de la PWA)
  Support/       helpers.php (e, money, icon, partial, csrf_field, css_color, fmt_phone…), Dni, Phone y Password
  bootstrap.php  Autoload, manejo de errores, helpers y sesión
config/          app.php (configuración, seguridad y lista de CSS), database.php y routes.php (tabla de rutas)
database/        migrations/NNN_*.sql (esquema)
bin/             migrate, seed, backup, restore-check, make-admin y superuser (línea de comandos)
tests/           PHPUnit (Unit/ e Integration/; usan la base navaja_test)
data/            Datos de ejemplo, un archivo por entidad (semilla y driver files)
storage/         Archivos que escribe la app (contadores de login con driver files); no público, ignorado por git
.env             Credenciales locales (ignorado por git)
public/          Raíz web: index.php (front controller), router.php, sw.js, .htaccess y assets/
  assets/css/    base, layout, components/*, pages/* (login, admin, su), a11y
  assets/js/     Módulos ES de la app (main, navigation, booking, catalog, queue, scanner, arcade, games/…) y del
                 panel (admin, admin-users)
docs/            Arquitectura, seguridad y plan de base de datos
```

## Documentación

| Documento | Contenido |
|-----------|-----------|
| [`docs/arquitectura.md`](docs/arquitectura.md) | Capas, flujo de una petición y cómo extender la app |
| [`docs/seguridad.md`](docs/seguridad.md) | Controles implementados, reglas para código nuevo y pendientes antes de producción |
| [`docs/panel-y-roles.md`](docs/panel-y-roles.md) | Roles, permisos por sección, cada pantalla del panel y el superusuario |
| [`docs/plan-base-de-datos.md`](docs/plan-base-de-datos.md) | Plan por fases para pasar de `data/` a una base de datos y migraciones posteriores |
| [`users.md`](users.md) | Cuentas de prueba y qué puede hacer cada rol |
| [`CHANGELOG.md`](CHANGELOG.md) | Qué cambió en cada entrega y cómo actualizar una instalación |
| [`CLAUDE.md`](CLAUDE.md) | Guía resumida para asistentes de código |

## Rutas

| Método | Ruta                    | Controlador                  | Notas |
|--------|-------------------------|------------------------------|-------|
| GET    | `/`                     | `ShellController@index`      | Requiere sesión |
| GET    | `/login`                | `AuthController@showLogin`   | |
| POST   | `/login`                | `AuthController@login`       | Campos `dni`, `password` y `_csrf`; 419 sin token, 429 si hay demasiados intentos |
| GET/POST | `/registro`           | `AuthController@showRegister` / `register` | Alta de cuenta (solo con base de datos; celular argentino único, una por navegador) |
| GET    | `/recuperar`            | `AuthController@showForgot`  | Redirige al WhatsApp de soporte con el mensaje de "Olvidé mi contraseña" |
| POST   | `/logout-todo`          | `AuthController@logoutAll`   | Cierra la sesión en todos los dispositivos |
| POST   | `/logout`               | `AuthController@logout`      | Requiere token CSRF (`_csrf`) |
| GET    | `/api/disponibilidad`   | `AppointmentController@availability` | JSON. Query `fecha`, `barbero` (slug o `any`), `servicio`. 401 sin sesión |
| POST   | `/turnos`               | `AppointmentController@store`   | Campos `service`, `barber`, `day`, `time`; CSRF por `_csrf` o cabecera `X-CSRF-Token`. 201 / 409 (ocupado) / 422 |
| POST   | `/turnos/cancelar`      | `AppointmentController@cancel`  | Campo `id`; solo turnos propios y futuros |
| POST   | `/turnos/reprogramar`   | `AppointmentController@reschedule` | Campo `id` + los de `/turnos`; cancela y crea en una transacción |
| GET/POST | `/fila?c=`            | `QueueController@show` / `take` | Tomar número en la fila (el QR del mostrador); `POST /fila/salir` lo deja |
| GET    | `/api/fila`             | `QueueController@status`     | JSON: número propio, cuántos hay adelante y a quién atienden |
| POST   | `/juegos/iniciar`, `/juegos/premio` | `GameController` | Minijuegos de Inicio (JSON, sesión + CSRF) |
| GET    | `/admin`, `/admin/mi-semana`, `/admin/turnos`, `/admin/fila`, `/admin/barberos`, `/admin/finanzas`, `/admin/ingresos`, `/admin/egresos`, `/admin/servicios`, `/admin/usuarios` | `AdminController` | Panel; cada sección exige su permiso. `?fecha=YYYY-MM-DD` en las vistas por día o semana |
| POST   | `/admin/turnos/estado`, `/admin/fila/llamar`, `/admin/fila/ausente`, `/admin/barberos/horario`, `/admin/barberos/dias`, `/admin/egresos[/borrar]`, `/admin/ingresos[/borrar]`, `/admin/servicios/guardar`, `/admin/servicios/estado`, `/admin/productos/guardar`, `/admin/productos/estado`, `/admin/usuarios/{guardar,clave,estado,borrar}` | `AdminController` | Escrituras del panel (CSRF); ver [`docs/panel-y-roles.md`](docs/panel-y-roles.md) |
| GET    | `/su`, `/su/seguridad`, `/su/auditoria`, `/su/auditoria/exportar` | `SuperController` | Solo superusuario (404 para una cuenta común; sin sesión, a `/login`) |
| POST   | `/su/usuarios/{guardar,clave,estado,sesiones,borrar}`, `/su/ip/{bloquear,desbloquear}`, `/su/permaban[/quitar]`, `/su/auditoria/limpiar` | `SuperController` | Acciones del superusuario (CSRF, auditadas como `su_*`) |
| GET    | `/manifest.webmanifest` | `PwaController@manifest`     | |
| GET    | `/icon.png?s=192[&m=1]` | `PwaController@icon`         | `m=1` = variante maskable |

## PWA

- **Manifest** e **íconos** se generan en `PwaController` (los íconos con GD, en `IconGenerator`).
- **Service worker** (`public/sw.js`): páginas con red primero, estáticos con caché primero. Las páginas no
  se precachean porque dependen de la sesión. Acepta el mensaje `purge-pages` para borrar las páginas guardadas.
- **Al cambiar CSS o JS**, subí el número de `CACHE` en `public/sw.js`; si agregás archivos, sumalos a `SHELL`.
- Las fuentes se cargan de Google Fonts, así que necesitan internet la primera vez.

Una ruta existente pedida con otro método responde 405; una desconocida, 404.

## Seguridad

Cabeceras (CSP, `X-Frame-Options`, HSTS bajo HTTPS…), tokens CSRF en los formularios, cookie de sesión endurecida,
límite de intentos de login y errores genéricos hacia el usuario. Detalle, reglas y pendientes en
[`docs/seguridad.md`](docs/seguridad.md).

## Limitaciones conocidas

- Implementadas todas las fases del [plan de base de datos](docs/plan-base-de-datos.md). Los recordatorios ("Avisos de turno")
  siguen siendo solo un aviso en pantalla (necesitan un trabajo en segundo plano).
- Las cuentas de demostración usan una contraseña conocida (ver arriba): quitarlas o cambiarla en producción.
- Las pantallas se muestran al instante: las demoras simuladas de los skeletons en `navigation.js` están en 0 (subirlas o
  reemplazarlas por pedidos reales si hace falta). Los horarios de Reservar se piden en vivo a `/api/disponibilidad`.
- Las IP y navegadores bloqueados reciben 403 en todo lo que pasa por PHP; los archivos estáticos que sirve Apache
  directamente no pasan por ese control (no tienen datos).
- Las páginas cacheadas para uso offline se borran del service worker al llegar a la pantalla de login (por ejemplo,
  después de cerrar sesión); si el dispositivo está sin conexión en ese momento, se borran la próxima vez que cargue el login.
