-- 008_appointment_services.sql — Un turno puede incluir varios servicios (ej. corte + cejas).
-- appointments.service_id queda como servicio principal (el primero elegido) y appointments.price/ends_at
-- guardan los totales; acá va el detalle con el precio de cada servicio al momento de reservar.
CREATE TABLE appointment_services (
  appointment_id INT UNSIGNED     NOT NULL,
  service_id     INT UNSIGNED     NOT NULL,
  price          INT UNSIGNED     NOT NULL,
  position       TINYINT UNSIGNED NOT NULL DEFAULT 0, -- orden en que se eligieron
  PRIMARY KEY (appointment_id, service_id),
  KEY idx_as_service (service_id),
  CONSTRAINT fk_as_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
  CONSTRAINT fk_as_service     FOREIGN KEY (service_id)     REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Los turnos existentes tenían un solo servicio: se copian tal cual.
INSERT IGNORE INTO appointment_services (appointment_id, service_id, price, position)
SELECT id, service_id, price, 0 FROM appointments;
