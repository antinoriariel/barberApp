# Usuarios de prueba

Cuentas que crea `php bin/seed.php` (idempotente) a partir de [`data/users.php`](data/users.php).
Usan la contraseña de demostración **`Navaja2026!`** (configurable con `DEMO_PASSWORD` en `.env`), salvo el administrador, que usa **`admin123`** (se cambió en la base; la semilla no pisa contraseñas existentes).

> **Solo para desarrollo.** Borrar estas cuentas o cambiar la contraseña antes de producción (ver `docs/seguridad.md`).

| Rol           | DNI        | Nombre          | Celular               | Contraseña    |
|---------------|------------|-----------------|-----------------------|---------------|
| Administrador | 33.202.388 | Andres Sena     | +54 9 3781 55-0202    | `admin123`    |
| Barbero       | 32.333.444 | Nico Ledesma    | +54 9 3781 55-0303    | `Navaja2026!` |
| Cliente       | 40.123.456 | Mateo Fernández | +54 9 379 411-2233    | `Navaja2026!` |
| Cliente       | 35.987.654 | Camila Rojas    | +54 9 11 5555-0101    | `Navaja2026!` |

El DNI se acepta con o sin puntos (`33202388` o `33.202.388`). Los celulares son de ejemplo (no existen).

**Registro** (`/registro`): pide nombre, DNI, celular argentino (único por cuenta) y contraseña. Se permite **una sola
cuenta por navegador**: para probar otra alta desde el mismo navegador, liberarlo en `/su/registros` o borrar la cookie
`navaja_dev`. **"Olvidé mi contraseña"** abre WhatsApp (`support.whatsapp` en `config/app.php`); la contraseña nueva la
pone el administrador en `/admin/usuarios`.

**Superusuario**: en el campo DNI se escribe `superuser`. No viene de la semilla: la contraseña se fija con
`php bin/superuser.php` (sin argumento genera una aleatoria y la muestra una sola vez) o `php bin/superuser.php <contraseña>`.
Entra a `/su`: todas las cuentas con sus IP, navegadores, última conexión y últimas 3 acciones, y los bloqueos de IP y Permaban.

## Qué puede hacer cada rol

- **Administrador** (`users.is_admin = 1`): al entrar va directo al panel `/admin` (no ve la app de clientes) y usa
  todas sus secciones: Resumen, Turnos (cierre como `done` / `no_show`), Fila, Barberos (días del local y horario de
  cada barbero), Finanzas, Ingreso extra, Egresos, Servicios (servicios y productos) y Usuarios (cuentas, roles,
  contraseñas, suspensión y baja).
- **Barbero** (`users.barber_id` apunta a su ficha de `barbers`; la cuenta de Nico Ledesma es la del barbero `nico`):
  al entrar va al panel, a **"Mi semana"** (sus cortes atendidos de la semana). Además ve **solo las secciones que el
  administrador le habilite** en `/admin/usuarios`; Barberos y Servicios los ve en modo lectura, y "Usuarios" es siempre
  exclusivo del admin. Las cuentas de barbero nuevas crean su ficha del equipo automáticamente con su nombre.
- **Superusuario** (fuera de esta tabla, ver arriba): `/su`, con todas las cuentas, bloqueos de IP y navegadores,
  alertas de seguridad y auditoría.

Detalle de cada sección y de los permisos en [`docs/panel-y-roles.md`](docs/panel-y-roles.md).
- **Cliente** (sin rol): reserva, reprograma y cancela turnos, ve su tarjeta de fidelidad.
  Mateo (7 visitas) y Camila (10 visitas, corte gratis) traen historial y un próximo turno de ejemplo.
  Las cuentas de administrador y barbero no reciben turnos de ejemplo.

## Cómo usarlas

```bash
php bin/migrate.php   # aplica las migraciones pendientes (roles: 003, 004 y 014)
php bin/seed.php      # crea/actualiza las cuatro cuentas
php bin/superuser.php # contraseña del superusuario (se muestra una sola vez)
```

- Desde el panel: `/admin/usuarios` da de alta y de baja cuentas, cambia contraseñas y asigna rol (y secciones del barbero).
- Para convertir otra cuenta en administrador por consola: `php bin/make-admin.php <dni> [--quitar]`.
- Para agregar una cuenta de personal: sumar una fila en `data/users.php` con `'admin' => true` o `'barber' => '<slug>'`
  (el slug sale de `data/barbers.php`) y volver a correr `php bin/seed.php`.
- Con `DB_DRIVER=files` solo existe el login (la misma contraseña); los roles requieren base de datos.
