CREATE TABLE IF NOT EXISTS request_rate_limits (
  scope VARCHAR(80) NOT NULL,
  identity_hash CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (scope,identity_hash),
  KEY idx_request_rate_limits_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
