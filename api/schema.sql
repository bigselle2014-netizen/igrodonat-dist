-- Магазин игродоната: товары, пакеты, заказы. MySQL 5.7+/MariaDB, utf8mb4.
-- Деньги храним в копейках (целые), чтобы не было ошибок округления.

CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(64)  NOT NULL UNIQUE,          -- совпадает с хабом /igry/{slug}/
  name        VARCHAR(128) NOT NULL,
  supplier    VARCHAR(32)  NOT NULL,                 -- mock | seagm | ...
  supplier_ref VARCHAR(64) NOT NULL DEFAULT '',      -- id категории у поставщика
  fields_json TEXT         NOT NULL,                 -- [{"name":"user_id","label":"ID игрока","pattern":"^[0-9]{5,12}$"}]
  account_check TINYINT(1) NOT NULL DEFAULT 0,       -- поставщик умеет проверять ID
  active      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(128) NOT NULL,                 -- «500 + 65 алмазов»
  supplier_ref VARCHAR(64) NOT NULL,                 -- id пакета у поставщика
  cost        DECIMAL(12,4) NOT NULL DEFAULT 0,      -- закупка в валюте поставщика
  price_kop   INT UNSIGNED NOT NULL,                 -- наша цена в копейках
  sort        INT          NOT NULL DEFAULT 0,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  KEY (product_id),
  CONSTRAINT fk_pkg_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Статусы: new → paid → supplying → delivered; ошибки: failed (нужен ручной разбор или возврат).
CREATE TABLE IF NOT EXISTS orders (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, -- он же InvId в платёжке
  uuid         CHAR(32)     NOT NULL UNIQUE,              -- ключ доступа покупателя к заказу
  package_id   INT UNSIGNED NOT NULL,
  title        VARCHAR(255) NOT NULL,                     -- снимок названия на момент заказа
  price_kop    INT UNSIGNED NOT NULL,
  account_json TEXT         NOT NULL,
  email        VARCHAR(190) NOT NULL,
  status       VARCHAR(16)  NOT NULL DEFAULT 'new',
  pay_provider VARCHAR(32)  NOT NULL,
  pay_ref      VARCHAR(128) NULL,
  supply_ref   VARCHAR(128) NULL,
  delivery     TEXT         NULL,                         -- коды/ответ поставщика для покупателя
  error        VARCHAR(500) NULL,
  ip           VARCHAR(45)  NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at      DATETIME     NULL,
  delivered_at DATETIME     NULL,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (status, updated_at),
  KEY (ip, created_at),
  CONSTRAINT fk_order_pkg FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_events (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   BIGINT UNSIGNED NOT NULL,
  type       VARCHAR(32)  NOT NULL,
  data       TEXT         NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
