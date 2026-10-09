-- 004_barber_role.sql — Rol de barbero: vincula una cuenta (users) con su ficha del equipo (barbers).
-- NULL = la cuenta no es barbero. Se siembra desde data/users.php (clave 'barber' = slug del barbero).

ALTER TABLE users ADD COLUMN barber_id INT UNSIGNED NULL AFTER is_admin;
ALTER TABLE users ADD CONSTRAINT fk_user_barber FOREIGN KEY (barber_id) REFERENCES barbers(id);
