-- 010_expense_notes.sql — Observación libre de cada egreso (detalle, proveedor, comprobante…), aparte del concepto.
ALTER TABLE expenses ADD COLUMN notes VARCHAR(255) NOT NULL DEFAULT '' AFTER description;
