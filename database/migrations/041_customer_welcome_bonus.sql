ALTER TABLE customer_accounts
    ADD COLUMN welcome_bonus_granted_at DATETIME DEFAULT NULL AFTER birth_date;

-- Accounts that existed before the feature launch are not retroactively treated
-- as new registrations. Accounts created after this migration keep NULL until
-- the welcome bonus is granted (or explicitly marked as processed).
UPDATE customer_accounts
SET welcome_bonus_granted_at=NOW()
WHERE welcome_bonus_granted_at IS NULL;
