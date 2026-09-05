-- Customer payment methods and Sber SBP payment transactions.
SET @db := DATABASE();

ALTER TABLE online_orders MODIFY status ENUM('awaiting_payment','new','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'new';

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='online_orders' AND COLUMN_NAME='payment_method'),
  'SELECT 1',
  'ALTER TABLE online_orders ADD COLUMN payment_method VARCHAR(32) DEFAULT NULL AFTER payment_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='online_orders' AND COLUMN_NAME='payment_provider'),
  'SELECT 1',
  'ALTER TABLE online_orders ADD COLUMN payment_provider VARCHAR(32) DEFAULT NULL AFTER payment_method'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS customer_payment_connections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(32) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  test_mode TINYINT(1) NOT NULL DEFAULT 1,
  merchant_login VARCHAR(190) DEFAULT NULL,
  secret_ciphertext TEXT DEFAULT NULL,
  secret_iv VARCHAR(255) DEFAULT NULL,
  secret_tag VARCHAR(255) DEFAULT NULL,
  api_base_url VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_customer_payment_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  method VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  provider_order_id VARCHAR(190) DEFAULT NULL,
  provider_order_number VARCHAR(190) DEFAULT NULL,
  payment_url TEXT DEFAULT NULL,
  sbp_payload TEXT DEFAULT NULL,
  provider_response LONGTEXT DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  failed_at DATETIME DEFAULT NULL,
  refunded_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_customer_payment_order FOREIGN KEY (order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_customer_payment_order_provider (order_id,provider),
  KEY idx_customer_payment_provider_order (provider,provider_order_id),
  KEY idx_customer_payment_status (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
