ALTER TABLE customer_accounts
    ADD COLUMN avatar_path VARCHAR(255) DEFAULT NULL AFTER birth_date;
