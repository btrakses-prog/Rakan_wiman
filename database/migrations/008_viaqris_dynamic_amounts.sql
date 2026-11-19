-- ViaQRIS dynamic nominal and automatic unique-code tracking.
-- Applied idempotently by SchemaUpgrade::ensureViaQrisDynamicAmounts().
ALTER TABLE payments ADD COLUMN unique_code INT UNSIGNED NULL;
ALTER TABLE payments ADD COLUMN payable_amount BIGINT UNSIGNED NULL;
ALTER TABLE payments ADD COLUMN paid_amount BIGINT UNSIGNED NULL;
