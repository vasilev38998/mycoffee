UPDATE cash_flow_accounts SET name='Безнал / ожидаемые поступления', provider='Evotor' WHERE name='Сбер Эквайринг';
UPDATE cash_flow_accounts SET name='Банковский счёт', provider=NULL WHERE name='Сбер Расчётный счёт';

UPDATE cash_flow_entries
SET description=REPLACE(description,'Сбер/безнал','Безнал')
WHERE description LIKE 'Сбер/безнал%';
UPDATE cash_flow_entries
SET description='Комиссия по безналичным платежам'
WHERE source_type='sber_fee' AND description='Комиссия Сбер эквайринга';

INSERT INTO app_settings(setting_key,setting_value) VALUES
('cashflow_electron_account_name','Безнал / ожидаемые поступления')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

INSERT INTO system_meta(meta_key,meta_value) VALUES('schema_version','14')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
