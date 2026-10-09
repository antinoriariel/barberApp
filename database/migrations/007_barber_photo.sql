-- 007_barber_photo.sql — Foto/ilustración opcional del barbero (ruta pública, ej. /img/barberos/nico.webp).
ALTER TABLE barbers ADD COLUMN photo VARCHAR(255) NULL AFTER tone;
