UPDATE app_settings SET setting_value='Kapouch' WHERE setting_key='coffee_name' AND setting_value IN ('MyCoffee','My Coffee','mycoffee');

CREATE TABLE IF NOT EXISTS notification_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('telegram') NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    destination VARCHAR(190) DEFAULT NULL,
    secret_ciphertext TEXT DEFAULT NULL,
    secret_iv VARCHAR(64) DEFAULT NULL,
    secret_tag VARCHAR(64) DEFAULT NULL,
    send_hour TINYINT UNSIGNED NOT NULL DEFAULT 9,
    last_sent_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notification_channel (channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_settings(channel,enabled,send_hour)
VALUES('telegram',0,9)
ON DUPLICATE KEY UPDATE channel=VALUES(channel);
