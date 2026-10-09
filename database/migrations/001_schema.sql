-- 001_schema.sql — Esquema inicial de Navaja & Co. (ver docs/plan-base-de-datos.md, sección 2).

CREATE TABLE shop (
  id          TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  name        VARCHAR(80)  NOT NULL,
  address     VARCHAR(190) NOT NULL,
  hours       VARCHAR(80)  NOT NULL,
  phone       VARCHAR(30)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dni         CHAR(8)      NOT NULL UNIQUE,
  name        VARCHAR(60)  NOT NULL,
  full_name   VARCHAR(120) NOT NULL,
  email       VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(30)  NOT NULL UNIQUE,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(190) NOT NULL,
  minutes     SMALLINT UNSIGNED NOT NULL,
  price       INT UNSIGNED NOT NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  SMALLINT     NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE barbers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(30)  NOT NULL UNIQUE,
  name        VARCHAR(80)  NOT NULL,
  role        VARCHAR(80)  NOT NULL,
  years       TINYINT UNSIGNED NOT NULL,
  rating      DECIMAL(2,1) NOT NULL,
  tone        CHAR(7)      NOT NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE opening_hours (
  weekday     TINYINT UNSIGNED PRIMARY KEY,
  is_open     TINYINT(1) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE slot_templates (
  slot_time   TIME PRIMARY KEY
) ENGINE=InnoDB;

CREATE TABLE closures (
  day         DATE PRIMARY KEY,
  reason      VARCHAR(120) NULL
) ENGINE=InnoDB;

CREATE TABLE appointments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  barber_id   INT UNSIGNED NOT NULL,
  service_id  INT UNSIGNED NOT NULL,
  starts_at   DATETIME     NOT NULL,
  ends_at     DATETIME     NOT NULL,
  price       INT UNSIGNED NOT NULL,
  status      ENUM('booked','cancelled','done','no_show') NOT NULL DEFAULT 'booked',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  active_slot DATETIME GENERATED ALWAYS AS (IF(status = 'booked', starts_at, NULL)) STORED,
  UNIQUE KEY uq_barber_slot (barber_id, active_slot),
  KEY idx_user_start (user_id, starts_at),
  KEY idx_barber_start (barber_id, starts_at),
  CONSTRAINT fk_ap_user    FOREIGN KEY (user_id)    REFERENCES users(id),
  CONSTRAINT fk_ap_barber  FOREIGN KEY (barber_id)  REFERENCES barbers(id),
  CONSTRAINT fk_ap_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  key_hash    CHAR(64) PRIMARY KEY,
  attempts    SMALLINT UNSIGNED NOT NULL,
  expires_at  DATETIME NOT NULL
) ENGINE=InnoDB;
