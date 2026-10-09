-- 003_admin_role.sql — Rol de administrador (panel /admin). Se asigna con `php bin/make-admin.php <dni>`.

ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
