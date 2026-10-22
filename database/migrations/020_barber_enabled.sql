-- 020_barber_enabled.sql — Habilitar / deshabilitar un barbero desde /admin/barberos.
-- enabled: 0 = no figura en el equipo de la app ni toma turnos nuevos (los ya reservados se conservan), pero sigue en el
-- panel con su horario y sus estadísticas. Es independiente de active (ficha retirada o cuenta suspendida).

ALTER TABLE barbers ADD COLUMN IF NOT EXISTS enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER active;
