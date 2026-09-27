CREATE TABLE IF NOT EXISTS customer_wheel_prizes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    subtitle VARCHAR(190) DEFAULT NULL,
    prize_type ENUM('points','stamp','free_drink','discount_percent') NOT NULL,
    value DECIMAL(12,2) NOT NULL DEFAULT 0,
    cap_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    weight INT UNSIGNED NOT NULL DEFAULT 1,
    daily_limit INT UNSIGNED NOT NULL DEFAULT 0,
    validity_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    icon VARCHAR(32) NOT NULL DEFAULT 'star',
    accent VARCHAR(16) NOT NULL DEFAULT '#FFC928',
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_customer_wheel_prizes_active_sort (active,sort_order,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_wheel_spins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    spin_uuid CHAR(36) NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    source_order_id BIGINT UNSIGNED NOT NULL,
    prize_id INT UNSIGNED DEFAULT NULL,
    prize_title VARCHAR(120) NOT NULL,
    prize_type ENUM('points','stamp','free_drink','discount_percent') NOT NULL,
    prize_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    prize_cap DECIMAL(12,2) NOT NULL DEFAULT 0,
    reward_status ENUM('granted','available','redeemed','expired','restored') NOT NULL DEFAULT 'granted',
    redeemed_order_id BIGINT UNSIGNED DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    redeemed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_wheel_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_wheel_prize FOREIGN KEY (prize_id) REFERENCES customer_wheel_prizes(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_customer_wheel_spin_uuid (spin_uuid),
    UNIQUE KEY uniq_customer_wheel_source_order (source_order_id),
    KEY idx_customer_wheel_customer_status (customer_id,reward_status,created_at),
    KEY idx_customer_wheel_prize_created (prize_id,created_at),
    KEY idx_customer_wheel_redeemed_order (redeemed_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings(setting_key,setting_value) VALUES
('customer_wheel_enabled','1'),
('customer_wheel_min_order','250'),
('customer_wheel_cooldown_hours','20'),
('customer_wheel_started_at',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s')),
('customer_wheel_title','Колесо Kapouch'),
('customer_wheel_subtitle','Заверши заказ — забери подарок')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO customer_wheel_prizes(title,subtitle,prize_type,value,cap_value,weight,daily_limit,validity_days,icon,accent,active,sort_order) VALUES
('20 бонусов','Уже на твоём балансе','points',20,0,32,0,30,'star','#FFC928',1,10),
('50 бонусов','На следующий любимый кофе','points',50,0,20,0,30,'sparkle','#F4A340',1,20),
('+1 к прогрессу','На шаг ближе к напитку в подарок','stamp',1,0,20,0,30,'stamp','#D98652',1,30),
('−10% на напиток','Скидка на один напиток в заказе','discount_percent',10,80,15,0,14,'discount','#8E5B40',1,40),
('100 бонусов','Большой бонус от Kapouch','points',100,0,10,0,30,'crown','#F05B45',1,50),
('Напиток в подарок','Любой подходящий напиток до лимита программы','free_drink',1,0,3,3,30,'cup','#4F2B20',1,60);
