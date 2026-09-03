CREATE TABLE IF NOT EXISTS control_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_key VARCHAR(190) NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    category VARCHAR(60) NOT NULL,
    title VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    recommendation TEXT DEFAULT NULL,
    metric_value DECIMAL(16,4) DEFAULT NULL,
    threshold_value DECIMAL(16,4) DEFAULT NULL,
    status ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    last_notified_at DATETIME DEFAULT NULL,
    occurrences INT UNSIGNED NOT NULL DEFAULT 1,
    context_json TEXT DEFAULT NULL,
    UNIQUE KEY uniq_control_alert (alert_key),
    KEY idx_control_status (status,severity,last_seen_at),
    KEY idx_control_category (category,last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings(setting_key,setting_value) VALUES
('control_revenue_drop_pct','15'),
('control_avg_check_drop_pct','10'),
('control_refund_share_pct','8'),
('control_inventory_variance_value','1000'),
('control_stock_days_warning','3'),
('control_cash_limit','20000'),
('control_telegram_critical','1')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
