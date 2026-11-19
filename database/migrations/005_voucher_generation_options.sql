-- Extra per-voucher attributes used by the flexible generator and print layouts.
CREATE TABLE IF NOT EXISTS voucher_generation_options (
  voucher_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  hotspot_server VARCHAR(120) NOT NULL DEFAULT 'all',
  limit_uptime_seconds BIGINT UNSIGNED NULL,
  limit_bytes_total BIGINT UNSIGNED NULL,
  generation_comment VARCHAR(255) NULL,
  user_mode ENUM('separate','same') NOT NULL DEFAULT 'separate',
  character_set VARCHAR(30) NOT NULL DEFAULT 'lower_alnum',
  name_length TINYINT UNSIGNED NOT NULL DEFAULT 8,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_voucher_gen_options_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE,
  INDEX idx_voucher_gen_options_server (hotspot_server)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
