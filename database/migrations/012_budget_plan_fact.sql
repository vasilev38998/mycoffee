CREATE TABLE IF NOT EXISTS monthly_budgets (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 month_start DATE NOT NULL,
 revenue_plan DECIMAL(14,2) NOT NULL DEFAULT 0,
 profit_plan DECIMAL(14,2) NOT NULL DEFAULT 0,
 purchases_plan DECIMAL(14,2) NOT NULL DEFAULT 0,
 notes VARCHAR(500) DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_monthly_budget_month (month_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_expense_lines (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 budget_id INT UNSIGNED NOT NULL,
 category VARCHAR(120) NOT NULL,
 planned_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_budget_expense_category (budget_id,category),
 KEY idx_budget_expense_budget (budget_id),
 CONSTRAINT fk_budget_expense_budget FOREIGN KEY (budget_id) REFERENCES monthly_budgets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings(setting_key,setting_value) VALUES
('budget_warning_pct','90'),
('budget_critical_pct','110')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO system_meta(meta_key,meta_value) VALUES('schema_version','12')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
