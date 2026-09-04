CREATE TABLE IF NOT EXISTS customer_push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth_secret VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_success_at DATETIME DEFAULT NULL,
    last_failure_at DATETIME DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_push_subscription_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_customer_push_endpoint_hash (endpoint_hash),
    KEY idx_customer_push_customer_active (customer_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_push_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body VARCHAR(500) NOT NULL,
    target_url VARCHAR(500) DEFAULT NULL,
    segment_type VARCHAR(40) NOT NULL DEFAULT 'all',
    category_id INT UNSIGNED DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    CONSTRAINT fk_customer_push_campaign_category FOREIGN KEY (category_id) REFERENCES customer_categories(id) ON DELETE SET NULL,
    KEY idx_customer_push_campaign_status_created (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_push_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED DEFAULT NULL,
    event_type VARCHAR(40) NOT NULL,
    title VARCHAR(120) NOT NULL,
    body VARCHAR(500) NOT NULL,
    target_url VARCHAR(500) DEFAULT NULL,
    dedupe_key VARCHAR(190) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    CONSTRAINT fk_customer_push_queue_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_push_queue_campaign FOREIGN KEY (campaign_id) REFERENCES customer_push_campaigns(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_customer_push_dedupe (dedupe_key),
    KEY idx_customer_push_queue_status_next (status,next_attempt_at),
    KEY idx_customer_push_queue_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
