ALTER TABLE users MODIFY COLUMN role ENUM('owner','manager','accountant','employee') NOT NULL DEFAULT 'employee';

SET @kapouch_user_active_exists := (
 SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='active'
);
SET @kapouch_user_active_sql := IF(
 @kapouch_user_active_exists=0,
 'ALTER TABLE users ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER role',
 'SELECT 1'
);
PREPARE kapouch_user_active_stmt FROM @kapouch_user_active_sql;
EXECUTE kapouch_user_active_stmt;
DEALLOCATE PREPARE kapouch_user_active_stmt;

CREATE TABLE IF NOT EXISTS audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED DEFAULT NULL,
 user_name VARCHAR(120) DEFAULT NULL,
 user_role VARCHAR(40) DEFAULT NULL,
 action VARCHAR(80) NOT NULL,
 entity_type VARCHAR(80) DEFAULT NULL,
 entity_id VARCHAR(120) DEFAULT NULL,
 request_method VARCHAR(10) DEFAULT NULL,
 request_path VARCHAR(255) DEFAULT NULL,
 description VARCHAR(255) DEFAULT NULL,
 context_json MEDIUMTEXT DEFAULT NULL,
 ip_address VARCHAR(64) DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_audit_created (created_at),
 KEY idx_audit_user (user_id,created_at),
 KEY idx_audit_action (action,created_at),
 CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_meta(meta_key,meta_value) VALUES('schema_version','10')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
