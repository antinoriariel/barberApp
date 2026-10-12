# Cambios

Registro de los cambios del proyecto, del más nuevo al más viejo. Detalle funcional en
[`docs/panel-y-roles.md`](docs/panel-y-roles.md); seguridad en [`docs/seguridad.md`](docs/seguridad.md).

## 2026-10-12 — Integración del working copy, superusuario y documentación

Rama `worktree-documentar-y-publicar`. Reúne en un solo historial el trabajo que estaba sin commitear en la copia local
con lo que ya se había publicado en `origin/master` (PR #1, #2 y #3), y documenta todo.

### Contexto: por qué una integración

El `master` local seguía en el commit inicial (`a839e9c`) con 73 archivos modificados o nuevos sin commitear, mientras
que `origin/master` ya tenía tres PR fusionados que se habían armado a partir de partes de esa misma copia local. Para
no pisar ni perder nada:

1. Se tomó una foto exacta de la copia local (sin `.claude/`) como commit sobre `a839e9c` (`84cf7b0`).
2. Se fusionó esa foto en una rama creada desde `origin/master` (`51b8866`), con fusión de tres vías: lo que cada lado
   agregó se conserva.
3. Se resolvieron a mano 11 archivos en conflicto (ver abajo) y se corrieron todas las pruebas.

### Nuevo

**Superusuario (`/su`)** — cuenta única fuera de `users` para soporte y seguridad.

- Login escribiendo `superuser` en el campo DNI; contraseña en la tabla `superuser`, fijada o rotada con
  `php bin/superuser.php [contraseña]` (sin argumento genera una aleatoria y la muestra una vez).
- Sesión que vence tras 2 h sin actividad y se invalida al rotar la contraseña. `/su` responde 404 a las demás cuentas.
- Lista de cuentas con sus navegadores (IP, user agent, primera y última conexión), últimas 3 acciones y filtros
  "en línea" / "dadas de baja".
- Acciones: editar, cambiar contraseña, suspender, cerrar sesiones, eliminar **con** historial (`User::purge`),
  bloquear IP (1 h, 24 h, 7 días o permanente) y "Permaban" de navegadores. Salida de emergencia:
  `php bin/superuser.php --desbloquear-todo`.
- `/su/seguridad`: alertas de 24 h (fuerza bruta por IP con bloqueo en un click, DNI atacados, cuentas desde muchas IP,
  navegadores nuevos, accesos del superusuario) y retención de la auditoría (90/180/365 días).
- `/su/auditoria`: registro completo con filtros, paginación y exportación CSV a prueba de inyección de fórmulas.
- Archivos: `SuperController`, `Models\Superuser`, `Ban`, `AuditLog`, `UserActivity`, `Services\Device`,
  `pages/su*.php`, `css/pages/su.css`, `bin/superuser.php`.

**Rastreo de navegadores y bloqueos**

- Cookie `navaja_dev` por navegador (en la base solo su sha256); `Device::touch` registra cada cuenta como mucho una
  vez por minuto en `user_devices`.
- `public/index.php` responde 403 a IP y navegadores bloqueados antes de enrutar, y cuenta cada rechazo.
- La auditoría guarda ahora el user agent de cada acción.

**Panel por roles** (ya publicado en PR #2 y #3, incluido aquí con la integración)

- `/admin/usuarios`: alta, edición, contraseña, suspensión y baja definitiva de cuentas sin historial, con validación en
  vivo y ficha de barbero creada automáticamente con el nombre de la cuenta.
- Secciones del panel concedibles a cada barbero (`users.panel_access`) y "Mi semana" para todo barbero.
- El personal ya no ve la app de clientes: entra directo al panel.

**Barberos en solo lectura** (PR #1): el barbero con la sección Barberos ve los días del local y los horarios como
texto; solo el administrador los edita (`requireHoursEditor`). Igual que Servicios (`requireCatalogEditor`).

**Diálogo de confirmación propio** en `/admin/usuarios` y `/su`: reemplaza `window.confirm()`, que el navegador puede
silenciar ("no permitir más diálogos") y dejaba el botón sin efecto. El foco queda en "Cancelar".

### Base de datos

Correr `php bin/migrate.php` (aplica solo las pendientes):

| Migración | Cambio |
|-----------|--------|
| `011_barber_hours.sql` | Horario semanal por barbero |
| `012_products.sql` | Productos editables desde el panel |
| `013_incomes.sql` | Ingresos extra |
| `014_user_admin.sql` | `users.active` (baja lógica) y `users.panel_access` |
| `015_superuser.sql` | `superuser`, `user_devices`, `ip_bans`, `device_bans`, `audit_log.user_agent` |
| `016_audit_tools.sql` | Bloqueos temporales, contadores de rechazos e índices de `audit_log` |

Después, `php bin/superuser.php` para configurar la contraseña del superusuario (sin ella nadie puede entrar a `/su`).

### Decisiones de la fusión

| Archivo | Se quedó con | Por qué |
|---------|--------------|---------|
| `AdminController.php`, `pages/admin-barberos.php`, `css/pages/admin.css` | `origin/master` | Traen la vista de solo lectura de días y horarios (PR #1), que la copia local no tenía |
| `Services/Auth.php`, `AuthController.php`, `Models/User.php`, `config/routes.php` | Copia local | Agregan el superusuario (`isSuperuser`, login, `purge`, `historyCounts`, rutas `/su`) sin quitar nada del remoto |
| `components/admin-header.php` | Copia local | Pestañas del superusuario. Se quitó el botón "Ir a la app": desde el commit `64772af` el personal que entra a `/` vuelve a `/admin`, así que era un bucle (y habría aparecido en `/su`) |
| `js/admin-users.js` | Copia local | Diálogo de confirmación propio |
| `CLAUDE.md` | Ambos | Rol con `requireHoursEditor` + sección del superusuario + migraciones hasta 016 |
| `public/sw.js` | Ninguno | `CACHE = 'navaja-v102'`, mayor que el de ambos lados, para que los navegadores bajen el CSS/JS fusionado |

### Documentación

- Docblock en español en **todas** las funciones y métodos del servidor que no tenían: `DbSessionHandler::open/close/
  destroy`, los constructores de `Barber`, `LoyaltyCard`, `Product`, `Service`, `Shop`, `User`, `BookingException` y
  `Qr`, los closures de `bin/seed.php` y `AuditLog::filters`, y los 71 métodos de prueba (qué regla verifica cada uno).
  No se agregaron comentarios al código que llega al navegador (JS, CSS y HTML de las vistas).
- Nuevo [`docs/panel-y-roles.md`](docs/panel-y-roles.md): roles, matriz de permisos, cada sección del panel y el
  superusuario.
- Actualizados `README.md` (rutas, estructura, pruebas, estado real de las funciones), `docs/arquitectura.md` (todos
  los modelos, controladores, servicios y módulos JS), `docs/seguridad.md`, `docs/plan-base-de-datos.md` y `users.md`.

### Pruebas

`vendor/bin/phpunit` → **OK (65 pruebas, 384 aserciones)**, antes y después de la documentación. Incluye las suites
nuevas `SuperuserTest`, `UserAdminTest`, `BarberHoursTest`, `IncomeTest` y `ProductTest`, y la carrera de doble reserva
con dos procesos simultáneos. Para correrlas hizo falta recrear la base `navaja_test`, que tenía un administrador
viejo (DNI 30111222) con el mismo email que el de la semilla actual; la base de pruebas se crea sola, así que borrarla
es seguro.

### Para actualizar una instalación existente

```bash
git pull
php bin/migrate.php       # 011 a 016
php bin/seed.php          # idempotente: completa productos y días del local sin pisar lo editado
php bin/superuser.php     # anota la contraseña que muestra
```

## 2026-10 — PR #1 a #3 (ya en `master`)

- **#3** El personal (admin o barbero) va al panel en vez de la app de clientes.
- **#2** Usuarios: alta, baja, contraseñas, roles, secciones por barbero y validación en vivo; tablero "Mi semana" del
  barbero; el barbero solo ve Servicios y edita su propio horario (esto último lo reemplazó el modo lectura de #1).
- **#1** Barberos: días del local y horarios en modo lectura para barberos.

## Inicio del proyecto (`a839e9c`)

App de turnos con base de datos, login por DNI + contraseña, reservas, fila con QR, fidelidad, panel de administración,
finanzas, egresos, minijuegos y PWA.
