-- 011_barber_hours.sql — Horario semanal de cada barbero (se carga en /admin/barberos).
-- Una fila por barbero y día de la semana (0 = domingo, como date('w')). Si un día no tiene fila, el barbero
-- atiende el horario completo del local; con is_working = 0 ese día no toma turnos.
CREATE TABLE barber_hours (
  barber_id   INT UNSIGNED     NOT NULL,
  weekday     TINYINT UNSIGNED NOT NULL,
  is_working  TINYINT(1)       NOT NULL DEFAULT 1,
  start_time  TIME             NOT NULL,
  end_time    TIME             NOT NULL,
  PRIMARY KEY (barber_id, weekday),
  CONSTRAINT fk_bh_barber FOREIGN KEY (barber_id) REFERENCES barbers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
