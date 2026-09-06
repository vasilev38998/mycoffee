CREATE TABLE IF NOT EXISTS evotor_customer_scans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    card_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
    device_uuid VARCHAR(200) DEFAULT NULL,
    status ENUM('pending','consumed','expired','cancelled') NOT NULL DEFAULT 'pending',
    scanned_at DATETIME NOT NULL,
    scanned_at_unix BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    expires_at_unix BIGINT UNSIGNED NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    consumed_document_id VARCHAR(200) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_evotor_customer_scan_connection FOREIGN KEY (connection_id) REFERENCES evotor_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_evotor_customer_scan_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    KEY idx_evotor_customer_scan_pending (connection_id,status,scanned_at_unix),
    KEY idx_evotor_customer_scan_customer (customer_id,scanned_at_unix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evotor_customer_sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    evotor_document_id VARCHAR(200) NOT NULL,
    sale_id INT UNSIGNED DEFAULT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    scan_id BIGINT UNSIGNED DEFAULT NULL,
    gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    loyalty_earned DECIMAL(12,2) NOT NULL DEFAULT 0,
    loyalty_spent DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_evotor_customer_sale_connection FOREIGN KEY (connection_id) REFERENCES evotor_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_evotor_customer_sale_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_evotor_customer_sale_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_evotor_customer_sale_scan FOREIGN KEY (scan_id) REFERENCES evotor_customer_scans(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_evotor_customer_sale_document (connection_id,evotor_document_id),
    KEY idx_evotor_customer_sale_customer (customer_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
