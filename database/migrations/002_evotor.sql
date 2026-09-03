CREATE TABLE IF NOT EXISTS evotor_connections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id VARCHAR(200) NOT NULL,
    store_name VARCHAR(190) DEFAULT NULL,
    token_ciphertext TEXT NOT NULL,
    token_iv VARCHAR(64) NOT NULL,
    token_tag VARCHAR(64) NOT NULL,
    last_products_sync_ms BIGINT UNSIGNED DEFAULT NULL,
    last_documents_sync_ms BIGINT UNSIGNED DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_evotor_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evotor_products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    evotor_product_id VARCHAR(200) NOT NULL,
    local_product_id INT UNSIGNED DEFAULT NULL,
    code VARCHAR(100) DEFAULT NULL,
    name VARCHAR(190) NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantity DECIMAL(14,3) DEFAULT NULL,
    measure_name VARCHAR(80) DEFAULT NULL,
    updated_at_evotor VARCHAR(64) DEFAULT NULL,
    raw_json MEDIUMTEXT NOT NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_evotor_product_connection FOREIGN KEY (connection_id) REFERENCES evotor_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_evotor_product_local FOREIGN KEY (local_product_id) REFERENCES products(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_evotor_product (connection_id, evotor_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evotor_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    evotor_document_id VARCHAR(200) NOT NULL,
    document_type VARCHAR(40) NOT NULL,
    document_number INT DEFAULT NULL,
    device_id VARCHAR(200) DEFAULT NULL,
    session_id VARCHAR(200) DEFAULT NULL,
    session_number INT DEFAULT NULL,
    close_date DATETIME DEFAULT NULL,
    result_sum DECIMAL(12,2) DEFAULT NULL,
    imported_sale_id INT UNSIGNED DEFAULT NULL,
    raw_json MEDIUMTEXT NOT NULL,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_evotor_document_connection FOREIGN KEY (connection_id) REFERENCES evotor_connections(id) ON DELETE CASCADE,
    CONSTRAINT fk_evotor_document_sale FOREIGN KEY (imported_sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_evotor_document (connection_id, evotor_document_id),
    INDEX idx_evotor_documents_type_date (document_type, close_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evotor_sync_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED DEFAULT NULL,
    sync_type ENUM('products','documents','full') NOT NULL,
    status ENUM('success','error') NOT NULL,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    message TEXT DEFAULT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NOT NULL,
    CONSTRAINT fk_evotor_log_connection FOREIGN KEY (connection_id) REFERENCES evotor_connections(id) ON DELETE SET NULL,
    INDEX idx_evotor_log_finished (finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
