UPDATE cash_flow_accounts SET name='Безнал / ожидаемые поступления', provider='Evotor' WHERE name='Сбер Эквайринг';
UPDATE cash_flow_accounts SET name='Банковский счёт', provider=NULL WHERE name='Сбер Расчётный счёт';

INSERT INTO app_settings(setting_key,setting_value) VALUES
('cashflow_electron_account_name','Безнал / ожидаемые поступления')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

INSERT INTO system_meta(meta_key,meta_value) VALUES('schema_version','14')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
