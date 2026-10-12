<?php
/**
 * bin/seed.php — Carga en la base los datos de data/*.php (catálogo, equipo, usuarios y turnos de ejemplo).
 * Uso: php bin/seed.php
 *
 * Idempotente: el catálogo se actualiza por `slug`, los usuarios por DNI, y los turnos de un usuario
 * solo se siembran si todavía no tiene ninguno. Usa el usuario de migraciones.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Data;
use App\Core\Database;
use App\Support\Password;

$pdo = Database::connect(Config::get('database.migrate_user'), Config::get('database.migrate_password'));

/** Ejecuta una sentencia preparada. */
$run = static function (string $sql, array $params = []) use ($pdo): void {
    $pdo->prepare($sql)->execute($params);
};
/** Fila (id, price, minutes) de un servicio por nombre; para barberos price y minutes quedan en 0. */
$find = static function (string $table, string $name) use ($pdo): array {
    $cols = $table === 'services' ? 'id, price, minutes' : 'id, 0 AS price, 0 AS minutes';
    $st = $pdo->prepare("SELECT {$cols} FROM {$table} WHERE name = ?");
    $st->execute([$name]);
    return $st->fetch() ?: throw new RuntimeException("No existe '{$name}' en {$table}.");
};

$pdo->beginTransaction();

$shop = Data::get('shop');
$run('INSERT INTO shop (id, name, address, hours, phone) VALUES (1, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE name = VALUES(name), address = VALUES(address), hours = VALUES(hours), phone = VALUES(phone)',
    [$shop['name'], $shop['address'], $shop['hours'], $shop['phone']]);

foreach (Data::get('services') as $i => $s) {
    $run('INSERT INTO services (slug, name, description, minutes, price, sort_order) VALUES (?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                  minutes = VALUES(minutes), price = VALUES(price), sort_order = VALUES(sort_order)',
        [$s['id'], $s['name'], $s['desc'], $s['min'], $s['price'], $i]);
}

foreach (Data::get('barbers') as $b) {
    $run('INSERT INTO barbers (slug, name, role, years, rating, tone, photo) VALUES (?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role), years = VALUES(years),
                                  rating = VALUES(rating), tone = VALUES(tone), photo = VALUES(photo)',
        [$b['id'], $b['name'], $b['role'], $b['years'], str_replace(',', '.', $b['rating']), $b['tone'], $b['photo'] ?? null]);
}

// Productos: INSERT IGNORE porque el administrador los edita en /admin/servicios (no se pisan precios ni nombres).
foreach (Data::get('products') as $i => $p) {
    $run('INSERT IGNORE INTO products (slug, name, description, price, sort_order) VALUES (?, ?, ?, ?, ?)',
        [$p['id'], $p['name'], $p['description'], $p['price'], $i]);
}

$schedule = Data::get('schedule');
for ($w = 0; $w <= 6; $w++) {
    // INSERT IGNORE: solo completa los días que faltan; los días abiertos/cerrados se eligen en /admin/barberos.
    $run('INSERT IGNORE INTO opening_hours (weekday, is_open) VALUES (?, ?)',
        [$w, in_array($w, $schedule['closed_weekdays'], true) ? 0 : 1]);
}
foreach ($schedule['slots'] as [$time]) {
    $run('INSERT IGNORE INTO slot_templates (slot_time) VALUES (?)', [$time . ':00']);
}

// Usuarios y sus turnos de ejemplo: el historial de data/appointments.php, visitas "de relleno" hasta
// completar `visits`, y el próximo turno (el primer jueves futuro a las 18:30).
$appointments = Data::get('appointments');
$monthNum = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6,
             'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

$barberNames = array_column(Data::get('barbers'), 'name');
$k = -1;
foreach (Data::get('users') as $dni => $u) {
    $k++;
    $dni = (string) $dni;
    // La contraseña de demostración solo se asigna si la cuenta aún no tiene una (no pisa cambios reales).
    // Rol: 'admin' => true entra al panel; 'barber' => slug vincula la cuenta con su ficha del equipo.
    $barberId = null;
    if (isset($u['barber'])) {
        $st = $pdo->prepare('SELECT id FROM barbers WHERE slug = ?');
        $st->execute([$u['barber']]);
        $barberId = $st->fetchColumn() ?: throw new RuntimeException("No existe el barbero '{$u['barber']}'.");
    }
    $run('INSERT INTO users (dni, name, full_name, phone, password_hash, is_admin, barber_id) VALUES (?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE name = VALUES(name), full_name = VALUES(full_name), phone = VALUES(phone),
                                  is_admin = VALUES(is_admin), barber_id = VALUES(barber_id),
                                  password_hash = COALESCE(password_hash, VALUES(password_hash))',
        [$dni, $u['name'], $u['full'], $u['phone'] ?? null, Password::hash((string) Config::get('auth.demo_password')),
         empty($u['admin']) ? 0 : 1, $barberId]);
    $st = $pdo->prepare('SELECT id FROM users WHERE dni = ?');
    $st->execute([$dni]);
    $uid = (int) $st->fetchColumn();

    // El personal (admin / barbero) no tiene historial ni turnos de ejemplo.
    if (!empty($u['admin']) || isset($u['barber'])) {
        continue;
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE user_id = ?');
    $st->execute([$uid]);
    if ((int) $st->fetchColumn() > 0) {
        continue;
    }

    /**
     * Inserta un turno de ejemplo del cliente $uid: el fin se calcula con la duración del servicio y el precio es el
     * del catálogo salvo que se pase $price (precio histórico). También agrega su fila en appointment_services.
     */
    $insert = static function (array $svc, array $barber, string $start, string $status, ?int $price = null) use ($run, $uid): void {
        $end = date('Y-m-d H:i:s', strtotime($start) + $svc['minutes'] * 60);
        $run('INSERT INTO appointments (user_id, barber_id, service_id, starts_at, ends_at, price, status)
              VALUES (?, ?, ?, ?, ?, ?, ?)', [$uid, $barber['id'], $svc['id'], $start, $end, $price ?? $svc['price'], $status]);
        // Detalle de servicios del turno (migración 008); los de ejemplo tienen uno solo.
        $run('INSERT IGNORE INTO appointment_services (appointment_id, service_id, price) VALUES (LAST_INSERT_ID(), ?, ?)',
            [$svc['id'], $price ?? $svc['price']]);
    };

    $made = 0;
    foreach ($appointments['history'] as $h) {
        [$day, $mon] = explode(' ', $h['date']);
        $date = sprintf('%d-%02d-%02d 11:00:00', (int) date('Y'), $monthNum[$mon], (int) $day);
        $insert($find('services', $h['service']), $find('barbers', $h['barber']), $date, 'done', $h['price']);
        $made++;
    }
    $filler = $find('services', 'Corte clásico');
    $barber = $find('barbers', $appointments['history'][0]['barber']);
    for ($n = 1; $made < $u['visits']; $n++, $made++) {
        $insert($filler, $barber, date('Y-m-d 15:00:00', strtotime("2026-07-15 -{$n} week")), 'done');
    }

    $next = $appointments['next'];
    $when = new DateTime('next thursday');
    $when->setTime(18, 30);
    // Cada usuario con un barbero distinto para no pisarse el mismo horario.
    $nextBarber = $barberNames[(array_search($next['barber'], $barberNames, true) + $k) % count($barberNames)];
    $insert($find('services', $next['service']), $find('barbers', $nextBarber), $when->format('Y-m-d H:i:s'), 'booked');
}

$pdo->commit();
echo "Semilla aplicada. Cuentas de demostración: DNI " . implode(', ', array_keys(Data::get('users')))
    . " con la contraseña de config auth.demo_password.\n";
