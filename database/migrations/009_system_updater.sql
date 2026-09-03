INSERT INTO system_meta(meta_key,meta_value) VALUES('schema_updater_enabled','1')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);

INSERT INTO system_meta(meta_key,meta_value) VALUES('schema_version','9')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
