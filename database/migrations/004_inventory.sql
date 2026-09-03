CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT UNSIGNED NOT NULL,
    movement_type ENUM('purchase','sale','return','writeoff','inventory_adjustment','manual') NOT NULL,
    quantity_delta DECIMAL(14,3) NOT NULL,
    reference_type VARCHAR(40) DEFAULT NULL,
    reference_id BIGINT UNSIGNED DEFAULT NULL,
    occurred_at DATETIME NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_movement_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE RESTRICT,
    UNIQUE KEY uniq_inventory_reference (ingredient_id, movement_type, reference_type, reference_id),
    INDEX idx_inventory_movement_date (occurred_at),
    INDEX idx_inventory_movement_ingredient_date (ingredient_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_counts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    counted_at DATETIME NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_count_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_count_id BIGINT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    expected_quantity DECIMAL(14,3) NOT NULL,
    actual_quantity DECIMAL(14,3) NOT NULL,
    difference_quantity DECIMAL(14,3) NOT NULL,
    CONSTRAINT fk_count_item_count FOREIGN KEY (inventory_count_id) REFERENCES inventory_counts(id) ON DELETE CASCADE,
    CONSTRAINT fk_count_item_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE RESTRICT,
    UNIQUE KEY uniq_count_ingredient (inventory_count_id, ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
