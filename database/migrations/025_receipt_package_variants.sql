-- Receipt package variants: distinguish the same product by package size/weight.
-- Beget-compatible conditional ALTERs: no ADD COLUMN IF NOT EXISTS.

SET @db := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='receipt_item_rules' AND COLUMN_NAME='package_quantity'),
  'SELECT 1',
  'ALTER TABLE receipt_item_rules ADD COLUMN package_quantity DECIMAL(16,4) DEFAULT NULL AFTER quantity_per_item'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='receipt_item_rules' AND COLUMN_NAME='package_unit'),
  'SELECT 1',
  'ALTER TABLE receipt_item_rules ADD COLUMN package_unit VARCHAR(16) DEFAULT NULL AFTER package_quantity'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='receipt_item_rules' AND COLUMN_NAME='package_signature'),
  'SELECT 1',
  'ALTER TABLE receipt_item_rules ADD COLUMN package_signature VARCHAR(80) DEFAULT NULL AFTER package_unit'
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
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='receipt_item_rules' AND INDEX_NAME='idx_receipt_rule_package'),
  'SELECT 1',
  'ALTER TABLE receipt_item_rules ADD KEY idx_receipt_rule_package (normalized_name(120),package_signature)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
