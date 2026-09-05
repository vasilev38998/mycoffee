-- Switch customer online payments from direct Sber gateway to YooKassa.
-- Existing generic payment tables from migration 026 are reused.

-- Old direct-Sber credentials cannot be reused for YooKassa, so disable/remove them.
DELETE FROM customer_payment_connections WHERE provider='sber_sbp';

-- Preserve any historical direct-Sber payment identifiers without pretending they are YooKassa IDs.
UPDATE customer_payments SET provider='sber_sbp_legacy' WHERE provider='sber_sbp';
UPDATE online_orders SET payment_provider='sber_sbp_legacy' WHERE payment_provider='sber_sbp';
