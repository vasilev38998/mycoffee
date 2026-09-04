CREATE TABLE IF NOT EXISTS customer_modifier_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    min_select TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_select TINYINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_customer_modifier_groups_active_sort (active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_modifier_options (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    modifier_group_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    label VARCHAR(120) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_modifier_option_group FOREIGN KEY (modifier_group_id) REFERENCES customer_modifier_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_modifier_option_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_customer_modifier_group_product (modifier_group_id,product_id),
    KEY idx_customer_modifier_options_active_sort (modifier_group_id,active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_product_modifier_groups (
    product_id INT UNSIGNED NOT NULL,
    modifier_group_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    PRIMARY KEY (product_id,modifier_group_id),
    CONSTRAINT fk_customer_product_modifier_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_product_modifier_group FOREIGN KEY (modifier_group_id) REFERENCES customer_modifier_groups(id) ON DELETE CASCADE,
    KEY idx_customer_product_mod_group (modifier_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_display_group_modifier_groups (
    product_group_id INT UNSIGNED NOT NULL,
    modifier_group_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    PRIMARY KEY (product_group_id,modifier_group_id),
    CONSTRAINT fk_customer_display_modifier_product_group FOREIGN KEY (product_group_id) REFERENCES customer_product_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_display_modifier_group FOREIGN KEY (modifier_group_id) REFERENCES customer_modifier_groups(id) ON DELETE CASCADE,
    KEY idx_customer_display_mod_group (modifier_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_local_product_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='online_order_items' AND COLUMN_NAME='local_product_id');
SET @sql := IF(@has_local_product_id=0,'ALTER TABLE online_order_items ADD COLUMN local_product_id INT UNSIGNED DEFAULT NULL AFTER external_item_id','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_evotor_product_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='online_order_items' AND COLUMN_NAME='evotor_product_id');
SET @sql := IF(@has_evotor_product_id=0,'ALTER TABLE online_order_items ADD COLUMN evotor_product_id VARCHAR(190) DEFAULT NULL AFTER local_product_id','SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
