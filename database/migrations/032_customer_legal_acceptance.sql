CREATE TABLE IF NOT EXISTS customer_order_legal_acceptance (
  order_id BIGINT UNSIGNED NOT NULL,
  offer_version VARCHAR(40) NOT NULL,
  offer_hash CHAR(64) NOT NULL,
  accepted_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id),
  KEY idx_customer_order_legal_acceptance_date (accepted_at),
  CONSTRAINT fk_customer_order_legal_acceptance_order FOREIGN KEY (order_id) REFERENCES online_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
