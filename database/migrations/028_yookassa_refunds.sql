-- YooKassa full refunds tracking.
SET @db := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='customer_payments' AND COLUMN_NAME='provider_refund_id'),
  'SELECT 1',
  'ALTER TABLE customer_payments ADD COLUMN provider_refund_id VARCHAR(190) DEFAULT NULL AFTER provider_order_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='customer_payments' AND COLUMN_NAME='refund_status'),
  'SELECT 1',
  'ALTER TABLE customer_payments ADD COLUMN refund_status VARCHAR(32) DEFAULT NULL AFTER provider_refund_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='customer_payments' AND COLUMN_NAME='refunded_amount'),
  'SELECT 1',
  'ALTER TABLE customer_payments ADD COLUMN refunded_amount DECIMAL(12,2) DEFAULT NULL AFTER refund_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='customer_payments' AND COLUMN_NAME='refund_response'),
  'SELECT 1',
  'ALTER TABLE customer_payments ADD COLUMN refund_response LONGTEXT DEFAULT NULL AFTER provider_response'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='customer_payments' AND INDEX_NAME='idx_customer_payment_refund'),
  'SELECT 1',
  'ALTER TABLE customer_payments ADD KEY idx_customer_payment_refund (provider,provider_refund_id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
