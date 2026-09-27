CREATE TABLE IF NOT EXISTS customer_wheel_attempt_grants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    attempts_total SMALLINT UNSIGNED NOT NULL,
    attempts_remaining SMALLINT UNSIGNED NOT NULL,
    granted_by_user_id INT UNSIGNED DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_wheel_attempt_customer FOREIGN KEY (customer_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_wheel_attempt_user FOREIGN KEY (granted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_customer_wheel_attempt_customer_remaining (customer_id,attempts_remaining,id),
    KEY idx_customer_wheel_attempt_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customer_wheel_spins
    MODIFY source_order_id BIGINT UNSIGNED NULL,
    ADD COLUMN attempt_grant_id BIGINT UNSIGNED NULL AFTER source_order_id,
    ADD CONSTRAINT fk_customer_wheel_spin_attempt FOREIGN KEY (attempt_grant_id) REFERENCES customer_wheel_attempt_grants(id) ON DELETE SET NULL,
    ADD KEY idx_customer_wheel_attempt_grant (attempt_grant_id);
