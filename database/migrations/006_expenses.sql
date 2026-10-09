-- 006_expenses.sql — Egresos del local (alquiler, insumos, sueldos…) para comparar con los ingresos de los turnos.
CREATE TABLE expenses (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  spent_on    DATE         NOT NULL,
  category    VARCHAR(40)  NOT NULL,
  description VARCHAR(120) NOT NULL DEFAULT '',
  amount      INT UNSIGNED NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_spent_on (spent_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
