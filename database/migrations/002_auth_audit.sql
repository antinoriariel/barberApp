-- 002_auth_audit.sql — Fase 4: sesiones en base de datos, recuperación de contraseña y auditoría.

CREATE TABLE sessions (
  id          VARCHAR(128) NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  data        MEDIUMBLOB   NOT NULL,
  updated_at  DATETIME     NOT NULL,
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (
  token_hash  CHAR(64) NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  expires_at  DATETIME NOT NULL,
  KEY idx_resets_user (user_id),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  event       VARCHAR(40)  NOT NULL,
  ip          VARCHAR(45)  NOT NULL,
  detail      VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_event (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
