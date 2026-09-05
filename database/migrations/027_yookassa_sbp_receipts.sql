-- YooKassa SBP + receipts: customer email is required for fiscal receipt delivery.
SET @db := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='customer_accounts' AND COLUMN_NAME='email'),
  'SELECT 1',
  'ALTER TABLE customer_accounts ADD COLUMN email VARCHAR(254) DEFAULT NULL AFTER name'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='customer_accounts' AND INDEX_NAME='idx_customer_accounts_email'),
  'SELECT 1',
  'ALTER TABLE customer_accounts ADD KEY idx_customer_accounts_email (email)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
