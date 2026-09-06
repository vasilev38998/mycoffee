CREATE TABLE IF NOT EXISTS customer_drink_loyalty_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    operation_key VARCHAR(255) NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    source_line_id VARCHAR(190) NOT NULL DEFAULT '',
    product_id INT UNSIGNED DEFAULT NULL,
    stamp_delta SMALLINT NOT NULL DEFAULT 0,
    reward_delta SMALLINT NOT NULL DEFAULT 0,
    reward_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_drink_loyalty_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_drink_loyalty_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_customer_drink_loyalty_operation (operation_key),
    KEY idx_customer_drink_loyalty_customer_created (customer_id,created_at),
    KEY idx_customer_drink_loyalty_source (source_type,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
