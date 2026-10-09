-- 009_queue.sql — Fila por orden de llegada ("tome su número"): el cliente escanea el QR del mostrador y recibe el
-- número siguiente al último entregado ese día. Los números arrancan en 1 cada día.
CREATE TABLE queue_tickets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  day         DATE         NOT NULL,
  number      SMALLINT UNSIGNED NOT NULL,
  status      ENUM('waiting','called','done','no_show','cancelled') NOT NULL DEFAULT 'waiting',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  called_at   DATETIME     NULL,
  UNIQUE KEY uq_queue_number (day, number),   -- dos escaneos simultáneos no pueden recibir el mismo número
  KEY idx_queue_user (user_id, day),
  CONSTRAINT fk_queue_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
