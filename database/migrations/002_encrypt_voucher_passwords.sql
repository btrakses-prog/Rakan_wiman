-- Run once after 001_initial_schema.sql.
-- Keeps voucher credentials encrypted at rest using APP_KEY.
ALTER TABLE vouchers CHANGE COLUMN password password_encrypted TEXT NOT NULL;
