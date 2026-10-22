-- 015_superuser.sql — Superusuario, rastreo de dispositivos y bloqueos.
-- superuser: una sola fila (id = 1) con la contraseña de la cuenta "superuser" (se fija con bin/superuser.php).
--   session_version sube al cambiar la contraseña: invalida las sesiones de superusuario abiertas.
-- user_devices: cada navegador (cookie navaja_dev, guardada como sha256) desde el que entró cada cuenta, con su
--   última IP, user agent y última conexión.
-- ip_bans / device_bans: la app responde 403 a esas IP y a esos navegadores ("Permaban").
-- audit_log.user_agent: desde qué navegador se hizo cada acción.

CREATE TABLE superuser (
  id              TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  password_hash   VARCHAR(255)     NOT NULL,
  session_version INT UNSIGNED     NOT NULL DEFAULT 1,
  updated_at      DATETIME         NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_devices (
  user_id       INT UNSIGNED NOT NULL,
  device_hash   CHAR(64)     NOT NULL,
  ip            VARCHAR(45)  NOT NULL,
  user_agent    VARCHAR(255) NOT NULL DEFAULT '',
  first_seen_at DATETIME     NOT NULL,
  last_seen_at  DATETIME     NOT NULL,
  PRIMARY KEY (user_id, device_hash),
  KEY idx_devices_hash (device_hash),
  KEY idx_devices_seen (user_id, last_seen_at),
  CONSTRAINT fk_ud_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ip_bans (
  ip          VARCHAR(45)  NOT NULL PRIMARY KEY,
  reason      VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE device_bans (
  device_hash CHAR(64)     NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  user_agent  VARCHAR(255) NOT NULL DEFAULT '',
  reason      VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE audit_log ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip;
