-- Preserve package configuration at voucher/payment creation time.
ALTER TABLE vouchers
  ADD COLUMN package_name_snapshot VARCHAR(120) NULL,
  ADD COLUMN package_code_snapshot VARCHAR(60) NULL,
  ADD COLUMN mikrotik_profile_snapshot VARCHAR(120) NULL,
  ADD COLUMN limit_uptime_seconds_snapshot BIGINT UNSIGNED NULL,
  ADD COLUMN validity_days_snapshot INT UNSIGNED NULL;

ALTER TABLE payments
  ADD COLUMN package_name_snapshot VARCHAR(120) NULL,
  ADD COLUMN package_code_snapshot VARCHAR(60) NULL,
  ADD COLUMN mikrotik_profile_snapshot VARCHAR(120) NULL,
  ADD COLUMN limit_uptime_seconds_snapshot BIGINT UNSIGNED NULL,
  ADD COLUMN validity_days_snapshot INT UNSIGNED NULL;

UPDATE vouchers v
JOIN packages p ON p.id = v.package_id
SET v.package_name_snapshot = p.name,
    v.package_code_snapshot = p.code,
    v.mikrotik_profile_snapshot = p.mikrotik_profile,
    v.limit_uptime_seconds_snapshot = p.limit_uptime_seconds,
    v.validity_days_snapshot = p.validity_days
WHERE v.package_name_snapshot IS NULL;

UPDATE payments pay
JOIN packages p ON p.id = pay.package_id
SET pay.package_name_snapshot = p.name,
    pay.package_code_snapshot = p.code,
    pay.mikrotik_profile_snapshot = p.mikrotik_profile,
    pay.limit_uptime_seconds_snapshot = p.limit_uptime_seconds,
    pay.validity_days_snapshot = p.validity_days
WHERE pay.package_name_snapshot IS NULL;
