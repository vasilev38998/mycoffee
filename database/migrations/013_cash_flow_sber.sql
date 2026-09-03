CREATE TABLE IF NOT EXISTS cash_flow_accounts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL,
 account_type ENUM('cash','bank','acquiring','owner','other') NOT NULL DEFAULT 'other',
 provider VARCHAR(80) DEFAULT NULL,
 opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_cashflow_account_name (name),
 KEY idx_cashflow_account_type (account_type,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_flow_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 occurred_at DATETIME NOT NULL,
 account_id INT UNSIGNED NOT NULL,
 direction ENUM('in','out') NOT NULL,
 entry_type ENUM('sale','refund','expense','purchase','transfer','owner_in','owner_out','fee','other') NOT NULL DEFAULT 'other',
 amount DECIMAL(14,2) NOT NULL,
 counter_account_id INT UNSIGNED DEFAULT NULL,
 source_type VARCHAR(80) DEFAULT NULL,
 source_id VARCHAR(190) DEFAULT NULL,
 category VARCHAR(120) DEFAULT NULL,
 description VARCHAR(255) DEFAULT NULL,
 created_by INT UNSIGNED DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_cashflow_source (source_type,source_id,account_id,direction),
 KEY idx_cashflow_date (occurred_at),
 KEY idx_cashflow_account_date (account_id,occurred_at),
 KEY idx_cashflow_type_date (entry_type,occurred_at),
 CONSTRAINT fk_cashflow_account FOREIGN KEY (account_id) REFERENCES cash_flow_accounts(id) ON DELETE CASCADE,
 CONSTRAINT fk_cashflow_counter_account FOREIGN KEY (counter_account_id) REFERENCES cash_flow_accounts(id) ON DELETE SET NULL,
 CONSTRAINT fk_cashflow_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cash_flow_accounts(name,account_type,provider,opening_balance,active) VALUES
('Касса Эвотор','cash','Evotor',0,1),
('Сбер Эквайринг','acquiring','Sberbank',0,1),
('Сбер Расчётный счёт','bank','Sberbank',0,1),
('Владелец','owner',NULL,0,1);

INSERT INTO app_settings(setting_key,setting_value) VALUES
('sber_acquiring_enabled','1'),
('sber_acquiring_fee_pct','0'),
('cashflow_runway_warning_days','14'),
('cashflow_runway_critical_days','7')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO system_meta(meta_key,meta_value) VALUES('schema_version','13')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
