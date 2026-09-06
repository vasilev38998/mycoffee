INSERT INTO app_settings(setting_key,setting_value) VALUES
('customer_app_public_url','https://app.kapouch.store/'),
('customer_api_public_url','https://kapouch.store/api/'),
('customer_api_allowed_origin','https://app.kapouch.store')
ON DUPLICATE KEY UPDATE setting_value=CASE WHEN TRIM(setting_value)='' THEN VALUES(setting_value) ELSE setting_value END;
