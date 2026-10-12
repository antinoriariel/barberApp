# Panel de administración, roles y superusuario

Guía de todo lo que no es la app del cliente: el panel `/admin` (administrador y barberos), la gestión de cuentas y el
panel del superusuario `/su`. Todo requiere base de datos (`DB_DRIVER=db`); con `DB_DRIVER=files` el panel responde 503.

## 1. Roles

Hay tres roles excluyentes para las cuentas de `users`, más una cuenta especial fuera de esa tabla:

| Rol | Cómo se marca | Dónde entra al iniciar sesión |
|-----|---------------|-------------------------------|
| Cliente | Ni `is_admin` ni `barber_id` | La app (`/`): reservar, fila, fidelidad, perfil |
| Barbero | `users.barber_id` → `barbers.id` (`User::isBarber()`) | El panel: "Mi semana" o la primera sección habilitada |
| Administrador | `users.is_admin = 1` | El panel completo (`/admin`, Resumen) |
| Superusuario | Fila única en la tabla `superuser`; se entra escribiendo `superuser` en el campo DNI | `/su` |

- El personal (admin o barbero) **no ve la app de clientes**: `ShellController::index` lo manda a `/admin`
  (`User::hasPanel()`), y `/admin` lleva al barbero a la primera sección que tenga (`AdminController::requireSection`).
- El rol se elige en `/admin/usuarios` (`User::ROLES`: cliente, barbero, administrador) o por consola:
  `php bin/make-admin.php <dni> [--quitar]`. Las cuentas de la semilla salen de `data/users.php`
  (`'admin' => true` / `'barber' => '<slug>'`); el detalle de las cuentas de prueba está en [`users.md`](../users.md).

### Matriz de permisos del panel

`User::canSee($seccion)` decide; **cada acción** de `AdminController` lo comprueba en el servidor (también los POST), así
que ocultar una pestaña nunca es la única barrera.

| Sección (clave) | Ruta | Administrador | Barbero | Notas |
|-----------------|------|:-------------:|:-------:|-------|
| Mi semana (`semana`) | `/admin/mi-semana` | — | Siempre | Solo su fila de la tabla de cortes de la semana. No se concede: viene con el rol |
| Resumen (`resumen`) | `/admin` | ✔ | Si se le habilita | Ingresos del día vs. el anterior, resultado del mes, gráfico |
| Turnos (`turnos`) | `/admin/turnos` | ✔ | Si se le habilita | Cerrar turnos como atendido (`done`) o ausente (`no_show`) |
| Fila (`fila`) | `/admin/fila` | ✔ | Si se le habilita | QR del mostrador, llamar al siguiente, marcar ausente |
| Barberos (`barberos`) | `/admin/barberos` | ✔ (edita) | Solo lectura | Cortes por barbero en la semana, días del local y horarios (`requireHoursEditor`) |
| Finanzas (`finanzas`) | `/admin/finanzas` | ✔ | Si se le habilita | Resultado por mes: turnos + ingresos extra − egresos |
| Ingreso extra (`ingresos`) | `/admin/ingresos` | ✔ | Si se le habilita | Alta y borrado de ingresos fuera de la agenda |
| Egresos (`egresos`) | `/admin/egresos` | ✔ | Si se le habilita | Alta y borrado de gastos con observación |
| Servicios (`servicios`) | `/admin/servicios` | ✔ (edita) | Solo lectura | Servicios y productos (`requireCatalogEditor`) |
| Usuarios (`usuarios`) | `/admin/usuarios` | ✔ | Nunca | Exclusivo del administrador; no figura en `User::SECTIONS` |

Las secciones que el admin le tilda a un barbero se guardan en `users.panel_access` (lista separada por comas, solo claves
de `User::SECTIONS`; lo demás se descarta al validar). Un barbero que intenta un POST de edición en Barberos o Servicios
recibe **403** aunque tenga la sección.

## 2. Secciones del panel

### Resumen, Turnos y Finanzas (`Models\Dashboard`)

- **Ingresos del día** = suma de `price` de los turnos `done` de esa fecha, comparada con el día anterior.
- **Resultado** del día y del mes = ingresos por turnos + ingresos extra (`incomes`) − egresos (`expenses`).
  `Dashboard::finance()` devuelve los últimos meses con `revenue`, `extra`, `expenses` y `net`.
- `?fecha=YYYY-MM-DD` navega entre días en Resumen y Turnos.

### Barberos (`/admin/barberos`)

- **Cortes por barbero**: tabla lunes → domingo de la semana elegida, solo turnos atendidos (`Dashboard::week()`; la
  misma tabla, filtrada a una sola fila, es "Mi semana": `components/admin-week-table.php`).
- **Días del local** (`POST /admin/barberos/dias`, `Schedule::saveOpenWeekdays`, tabla `opening_hours`): un tilde por
  día. Cerrar un día **no cancela** los turnos ya reservados; la página avisa cuántos quedaron
  (`Appointment::countUpcomingOnWeekdays`). `bin/seed.php` solo completa días faltantes, nunca pisa esta elección.
- **Horario de cada barbero** (`POST /admin/barberos/horario`, `Models\BarberHours`, tabla `barber_hours`): por día de la
  semana, si atiende y de qué hora a qué hora. Un día sin fila = atiende todo el horario del local. Solo se configuran
  los días con turnos (`Schedule::weekdayStatus() === 'open'`); los cerrados o "por orden de llegada" se muestran como
  texto. `Schedule::slots` y `Booking::place` solo ofrecen/aceptan al barbero dentro de su franja; "Cualquiera" lo
  saltea. Los turnos ya reservados no cambian.
- El barbero ve todo esto en **modo lectura** (sin formularios): "Los carga y edita el administrador".

### Servicios y productos (`/admin/servicios`)

- **Servicios**: alta, edición (nombre, descripción, precio, duración) y ocultar/mostrar. Nunca se borran porque los
  turnos los referencian; los turnos ya tomados conservan su precio. La duración no se le muestra al cliente pero la
  usa la agenda. Límites en `Service::validate`.
- **Productos** (`Models\Product`, tabla `products`): se venden en el mostrador y se muestran en Servicios → Productos.
  Alta con slug automático sin acentos, edición de precio y ocultar. La semilla los inserta con `INSERT IGNORE` desde
  `data/products.php`, así que las ediciones del admin sobreviven a `bin/seed.php`.

### Ingreso extra y Egresos

- **Ingreso extra** (`Models\Income`, tabla `incomes`): fecha, monto (acepta `15.000`), categoría (`Corte extra` u
  `Otros`) y observación de hasta 255 caracteres. Para cortes cobrados fuera de la agenda, propinas, ventas sueltas.
- **Egresos** (`Models\Expense`, tabla `expenses`): gasto con observación. Ambos se suman/restan en el resultado.
- `created_by` guarda quién lo cargó; si la cuenta se borra con historial queda en `NULL` (las finanzas no cambian).

### Fila (`/admin/fila`)

QR del mostrador (`Services\Qr`, SVG sin dependencias) que apunta a `/fila?c=<queue.code>` usando `APP_URL`; el cliente
confirma y recibe el número siguiente del día. Desde el panel: llamar al siguiente (el anterior queda atendido) o marcar
ausente. Los números vuelven a 1 cada día.

### Usuarios (`/admin/usuarios`, solo administrador)

| Acción | Ruta | Qué hace |
|--------|------|----------|
| Alta | `POST /admin/usuarios/guardar` (sin `id`) | DNI, nombre y apellido, email opcional, rol, secciones (si es barbero) y contraseña |
| Edición | `POST /admin/usuarios/guardar` (con `id`) | Nombre, email, rol y secciones. **El DNI no se cambia** |
| Contraseña | `POST /admin/usuarios/clave` | Fija una nueva, borra sus enlaces de recuperación y cierra sus sesiones |
| Suspender / reactivar | `POST /admin/usuarios/estado` | `users.active`; la cuenta suspendida no entra (mismo error que una contraseña mala) y pierde su sesión |
| Eliminar | `POST /admin/usuarios/borrar` | Solo cuentas **sin historial** (sin turnos, premios, fila ni movimientos): `User::deletableIds()` |

Reglas:

- **Ficha del barbero automática**: al dar de alta (o pasar a) rol barbero, `User::saveFromAdmin` crea su ficha en
  `barbers` con el nombre de la cuenta (`Barber::provision`: rol "Barbero", 0 años, sin valoración → "Nuevo", color
  libre, slug sin acentos y único). Si existe una ficha inactiva y sin cuenta con el mismo nombre, la reutiliza.
  Renombrar la cuenta renombra la ficha; cambiar el rol o suspender la oculta (avisando si tenía turnos futuros);
  eliminar la cuenta la retira (`Barber::retire`: se borra si nunca atendió, si no se oculta).
- Todo va en una transacción: si el alta choca con un DNI o email repetido no queda una ficha huérfana.
- Nadie puede quitarse el rol de admin ni suspenderse a sí mismo, ni dejar al local sin administradores activos.
- En el navegador, `admin-users.js` valida en vivo con las mismas reglas que el servidor, formatea el DNI con puntos,
  filtra tarjetas con el buscador y pide confirmación en un diálogo propio (`<dialog>`) antes de suspender o borrar.
- Todo queda en la auditoría: `admin_user_create`, `admin_user_update`, `admin_user_password`, `admin_user_enable`,
  `admin_user_disable`, `admin_user_delete` (nunca la contraseña).

## 3. Superusuario (`/su`)

Una cuenta única, **fuera de `users`**, para soporte y seguridad. No es administrador del local: no ve el panel
`/admin`, y `Auth::user()` es `null` para ella (`Auth::isSuperuser()` la identifica por la clave de sesión `su`).

### Configurarlo y entrar

```bash
php bin/superuser.php                      # genera una contraseña aleatoria, la guarda y la muestra UNA vez
php bin/superuser.php '<contraseña>'       # fija esa (queda en el historial de la consola)
php bin/superuser.php --desbloquear-ip <ip>
php bin/superuser.php --desbloquear-todo   # salida de emergencia: borra todos los bloqueos de IP y Permaban
```

- En `/login`, escribir `superuser` en el campo DNI (sin importar mayúsculas ni espacios) y esa contraseña.
- Mismo límite de intentos y mismo mensaje de error que cualquier cuenta (clave `login:dni:superuser`).
- La sesión vence tras 2 h sin actividad (`security.superuser_idle`) y se invalida al rotar la contraseña
  (`superuser.session_version`). Sin contraseña configurada no se puede entrar.
- `/su` y todas sus rutas responden **404** a una sesión de cuenta común (no revelan que existen); sin sesión
  redirigen a `/login`.

### Cuentas (`GET /su`)

Lista de todas las cuentas con:

- **Navegadores** (`user_devices`): cada navegador desde el que entró, con IP, user agent resumido ("Chrome · Android"),
  primera y última conexión. Lo escribe `Services\Device::touch` desde `Auth::user()`, como mucho una vez por minuto.
  El navegador se identifica con la cookie `navaja_dev` (32 bytes aleatorios, HttpOnly, 10 años); en la base solo se
  guarda su sha256.
- **Últimas 3 acciones** de la auditoría (`Models\UserActivity`), con desde qué navegador se hicieron.
- Las tarjetas de arriba son filtros: `?ver=en-linea` (vistas en los últimos 5 minutos) y `?ver=de-baja` (suspendidas).

Acciones (todas POST con CSRF, auditadas con `user_id = NULL` y eventos `su_*`):

| Acción | Ruta | Detalle |
|--------|------|---------|
| Editar | `/su/usuarios/guardar` | Mismos campos y reglas que `/admin/usuarios` |
| Contraseña | `/su/usuarios/clave` | Cierra las sesiones de la cuenta |
| Suspender / reactivar | `/su/usuarios/estado` | |
| Cerrar sesiones | `/su/usuarios/sesiones` | Saca a la cuenta de todos los dispositivos |
| Eliminar con historial | `/su/usuarios/borrar` | `User::purge`: borra turnos, premios y números de fila; egresos/ingresos quedan sin autor; la auditoría y los bloqueos se conservan. Muestra antes cuántos registros arrastra (`User::historyCounts`) |
| Bloquear IP | `/su/ip/bloquear` | Duración de `Ban::DURATIONS`: 1 h, 24 h, 7 días o permanente |
| Desbloquear IP | `/su/ip/desbloquear` | |
| Permaban | `/su/permaban` | Con `device`: bloquea ese navegador para siempre. Sin `device`: bloquea todos los navegadores de la cuenta, la suspende y cierra sus sesiones |
| Quitar Permaban | `/su/permaban/quitar` | |

`public/index.php` consulta `Models\Ban::blocks($ip, $dispositivo)` **antes de enrutar** y responde 403 a una IP o
navegador bloqueados; cada rechazo suma `hits` y `last_hit_at` (para ver si siguen intentando). El superusuario nunca
puede bloquear su propia IP ni su propio navegador.

### Seguridad (`GET /su/seguridad`)

Alertas de las últimas 24 h, calculadas sobre `audit_log` (`Models\AuditLog`):

- **IP sospechosas**: 3 o más logins fallidos, con cuántos DNI distintos probaron, y bloqueo temporal en un click.
- **DNI atacados**: cuentas con varios fallos (incluido `superuser`); un fallo suelto no es alerta.
- **Cuentas desde muchas IP**: 3 o más IP distintas.
- **Navegadores nuevos** en cuentas que ya tenían otros.
- **Accesos del superusuario**.
- **Retención**: `POST /su/auditoria/limpiar` borra lo de más de 90, 180 o 365 días; la limpieza misma queda registrada.

### Auditoría (`GET /su/auditoria`)

Todo `audit_log` paginado, con filtros por grupo de eventos, cuenta, IP, texto libre (el `%` y el `_` se buscan
literales) y rango de fechas (las inválidas se descartan). `GET /su/auditoria/exportar` baja el mismo filtro en CSV
separado por `;` con BOM (abre bien en Excel), y neutraliza las celdas que empiezan con `= + - @` contra inyección de
fórmulas.

Las acciones de estas páginas mandan `volver=seguridad|auditoria` para regresar a donde se hizo el click.

## 4. Tablas involucradas

| Migración | Tablas / columnas |
|-----------|-------------------|
| `011_barber_hours.sql` | `barber_hours (barber_id, weekday, is_working, start_time, end_time)` |
| `012_products.sql` | `products (slug, name, description, price, active, sort_order)` |
| `013_incomes.sql` | `incomes (received_on, category, notes, amount, created_by)` |
| `014_user_admin.sql` | `users.active`, `users.panel_access` |
| `015_superuser.sql` | `superuser`, `user_devices`, `ip_bans`, `device_bans`, `audit_log.user_agent` |
| `016_audit_tools.sql` | `ip_bans.expires_at`, `hits`/`last_hit_at` en ambos bloqueos, índices de `audit_log` por IP y fecha |

`users` está en `utf8mb4_unicode_ci` y las tablas nuevas en `utf8mb4_general_ci`: un JOIN por columnas de texto entre
ellas necesita `COLLATE` explícito.
