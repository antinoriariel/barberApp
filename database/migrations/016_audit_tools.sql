-- 016_audit_tools.sql — Herramientas de auditoría del superusuario.
-- ip_bans.expires_at: bloqueo temporal (NULL = permanente). hits / last_hit_at: cuántas veces la app rechazó a esa IP
-- o a ese navegador y cuándo fue la última (sirve para ver si siguen intentando).
-- Índices de audit_log para filtrar por IP, por fecha y por evento + fecha (registro y panel de seguridad).

ALTER TABLE ip_bans ADD COLUMN expires_at DATETIME NULL AFTER created_at;
ALTER TABLE ip_bans ADD COLUMN hits INT UNSIGNED NOT NULL DEFAULT 0 AFTER expires_at;
ALTER TABLE ip_bans ADD COLUMN last_hit_at DATETIME NULL AFTER hits;
ALTER TABLE device_bans ADD COLUMN hits INT UNSIGNED NOT NULL DEFAULT 0 AFTER created_at;
ALTER TABLE device_bans ADD COLUMN last_hit_at DATETIME NULL AFTER hits;

ALTER TABLE audit_log ADD KEY idx_audit_ip (ip, created_at);
ALTER TABLE audit_log ADD KEY idx_audit_created (created_at);
