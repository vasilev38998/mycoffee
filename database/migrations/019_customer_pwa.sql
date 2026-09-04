CREATE TABLE IF NOT EXISTS customer_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    icon VARCHAR(16) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customer_categories_slug (slug),
    KEY idx_customer_categories_active_sort (active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_product_settings (
    product_id INT UNSIGNED NOT NULL PRIMARY KEY,
    category_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(600) DEFAULT NULL,
    badge VARCHAR(80) DEFAULT NULL,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_product_settings_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_product_settings_category FOREIGN KEY (category_id) REFERENCES customer_categories(id) ON DELETE SET NULL,
    KEY idx_customer_product_settings_category (category_id),
    KEY idx_customer_product_settings_visible_sort (visible,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO customer_categories(name,slug,icon,sort_order,active) VALUES
('Кофе','coffee','☕',10,1),
('Чай','tea','🫖',20,1),
('Лимонады','lemonades','🍋',30,1),
('Молочные коктейли','milkshakes','🥤',40,1),
('Выпечка','bakery','🥐',50,1),
('Другое','other','✨',100,1)
ON DUPLICATE KEY UPDATE name=VALUES(name),icon=VALUES(icon),sort_order=VALUES(sort_order),active=VALUES(active);
