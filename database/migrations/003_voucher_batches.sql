-- For installations that already ran 001 and 002 before voucher batches were added.
CREATE TABLE IF NOT EXISTS voucher_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_key CHAR(32) NOT NULL UNIQUE,
  package_id BIGINT UNSIGNED NOT NULL,
  router_id BIGINT UNSIGNED NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_batch_package FOREIGN KEY (package_id) REFERENCES packages(id),
  CONSTRAINT fk_batch_router FOREIGN KEY (router_id) REFERENCES routers(id),
  CONSTRAINT fk_batch_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE vouchers ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER public_id;
ALTER TABLE vouchers ADD CONSTRAINT fk_vouchers_batch FOREIGN KEY (batch_id) REFERENCES voucher_batches(id);
