CREATE TABLE IF NOT EXISTS suppliers (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 contact_name VARCHAR(160) DEFAULT NULL,
 phone VARCHAR(80) DEFAULT NULL,
 email VARCHAR(190) DEFAULT NULL,
 notes VARCHAR(500) DEFAULT NULL,
 lead_time_days INT UNSIGNED NOT NULL DEFAULT 1,
 min_order_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_supplier_name (name),
 KEY idx_supplier_active (active,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_ingredients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 ingredient_id INT UNSIGNED NOT NULL,
 supplier_sku VARCHAR(120) DEFAULT NULL,
 pack_quantity DECIMAL(12,3) DEFAULT NULL,
 last_price DECIMAL(12,2) DEFAULT NULL,
 preferred TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_supplier_ingredient (supplier_id,ingredient_id),
 KEY idx_supplier_ingredient_ingredient (ingredient_id),
 CONSTRAINT fk_supplier_ingredient_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
 CONSTRAINT fk_supplier_ingredient_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @kapouch_supplier_id_exists := (
 SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchases' AND COLUMN_NAME='supplier_id'
);
SET @kapouch_supplier_id_sql := IF(
 @kapouch_supplier_id_exists=0,
 'ALTER TABLE purchases ADD COLUMN supplier_id INT UNSIGNED NULL AFTER supplier, ADD KEY idx_purchases_supplier_id (supplier_id), ADD CONSTRAINT fk_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL',
 'SELECT 1'
);
PREPARE kapouch_supplier_id_stmt FROM @kapouch_supplier_id_sql;
EXECUTE kapouch_supplier_id_stmt;
DEALLOCATE PREPARE kapouch_supplier_id_stmt;

INSERT IGNORE INTO suppliers(name)
SELECT DISTINCT TRIM(supplier) FROM purchases
WHERE supplier IS NOT NULL AND TRIM(supplier)<>'';

UPDATE purchases p
JOIN suppliers s ON s.name=TRIM(p.supplier)
SET p.supplier_id=s.id
WHERE p.supplier_id IS NULL AND p.supplier IS NOT NULL AND TRIM(p.supplier)<>'';

INSERT INTO app_settings(setting_key,setting_value) VALUES
('purchase_price_warning_pct','10'),
('purchase_price_critical_pct','20')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO system_meta(meta_key,meta_value) VALUES('schema_version','11')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
