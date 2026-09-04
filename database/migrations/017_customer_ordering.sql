CREATE TABLE IF NOT EXISTS customer_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(40) NOT NULL,
    name VARCHAR(160) DEFAULT NULL,
    loyalty_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customer_accounts_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_loyalty_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    operation_type ENUM('earn','spend','adjust') NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_loyalty_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_loyalty_order FOREIGN KEY (order_id) REFERENCES online_orders(id) ON DELETE SET NULL,
    KEY idx_customer_loyalty_customer_created (customer_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_order_access (
    order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    customer_id BIGINT UNSIGNED DEFAULT NULL,
    tracking_token CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_order_access_order FOREIGN KEY (order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_order_access_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_customer_order_tracking_token (tracking_token),
    KEY idx_customer_order_access_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
