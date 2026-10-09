-- 002_game_rewards.sql — Promociones ganadas en los minijuegos de la pantalla de inicio.
-- Un cliente puede ganar como máximo un premio por día (lo valida Models\GameReward).

CREATE TABLE game_rewards (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  game        VARCHAR(20)  NOT NULL,
  code        CHAR(10)     NOT NULL UNIQUE,
  label       VARCHAR(120) NOT NULL,
  score       SMALLINT UNSIGNED NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME     NOT NULL,
  redeemed_at DATETIME     NULL,
  KEY idx_user_created (user_id, created_at),
  CONSTRAINT fk_gr_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
