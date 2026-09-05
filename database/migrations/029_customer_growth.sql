CREATE TABLE IF NOT EXISTS customer_favorites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id BIGINT UNSIGNED NOT NULL,
  product_key VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_favorite (customer_id,product_key),
  KEY idx_customer_favorites_customer (customer_id,created_at),
  CONSTRAINT fk_customer_favorites_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
