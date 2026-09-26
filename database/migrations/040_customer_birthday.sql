ALTER TABLE customer_accounts
    ADD COLUMN birth_date DATE DEFAULT NULL AFTER name;
