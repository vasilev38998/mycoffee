-- Receipt package variants: distinguish the same product by package size/weight.
-- Beget-compatible conditional ALTERs: no ADD COLUMN IF NOT EXISTS.

SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS receipt_package_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_key VARCHAR(500) NOT NULL,
  package_signature VARCHAR(80) NOT NULL,
  package_quantity DECIMAL(16,4) NOT NULL,
  package_unit VARCHAR(16) NOT NULL,
  ingredient_id INT UNSIGNED NOT NULL,
  quantity_per_item DECIMAL(16,4) NOT NULL,
  auto_apply TINYINT(1) NOT NULL DEFAULT 1,
  usage_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_seen_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_receipt_package_rule_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_receipt_package_variant (product_key(150),package_signature),
  KEY idx_receipt_package_ingredient (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='purchase_receipt_items' AND COLUMN_NAME='package_product_key'),
  'SELECT 1',
  'ALTER TABLE purchase_receipt_items ADD COLUMN package_product_key VARCHAR(500) DEFAULT NULL AFTER normalized_name'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='purchase_receipt_items' AND COLUMN_NAME='detected_package_quantity'),
  'SELECT 1',
  'ALTER TABLE purchase_receipt_items ADD COLUMN detected_package_quantity DECIMAL(16,4) DEFAULT NULL AFTER quantity_per_item'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='purchase_receipt_items' AND COLUMN_NAME='detected_package_unit'),
  'SELECT 1',
  'ALTER TABLE purchase_receipt_items ADD COLUMN detected_package_unit VARCHAR(16) DEFAULT NULL AFTER detected_package_quantity'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='purchase_receipt_items' AND COLUMN_NAME='package_signature'),
  'SELECT 1',
  'ALTER TABLE purchase_receipt_items ADD COLUMN package_signature VARCHAR(80) DEFAULT NULL AFTER detected_package_unit'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='purchase_receipt_items' AND COLUMN_NAME='package_warning'),
  'SELECT 1',
  'ALTER TABLE purchase_receipt_items ADD COLUMN package_warning VARCHAR(255) DEFAULT NULL AFTER package_signature'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
