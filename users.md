# Usuarios de prueba

Cuentas que crea `php bin/seed.php` (idempotente) a partir de [`data/users.php`](data/users.php).
Usan la contraseña de demostración **`Navaja2026!`** (configurable con `DEMO_PASSWORD` en `.env`), salvo el administrador, que usa **`admin123`** (se cambió en la base; la semilla no pisa contraseñas existentes).

> **Solo para desarrollo.** Borrar estas cuentas o cambiar la contraseña antes de producción (ver `docs/seguridad.md`).

| Rol           | DNI        | Nombre          | Email               | Contraseña    |
|---------------|------------|-----------------|---------------------|---------------|
| Administrador | 33.202.388 | Andres Sena     | admin@navaja.test   | `admin123`    |
| Barbero       | 32.333.444 | Nico Ledesma    | nico@navaja.test    | `Navaja2026!` |
| Cliente       | 40.123.456 | Mateo Fernández | mateoFnz@gmail.com    | `Navaja2026!` |
| Cliente       | 35.987.654 | Camila Rojas    | camila@correo.com   | `Navaja2026!` |

El DNI se acepta con o sin puntos (`33202388` o `33.202.388`).

## Qué puede hacer cada rol

- **Administrador** (`users.is_admin = 1`): todo lo del cliente más el panel `GET /admin` (ingresos del día, cierre de
  turnos como `done` / `no_show`) y el editor de servicios `/admin/servicios` (precio, nombre, descripción, duración; alta y ocultar). Sin sesión de admin, `/admin` responde 403.
- **Barbero** (`users.barber_id` apunta a su ficha de `barbers`; la cuenta de Nico Ledesma es la del barbero `nico`):
  hoy la cuenta queda vinculada a su ficha del equipo (`User::isBarber()`, `User::$barberId`) y entra a la app como
  cualquier cuenta. **Todavía no existe un panel propio de barbero**; el vínculo deja la base lista para construirlo.
- **Cliente** (sin rol): reserva, reprograma y cancela turnos, ve su tarjeta de fidelidad.
  Mateo (7 visitas) y Camila (10 visitas, corte gratis) traen historial y un próximo turno de ejemplo.
  Las cuentas de administrador y barbero no reciben turnos de ejemplo.

## Cómo usarlas

```bash
php bin/migrate.php   # aplica 004_barber_role.sql (columna users.barber_id)
php bin/seed.php      # crea/actualiza las cuatro cuentas
```

- Para convertir otra cuenta en administrador: `php bin/make-admin.php <dni> [--quitar]`.
- Para agregar una cuenta de personal: sumar una fila en `data/users.php` con `'admin' => true` o `'barber' => '<slug>'`
  (el slug sale de `data/barbers.php`) y volver a correr `php bin/seed.php`.
- Con `DB_DRIVER=files` solo existe el login (la misma contraseña); los roles requieren base de datos.
