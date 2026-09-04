CREATE TABLE IF NOT EXISTS purchase_receipts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fingerprint CHAR(64) NOT NULL,
  qr_raw TEXT DEFAULT NULL,
  fiscal_fn VARCHAR(32) DEFAULT NULL,
  fiscal_fd VARCHAR(32) DEFAULT NULL,
  fiscal_fp VARCHAR(32) DEFAULT NULL,
  receipt_at DATETIME DEFAULT NULL,
  seller_name VARCHAR(255) DEFAULT NULL,
  seller_inn VARCHAR(20) DEFAULT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('draft','imported','cancelled') NOT NULL DEFAULT 'draft',
  source VARCHAR(40) NOT NULL DEFAULT 'manual_json',
  raw_json LONGTEXT DEFAULT NULL,
  imported_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_purchase_receipt_fingerprint (fingerprint),
  KEY idx_purchase_receipt_status_date (status,receipt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipt_item_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  normalized_name VARCHAR(500) NOT NULL,
  ingredient_id INT UNSIGNED NOT NULL,
  quantity_per_item DECIMAL(16,4) NOT NULL DEFAULT 1,
  auto_apply TINYINT(1) NOT NULL DEFAULT 1,
  usage_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_seen_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_receipt_rule_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_receipt_rule_name (normalized_name(191)),
  KEY idx_receipt_rule_ingredient (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_receipt_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  line_no INT UNSIGNED NOT NULL DEFAULT 0,
  raw_name VARCHAR(500) NOT NULL,
  normalized_name VARCHAR(500) NOT NULL,
  receipt_quantity DECIMAL(14,4) NOT NULL DEFAULT 1,
  unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  included TINYINT(1) NOT NULL DEFAULT 1,
  ingredient_id INT UNSIGNED DEFAULT NULL,
  quantity_per_item DECIMAL(16,4) DEFAULT NULL,
  rule_id BIGINT UNSIGNED DEFAULT NULL,
  purchase_id INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_receipt_item_receipt FOREIGN KEY (receipt_id) REFERENCES purchase_receipts(id) ON DELETE CASCADE,
  CONSTRAINT fk_receipt_item_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE SET NULL,
  CONSTRAINT fk_receipt_item_rule FOREIGN KEY (rule_id) REFERENCES receipt_item_rules(id) ON DELETE SET NULL,
  KEY idx_receipt_item_receipt (receipt_id,line_no),
  KEY idx_receipt_item_ingredient (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipt_data_connections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL DEFAULT 'Источник электронных чеков',
  endpoint_url VARCHAR(1000) DEFAULT NULL,
  token_ciphertext TEXT DEFAULT NULL,
  token_iv VARCHAR(255) DEFAULT NULL,
  token_tag VARCHAR(255) DEFAULT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
