# Plan: conexión con la base de datos

Objetivo: reemplazar `data/*.php` por una base de datos **y** hacer que reservar, cancelar y reprogramar turnos
guarden de verdad, sin romper controladores ni vistas en el camino.

Estado: **todas las fases implementadas** (0–5). Quedan fuera, por decisión, el retiro de `Core\Data` (se hará cuando el driver `db` lleve tiempo estable) y los recordatorios.
El interruptor `DB_DRIVER=files|db` permite volver a la maqueta. Notas de la implementación:

- "Cualquiera" asigna el primer barbero libre; se agregó `booking.closing_time` (20:00): un servicio no puede terminar después del cierre.
- Límites extra en `config/app.php → booking`: ventana de 30 días y máximo de turnos futuros por cliente.
- `users.visits` se calcula como turnos `done`, sin ciclo con módulo (igual que antes).
- `Schedule::slots()` cambió de firma como estaba previsto; `ShellController` pasa el primer día y servicio y `booking.js` recarga al cambiar.
- Fase 4: contraseña con Argon2id (`Support\Password`), login con mensaje único y hash falso para igualar tiempos, alta (`/registro`)
  y recuperación por email (`/recuperar`, token hasheado de un solo uso; el `Mailer` escribe en `storage/mail.log` salvo `MAIL_DRIVER=mail`),
  sesiones en la tabla `sessions` (`DbSessionHandler`, con cierre en todos los dispositivos), `audit_log` y `RateLimiter` en `login_attempts`.
  Se quitaron los DNI de prueba del login; las cuentas demo del seed usan `auth.demo_password`.
- Fase 5: PHPUnit 11 instalado solo para desarrollo con Composer (`composer.json` solo tiene `require-dev`); 25 pruebas, incluida la carrera de
  doble reserva con procesos reales. `bin/backup.php` (mysqldump + gzip + retención) y `bin/restore-check.php` (restaura en base temporal y compara).
- Las migraciones `002_auth_audit.sql` y `002_game_rewards.sql` comparten el número 002: se aplican en orden alfabético y ya están registradas
  así; la 003 agrega `users.is_admin` y la 004 `users.barber_id` (FK a `barbers`, rol de barbero); para las próximas usar 005 en adelante.
- Cuentas de prueba por rol (admin, barbero, clientes): `users.md`.
- El entorno local usa `root` en `.env` por tablas de privilegios dañadas en el MariaDB de XAMPP (ver `docs/seguridad.md`).

## 1. Decisiones a tomar antes de empezar

| Decisión | Recomendación | Por qué |
|----------|---------------|---------|
| Motor | **MySQL/MariaDB** (ya viene con XAMPP) vía **PDO** | Cero instalación extra, y PDO permite cambiar de motor tocando poco. SQLite sería válido si se quiere un solo archivo y un solo local. |
| Acceso a datos | **PDO a mano** dentro de los modelos, sin ORM | Respeta la filosofía "PHP puro" del proyecto y el modelo ya es la única frontera con los datos. |
| Un local o varios | **Un local** ahora; dejar `shop_id` fuera hasta que haga falta | Evita complejidad no pedida. Agregarlo después es una migración simple. |
| Autenticación | Se resuelve en la fase 4, no bloquea las fases 1-3 | Hoy el login por DNI sigue funcionando sobre la tabla `users`. |
| Dinero | Pesos enteros (`INT UNSIGNED`) | Los precios actuales son enteros; se guarda el precio **al momento de reservar** para no alterar el historial si cambia el catálogo. |

> Estas decisiones son del dueño del producto; el resto del plan asume las recomendaciones.

## 2. Modelo de datos

Mapeo de lo que hoy está en `data/`:

| Archivo actual | Tabla(s) | Modelo que la usa |
|----------------|----------|-------------------|
| `data/shop.php` | `shop` (una fila) | `Shop::get()` |
| `data/services.php` | `services` | `Service::all()`, `popular()` |
| `data/barbers.php` | `barbers` | `Barber::all()` |
| `data/users.php` | `users` | `User::find()`, `all()` |
| `data/schedule.php` | `opening_hours`, `slot_templates`, `closures` | `Schedule::openDays()`, `slots()` |
| `data/appointments.php` | `appointments` | `Appointment::next()`, `history()` |
| *(nuevo)* | `login_attempts` | `RateLimiter` |

```sql
CREATE TABLE shop (                                   -- una sola fila
  id          TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  name        VARCHAR(80)  NOT NULL,
  address     VARCHAR(190) NOT NULL,
  hours       VARCHAR(80)  NOT NULL,                  -- texto de presentación ("Mar a Sáb, 10 a 20 h")
  phone       VARCHAR(30)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dni         CHAR(8)      NOT NULL UNIQUE,          -- solo dígitos, como Dni::normalize()
  name        VARCHAR(60)  NOT NULL,                 -- nombre de pila
  full_name   VARCHAR(120) NOT NULL,
  email       VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,                   -- fase 4
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(30)  NOT NULL UNIQUE,          -- 'corte', 'barba'… (es lo que hoy usa data-book-service)
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(190) NOT NULL,
  minutes     SMALLINT UNSIGNED NOT NULL,
  price       INT UNSIGNED NOT NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  SMALLINT     NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE barbers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(30)  NOT NULL UNIQUE,          -- 'nico', 'fran'… (data-book-barber)
  name        VARCHAR(80)  NOT NULL,
  role        VARCHAR(80)  NOT NULL,
  years       TINYINT UNSIGNED NOT NULL,
  rating      DECIMAL(2,1) NOT NULL,
  tone        CHAR(7)      NOT NULL,                 -- '#D7263D'; se valida con css_color() al imprimir
  active      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE opening_hours (                          -- reemplaza closed_weekdays
  weekday     TINYINT UNSIGNED PRIMARY KEY,           -- 0 = domingo (como date('w'))
  is_open     TINYINT(1) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE slot_templates (                         -- los horarios fijos de data/schedule.php
  slot_time   TIME PRIMARY KEY
) ENGINE=InnoDB;

CREATE TABLE closures (                               -- feriados / días especiales
  day         DATE PRIMARY KEY,
  reason      VARCHAR(120) NULL
) ENGINE=InnoDB;

CREATE TABLE appointments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  barber_id   INT UNSIGNED NOT NULL,
  service_id  INT UNSIGNED NOT NULL,
  starts_at   DATETIME     NOT NULL,
  ends_at     DATETIME     NOT NULL,                  -- starts_at + services.minutes
  price       INT UNSIGNED NOT NULL,                  -- precio congelado al reservar
  status      ENUM('booked','cancelled','done','no_show') NOT NULL DEFAULT 'booked',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Anti doble reserva: solo los turnos 'booked' ocupan el horario del barbero.
  active_slot DATETIME GENERATED ALWAYS AS (IF(status = 'booked', starts_at, NULL)) STORED,
  UNIQUE KEY uq_barber_slot (barber_id, active_slot),
  KEY idx_user_start (user_id, starts_at),
  CONSTRAINT fk_ap_user    FOREIGN KEY (user_id)    REFERENCES users(id),
  CONSTRAINT fk_ap_barber  FOREIGN KEY (barber_id)  REFERENCES barbers(id),
  CONSTRAINT fk_ap_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (                         -- fase 4: reemplaza storage/ratelimit/
  key_hash    CHAR(64) PRIMARY KEY,
  attempts    SMALLINT UNSIGNED NOT NULL,
  expires_at  DATETIME NOT NULL
) ENGINE=InnoDB;
```

Notas de diseño:

- **Visitas / fidelidad**: `users.visits` no se guarda; `LoyaltyCard` lo calcula con
  `COUNT(*) FROM appointments WHERE user_id = ? AND status = 'done'` (módulo la meta si se reinicia el ciclo).
  El valor `visits` de `data/users.php` se convierte en turnos `done` sembrados.
- **"Cualquiera" como barbero**: no es un registro. El servidor asigna el primer barbero libre en ese horario al confirmar.
- **Doble reserva**: la restricción `uq_barber_slot` es la defensa real (a prueba de carreras); el chequeo previo en
  PHP solo sirve para dar un mensaje amable. Un turno que dura más de un slot necesita además validar solapamientos
  (`starts_at < nuevo_fin AND ends_at > nuevo_inicio`) dentro de la transacción, con `SELECT … FOR UPDATE`.
- Zona horaria: guardar en hora local del local y fijar `date_default_timezone_set('America/Argentina/Buenos_Aires')`
  y `SET time_zone` en la conexión, para que `Schedule` y la base coincidan.

## 3. Fases

Cada fase termina con la app funcionando y se puede entregar sola.

### Fase 0 — Preparación

- Crear base `navaja` (utf8mb4) y un usuario **con privilegios mínimos** (`SELECT, INSERT, UPDATE, DELETE`; sin `DROP`/`ALTER`).
  Otro usuario aparte, solo para migraciones.
- Credenciales fuera del código: archivo `.env` en la raíz (ignorado por git y bloqueado por `.htaccess`) o variables de
  entorno. `config/app.php` agrega un bloque `database` leyendo `getenv()` (mismo patrón que `debug`).
- Agregar `config/database.php`: `dsn`, `user`, `password`, `charset`, `timezone`, y `driver` = `files|db` (ver fase 2).

### Fase 1 — Infraestructura (sin tocar modelos)

1. **`app/Core/Database.php`**: conexión PDO perezosa (se abre en la primera consulta) con
   `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false` y `utf8mb4`.
   API mínima: `Database::select($sql, $params)`, `selectOne()`, `execute()`, `transaction(callable)`, `lastId()`.
   **Siempre** sentencias preparadas con parámetros; los errores van al log y al usuario le llega la página 500 genérica
   (ya lo hace `public/index.php`).
2. **Migraciones**: carpeta `database/migrations/NNN_nombre.sql` + tabla `migrations` + script `bin/migrate.php`
   que aplica las pendientes en orden. Sin herramientas externas.
3. **Semillas**: `bin/seed.php` lee los `data/*.php` actuales y los inserta (los archivos `data/` quedan como
   fuente de la semilla y de datos de prueba, no se borran).
4. Probar conexión, migración y semilla en una base vacía y repetirlas (deben ser idempotentes).

### Fase 2 — Lectura desde la base (catálogo → usuarios → turnos)

Reemplazar `Data::get()` por consultas **modelo por modelo**, de menor a mayor riesgo. La firma pública de cada
método **no cambia**, así que controladores y vistas quedan intactos:

| Orden | Modelo | Cambio |
|-------|--------|--------|
| 1 | `Shop::get()` | `SELECT … FROM shop LIMIT 1` |
| 2 | `Service::all()` / `popular()` | `WHERE active = 1 ORDER BY sort_order`. `popular()` pasa a ordenar por cantidad de turnos. |
| 3 | `Barber::all()` | `WHERE active = 1` |
| 4 | `User::find()` / `all()` | `WHERE dni = ?`. `all()` solo se mantiene mientras existan los DNI de prueba. |
| 5 | `Schedule::openDays()` | usa `opening_hours` + `closures` |
| 5 | `Schedule::slots()` | **cambia la firma**: `slots(string $date, ?string $barberSlug, int $minutes)` y marca `free` según los turnos reales. Es el único cambio de interfaz; se adapta `ShellController` y la vista. |
| 6 | `Appointment::next($user)` / `history($user)` | filtran por `user_id` (hoy todos ven los mismos datos) |
| 7 | `LoyaltyCard::for($user)` | visitas = turnos `done` |

- **Interruptor de seguridad**: `config('database.driver')` (`files` o `db`). Cada modelo usa uno u otro hasta que la
  migración esté validada, y se puede volver atrás cambiando una línea.
- Las vistas esperan `Service`/`Barber` (objetos) y arreglos con las mismas claves que hoy
  (`service`, `barber`, `day`, `time`, `date`, `price`): los modelos adaptan las filas SQL a esas formas.
- Criterio de salida: con `driver = db` la app se ve **idéntica** a la de `files` para los dos usuarios de prueba.

### Fase 3 — Escritura: reservar, cancelar y reprogramar

Nuevas rutas (todas `POST`, con `requireLogin()` + `requireCsrf()`), en un `AppointmentController`:

| Ruta | Acción |
|------|--------|
| `GET /api/disponibilidad?fecha=&barbero=&servicio=` | Devuelve los horarios libres (JSON). Reemplaza el `setTimeout` simulado de `navigation.js` por carga real. |
| `POST /turnos` | Crea el turno. |
| `POST /turnos/cancelar` | Cancela (solo el dueño, solo si es futuro). |
| `POST /turnos/reprogramar` | Cancela el viejo y crea el nuevo **en una sola transacción**. |

Flujo de `POST /turnos` (dentro de `Database::transaction`):

1. Validar entrada en el servidor: `service` y `barber` existen y están activos, la fecha es un día abierto y futuro
   (dentro de la ventana permitida, p. ej. 30 días), la hora es un `slot_template`, el usuario no tiene otro turno solapado.
2. Resolver `barber = any` (primer barbero libre).
3. `INSERT` con `price` y `ends_at` calculados **en el servidor** (nunca confiar en precios/duraciones del cliente).
4. Si salta la restricción `uq_barber_slot` → responder **409** "Ese horario se acaba de ocupar" y recargar la disponibilidad.
5. Responder JSON con el turno creado.

Front-end:

- `booking.js`: `confirm()` pasa de mostrar un toast a `fetch('/turnos', { method: 'POST', headers: { 'X-CSRF-Token': meta } })`;
  muestra el toast **después** de la respuesta y maneja 409/422/419/429.
- `ticket.php`: los botones "Reprogramar" / "Cancelar" dejan de ser `data-toast` y envían las acciones reales
  (con confirmación antes de cancelar).
- El servidor verifica el token CSRF también desde la cabecera `X-CSRF-Token` (ampliar `Security::verifyCsrf` o
  `Controller::requireCsrf` para aceptarla — hoy solo lee el campo `_csrf`).
- `sw.js`: las rutas `/api/*` y `/turnos*` **no** se cachean (ya solo se cachean GET de páginas y estáticos; verificar
  que `/api/disponibilidad` quede fuera) y subir `CACHE`.
- Recordatorios (el botón "Avisos de turno") quedan fuera de este plan: requieren un trabajo en segundo plano.

### Fase 4 — Autenticación real y endurecimiento

- Contraseña con `password_hash(PASSWORD_ARGON2ID)` (o `PASSWORD_DEFAULT`) y `password_verify`; reglas de longitud mínima;
  alta de usuarios y recuperación por email. Alternativa: código de un solo uso por email/SMS.
- Mensaje de error único en login (evita enumerar DNI) y quitar los DNI de prueba del login (`docs/seguridad.md`).
- `RateLimiter` pasa a la tabla `login_attempts` (misma API estática, otro almacenamiento).
- Opcional: sesiones en base de datos (`SessionHandlerInterface`) para poder cerrar sesiones de todos los dispositivos.
- Registro de auditoría de login, cancelaciones y reprogramaciones.

### Fase 5 — Calidad y puesta en marcha

- Tests: hoy no hay ninguno. Agregar al menos PHPUnit sobre `Dni`, `LoyaltyCard`, `Schedule` y la creación de turnos
  (incluida la carrera de doble reserva, con dos transacciones simultáneas sobre una base de pruebas).
- Respaldos automáticos de la base y prueba de restauración.
- Documentar en `README.md` cómo crear la base, correr `bin/migrate.php` y `bin/seed.php`.
- Retirar `Core\Data` y los modelos "files" cuando el driver `db` lleve un tiempo estable.

## 4. Riesgos y cómo se mitigan

| Riesgo | Mitigación |
|--------|-----------|
| Dos clientes reservan el mismo horario a la vez | Restricción única `uq_barber_slot` + transacción; el segundo recibe 409. |
| Cliente manipula precio/duración/fecha | El servidor recalcula todo desde `services` y valida contra `opening_hours`/`slot_templates`. |
| Un cliente cancela turnos ajenos | Toda consulta de escritura filtra por `user_id` de la sesión, nunca por un id del cliente solamente. |
| SQL injection | Solo sentencias preparadas (`EMULATE_PREPARES = false`); nunca interpolar entrada en SQL. |
| Cambiar de fuente rompe vistas | Firmas de modelos estables, `driver files/db` reversible, criterio de salida "idéntico a `files`". |
| Credenciales filtradas | `.env` fuera del repositorio y de la web; usuario de base con privilegios mínimos. |
| Zona horaria desalineada | Zona fija en PHP y en la conexión; guardar `DATETIME` locales. |

## 5. Primer paso concreto

Fase 0 y fase 1 completas (conexión, migraciones, semilla y prueba de idempotencia) sin tocar ningún modelo: es el
tramo con menor riesgo y desbloquea todo lo demás.
