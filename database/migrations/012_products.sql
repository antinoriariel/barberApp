-- 012_products.sql — Productos a la venta en el local (cera, gel…): pasan de data/products.php a la base para que
-- el administrador los agregue y les cambie el precio desde /admin/servicios. No se borran, se ocultan (active = 0).
CREATE TABLE products (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(30)  NOT NULL UNIQUE,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(190) NOT NULL,
  price       INT UNSIGNED NOT NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  SMALLINT     NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
