-- 013_incomes.sql — Ingresos extra del local (cortes cobrados fuera de la agenda, ventas sueltas…), que se suman
-- a los ingresos de los turnos atendidos en el resultado del día y del mes.
CREATE TABLE incomes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  received_on DATE         NOT NULL,
  category    VARCHAR(40)  NOT NULL,
  notes       VARCHAR(255) NOT NULL DEFAULT '',
  amount      INT UNSIGNED NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_received_on (received_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
