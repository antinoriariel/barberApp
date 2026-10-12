-- 017_ui_usage.sql — Uso de la interfaz para el mapa de calor del superusuario (/su/uso; Models\UiUsage).
-- Lo escribe POST /api/uso con lotes que arma public/assets/js/track.js (ver docs/seguridad.md, "Uso de la app").
--
-- ui_clicks: un click/tap por fila. x en milésimos del ancho de la app (0-1000). y depende de la capa:
--   'page'  = px desde el borde superior de la pantalla, escalados a un ancho de referencia de 400 px;
--   'fixed' = milésimos del alto de la ventana (barra superior, barra inferior, diálogos, resumen de la reserva).
--   comp = código del componente (data-track, catálogo en config/tracking.php); item = cuál de la lista (servicio,
--   barbero…); dest = a dónde lleva (ruta o #pantalla, nunca la query). bx/by/bw/bh = caja del componente en las
--   mismas unidades, para dibujar el plano debajo del calor. vw = ancho de la ventana (celular o PC).
-- ui_moves: presencia del mouse (solo PC), ya agregada en celdas de 25 milésimos x 20 px por día y por usuario:
--   cada muestra son ~250 ms con el mouse moviéndose en esa celda.
-- ui_views: veces que cada usuario abrió cada pantalla por día (para el % de clicks sobre visitas).
-- Todas se borran con la cuenta (CASCADE) y el superusuario puede vaciar lo viejo desde /su/uso.

CREATE TABLE ui_clicks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  screen      VARCHAR(20)  NOT NULL,
  layer       ENUM('page','fixed') NOT NULL,
  device      ENUM('touch','mouse','pen') NOT NULL,
  comp        VARCHAR(60)  NOT NULL,
  item        VARCHAR(60)  NOT NULL DEFAULT '',
  dest        VARCHAR(120) NOT NULL DEFAULT '',
  x           SMALLINT UNSIGNED NOT NULL,
  y           SMALLINT UNSIGNED NOT NULL,
  bx          SMALLINT UNSIGNED NULL,
  `by`        SMALLINT UNSIGNED NULL,
  bw          SMALLINT UNSIGNED NULL,
  bh          SMALLINT UNSIGNED NULL,
  vw          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  KEY idx_uic_screen (screen, layer, created_at),
  KEY idx_uic_created (created_at),
  CONSTRAINT fk_uic_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ui_moves (
  day         DATE         NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  screen      VARCHAR(20)  NOT NULL,
  layer       ENUM('page','fixed') NOT NULL,
  gx          SMALLINT UNSIGNED NOT NULL,
  gy          SMALLINT UNSIGNED NOT NULL,
  samples     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, screen, layer, gx, gy, user_id),
  KEY idx_uim_user (user_id),
  CONSTRAINT fk_uim_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ui_views (
  day         DATE         NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  screen      VARCHAR(20)  NOT NULL,
  views       INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, screen, user_id),
  KEY idx_uiv_user (user_id),
  CONSTRAINT fk_uiv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
