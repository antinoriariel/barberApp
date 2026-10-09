-- 005_shop_hours_text.sql — El horario ahora es un texto de varias líneas (un renglón por franja).
ALTER TABLE shop MODIFY hours VARCHAR(400) NOT NULL;
