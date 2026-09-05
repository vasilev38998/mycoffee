-- Switch customer online payments from direct Sber gateway to YooKassa.
SET @db := DATABASE();

-- Existing generic payment tables from migration 026 are reused.
-- Rename stored provider identifiers only when those rows exist.
UPDATE customer_payment_connections SET provider='yookassa_sbp' WHERE provider='sber_sbp';
UPDATE customer_payments SET provider='yookassa_sbp' WHERE provider='sber_sbp';
UPDATE online_orders SET payment_provider='yookassa_sbp' WHERE payment_provider='sber_sbp';
