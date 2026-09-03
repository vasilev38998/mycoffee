SET @kapouch_cash_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'evotor_connections'
      AND COLUMN_NAME = 'last_cash_sync_ms'
);
SET @kapouch_cash_column_sql := IF(
    @kapouch_cash_column_exists = 0,
    'ALTER TABLE evotor_connections ADD COLUMN last_cash_sync_ms BIGINT NULL AFTER last_documents_sync_ms',
    'SELECT 1'
);
PREPARE kapouch_cash_column_stmt FROM @kapouch_cash_column_sql;
EXECUTE kapouch_cash_column_stmt;
DEALLOCATE PREPARE kapouch_cash_column_stmt;

CREATE TABLE IF NOT EXISTS cash_register_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id INT UNSIGNED NOT NULL,
    evotor_document_id VARCHAR(200) NOT NULL,
    document_type VARCHAR(40) NOT NULL,
    occurred_at DATETIME NOT NULL,
    device_id VARCHAR(200) DEFAULT NULL,
    session_id VARCHAR(200) DEFAULT NULL,
    session_number INT DEFAULT NULL,
    document_number VARCHAR(100) DEFAULT NULL,
    cash_delta DECIMAL(14,2) NOT NULL DEFAULT 0,
    cash_sale_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    cash_return_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount DECIMAL(14,2) DEFAULT NULL,
    payment_category_id INT DEFAULT NULL,
    payment_category_name VARCHAR(120) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    counterparty VARCHAR(190) DEFAULT NULL,
    report_cash DECIMAL(14,2) DEFAULT NULL,
    report_cash_in_sum DECIMAL(14,2) DEFAULT NULL,
    report_cash_out_sum DECIMAL(14,2) DEFAULT NULL,
    report_collection DECIMAL(14,2) DEFAULT NULL,
    report_proceeds DECIMAL(14,2) DEFAULT NULL,
    raw_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cash_evotor_document (connection_id,evotor_document_id),
    KEY idx_cash_occurred (occurred_at),
    KEY idx_cash_session (session_id,session_number),
    KEY idx_cash_type (document_type),
    CONSTRAINT fk_cash_connection FOREIGN KEY (connection_id) REFERENCES evotor_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
