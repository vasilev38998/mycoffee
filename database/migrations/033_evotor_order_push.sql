ALTER TABLE evotor_connections
  ADD COLUMN push_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled,
  ADD COLUMN push_application_id VARCHAR(64) DEFAULT NULL AFTER push_enabled,
  ADD COLUMN push_device_uuid VARCHAR(100) DEFAULT NULL AFTER push_application_id,
  ADD COLUMN push_token_ciphertext TEXT DEFAULT NULL AFTER push_device_uuid,
  ADD COLUMN push_token_iv VARCHAR(64) DEFAULT NULL AFTER push_token_ciphertext,
  ADD COLUMN push_token_tag VARCHAR(64) DEFAULT NULL AFTER push_token_iv,
  ADD COLUMN push_last_sent_at DATETIME DEFAULT NULL AFTER push_token_tag,
  ADD COLUMN push_last_error VARCHAR(1000) DEFAULT NULL AFTER push_last_sent_at;

CREATE TABLE IF NOT EXISTS evotor_order_push_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connection_id INT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  status ENUM('pending','sent','error') NOT NULL DEFAULT 'pending',
  provider_push_id VARCHAR(100) DEFAULT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  payload_json TEXT NOT NULL,
  last_error VARCHAR(1000) DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_evotor_order_push_connection FOREIGN KEY (connection_id) REFERENCES evotor_connections(id) ON DELETE CASCADE,
  CONSTRAINT fk_evotor_order_push_order FOREIGN KEY (order_id) REFERENCES online_orders(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_evotor_order_push_event (connection_id,order_id,event_type),
  KEY idx_evotor_order_push_retry (status,attempts,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
