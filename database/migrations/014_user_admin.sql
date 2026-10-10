-- 014_user_admin.sql — Gestión de usuarios desde /admin/usuarios.
-- active: baja lógica (0 = no puede entrar; los turnos y movimientos que la referencian se conservan).
-- panel_access: secciones del panel que ve una cuenta de barbero, separadas por coma (ej. "turnos,fila");
-- NULL o vacío = ninguna. El administrador ve todas sin importar este campo.

ALTER TABLE users ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER barber_id;
ALTER TABLE users ADD COLUMN panel_access VARCHAR(255) NULL AFTER active;
