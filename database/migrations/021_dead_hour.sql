-- 021_dead_hour.sql — "Hora muerta": descuento por tiempo limitado que el administrador activa desde
-- /admin/hora-muerta cuando el local está sin clientes. Mientras dura, los clientes lo ven con una cuenta regresiva
-- y los turnos de ese mismo día que se reservan en ese lapso se guardan con el descuento aplicado.
-- ended_at: se cortó antes de tiempo con "Terminar ahora" (NULL = corrió hasta ends_at).

CREATE TABLE IF NOT EXISTS dead_hours (
  id         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  percent    TINYINT UNSIGNED NOT NULL,
  starts_at  DATETIME         NOT NULL,
  ends_at    DATETIME         NOT NULL,
  ended_at   DATETIME         NULL,
  created_by INT UNSIGNED     NULL,
  created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ends_at (ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Porcentaje de descuento con el que se reservó el turno (0 = precio de lista). appointments.price ya lo trae aplicado.
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS discount_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER price;
