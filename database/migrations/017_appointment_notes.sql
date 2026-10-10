-- 017_appointment_notes.sql — Observación del turno.
-- Si lo atiende un barbero distinto del reservado, el turno pasa a su nombre (así cuenta en sus cortes)
-- y acá queda con quién se había reservado (ej. "Reservado con Lucas").

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS notes VARCHAR(255) NOT NULL DEFAULT '' AFTER status;
