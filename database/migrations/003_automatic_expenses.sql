CREATE TABLE IF NOT EXISTS automatic_expense_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    category VARCHAR(120) NOT NULL,
    rule_type ENUM('per_shift','monthly_fixed','percent_revenue','percent_card_revenue') NOT NULL,
    amount DECIMAL(12,4) NOT NULL DEFAULT 0,
    starts_on DATE NOT NULL,
    ends_on DATE DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_auto_rules_active (enabled, starts_on, ends_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automatic_expense_accruals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    accrual_date DATE NOT NULL,
    shift_key VARCHAR(220) NOT NULL DEFAULT '',
    amount DECIMAL(12,2) NOT NULL,
    basis_amount DECIMAL(12,2) DEFAULT NULL,
    basis_description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_auto_accrual_rule FOREIGN KEY (rule_id) REFERENCES automatic_expense_rules(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_auto_accrual (rule_id, accrual_date, shift_key),
    INDEX idx_auto_accrual_date (accrual_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
