CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_meta (
    meta_key VARCHAR(100) PRIMARY KEY,
    meta_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings(setting_key,setting_value) VALUES
('coffee_name','MyCoffee'),
('timezone','Asia/Irkutsk'),
('currency','₽'),
('monthly_revenue_goal','0'),
('monthly_profit_goal','0'),
('target_food_cost','30'),
('target_expense_load','30'),
('opening_hour','07:00'),
('closing_hour','21:00')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
