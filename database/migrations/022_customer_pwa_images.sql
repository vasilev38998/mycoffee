SET @has_product_image := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_product_settings' AND COLUMN_NAME='image_path');
SET @sql := IF(@has_product_image=0,'ALTER TABLE customer_product_settings ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER badge','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_group_image := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_product_groups' AND COLUMN_NAME='image_path');
SET @sql := IF(@has_group_image=0,'ALTER TABLE customer_product_groups ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER badge','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
