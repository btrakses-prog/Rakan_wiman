-- RAKAN WIMAN v0.3.0: payment, WA, portal and router credential alignment.
-- Run after migrations 001, 002 and 003.
ALTER TABLE routers CHANGE COLUMN password password_encrypted TEXT NOT NULL;
ALTER TABLE vouchers ADD UNIQUE KEY uq_voucher_source_payment (source_payment_id);
ALTER TABLE payments ADD COLUMN voucher_id BIGINT UNSIGNED NULL AFTER processed_at;
ALTER TABLE payments ADD COLUMN qr_image_url TEXT NULL AFTER payment_url;
ALTER TABLE payments ADD KEY idx_payments_voucher (voucher_id);
