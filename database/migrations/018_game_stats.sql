-- 018_game_stats.sql — Tiempo de juego y ranking de los minijuegos (Models\GameSession, Models\GameScore).
--
-- game_sessions: una fila por partida. El servidor la abre en /juegos/iniciar y la cierra en /juegos/fin con SU reloj,
--   así el tiempo jugado nunca lo informa el navegador. `token_hash` es el sha256 del token de un solo uso que recibe el
--   cliente (el token en claro no se guarda). `sig` es un HMAC de los campos cerrados (Services\Integrity): una fila
--   editada a mano en la base deja de cuadrar y se ignora en los totales y el ranking.
-- game_highscores: mejor puntaje de cada usuario en cada juego (de ahí sale el top 6), también firmado.
-- Las filas se borran solas con la cuenta (CASCADE): son estadísticas de juego, no historial del negocio.

CREATE TABLE IF NOT EXISTS game_sessions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  game        VARCHAR(20)  NOT NULL,
  token_hash  CHAR(64)     NOT NULL UNIQUE,
  status      ENUM('playing','done','abandoned','rejected') NOT NULL DEFAULT 'playing',
  started_at  DATETIME     NOT NULL,
  ended_at    DATETIME     NULL,
  seconds     INT UNSIGNED NOT NULL DEFAULT 0,
  score       INT UNSIGNED NOT NULL DEFAULT 0,
  rewarded    TINYINT(1)   NOT NULL DEFAULT 0,
  sig         CHAR(64)     NULL,
  KEY idx_user_game (user_id, game, status),
  KEY idx_user_started (user_id, started_at),
  CONSTRAINT fk_gs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_highscores (
  user_id     INT UNSIGNED NOT NULL,
  game        VARCHAR(20)  NOT NULL,
  score       INT UNSIGNED NOT NULL,
  session_id  INT UNSIGNED NOT NULL,
  achieved_at DATETIME     NOT NULL,
  sig         CHAR(64)     NOT NULL,
  PRIMARY KEY (user_id, game),
  KEY idx_game_rank (game, score, achieved_at),
  CONSTRAINT fk_gh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
