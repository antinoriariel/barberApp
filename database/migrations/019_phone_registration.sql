-- 019_phone_registration.sql — Teléfono en lugar de email y un solo registro por navegador.
-- users.phone: celular argentino normalizado (10 dígitos: código de área sin 0 + número sin 15, ver Support\Phone).
--   UNIQUE: dos cuentas no pueden tener el mismo número. NULL = sin teléfono (cuentas viejas o de personal).
--   La columna email queda sin uso (no se borra para no perder datos; se puede quitar en otra migración).
-- device_registrations: cada alta hecha desde /registro, con el navegador (sha256 de la cookie navaja_dev), la IP,
--   el user agent y una copia de DNI, nombre y teléfono (para auditarla aunque la cuenta se borre después).
--   active_device es el hash mientras el registro no fue liberado por el superusuario (released_at NULL): su índice
--   único hace que un navegador no pueda crear una segunda cuenta, aun con dos pedidos en paralelo.
--   Si la cuenta se elimina, user_id pasa a NULL pero el navegador sigue sin poder registrar otra.

ALTER TABLE users ADD COLUMN IF NOT EXISTS phone CHAR(10) NULL AFTER full_name;
ALTER TABLE users ADD UNIQUE KEY IF NOT EXISTS uq_users_phone (phone);

CREATE TABLE IF NOT EXISTS device_registrations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_hash   CHAR(64)     NOT NULL,
  user_id       INT UNSIGNED NULL,
  dni           CHAR(8)      NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  phone         CHAR(10)     NOT NULL,
  ip            VARCHAR(45)  NOT NULL,
  user_agent    VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME     NOT NULL,
  released_at   DATETIME     NULL,
  active_device CHAR(64) GENERATED ALWAYS AS (IF(released_at IS NULL, device_hash, NULL)) STORED,
  UNIQUE KEY uq_reg_active_device (active_device),
  KEY idx_reg_device (device_hash),
  KEY idx_reg_ip (ip, created_at),
  KEY idx_reg_created (created_at),
  CONSTRAINT fk_reg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
