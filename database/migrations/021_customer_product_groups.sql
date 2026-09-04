CREATE TABLE IF NOT EXISTS customer_product_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(600) DEFAULT NULL,
    badge VARCHAR(80) DEFAULT NULL,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_product_group_category FOREIGN KEY (category_id) REFERENCES customer_categories(id) ON DELETE SET NULL,
    KEY idx_customer_product_groups_visible_sort (visible,sort_order),
    KEY idx_customer_product_groups_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_product_group_variants (
    group_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    variant_label VARCHAR(80) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id,product_id),
    UNIQUE KEY uniq_customer_group_variant_product (product_id),
    CONSTRAINT fk_customer_group_variant_group FOREIGN KEY (group_id) REFERENCES customer_product_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_group_variant_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    KEY idx_customer_group_variant_sort (group_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
