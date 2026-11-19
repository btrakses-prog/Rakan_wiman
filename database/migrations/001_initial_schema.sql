SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE TABLE IF NOT EXISTS admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin','admin','operator') NOT NULL DEFAULT 'superadmin',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  identity VARCHAR(120) NULL,
  host VARCHAR(255) NOT NULL,
  api_port SMALLINT UNSIGNED NOT NULL DEFAULT 8728,
  username VARCHAR(120) NOT NULL,
  password VARCHAR(255) NOT NULL,
  api_ssl TINYINT(1) NOT NULL DEFAULT 0,
  hotspot_server VARCHAR(120) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  connection_status ENUM('unknown','online','offline','error') NOT NULL DEFAULT 'unknown',
  last_seen_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_routers_active (is_active),
  INDEX idx_routers_status (connection_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(60) NOT NULL UNIQUE,
  price BIGINT UNSIGNED NOT NULL,
  mikrotik_profile VARCHAR(120) NOT NULL,
  limit_uptime_seconds BIGINT UNSIGNED NULL,
  validity_days INT UNSIGNED NULL,
  shared_users SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vouchers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  router_id BIGINT UNSIGNED NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  username VARCHAR(120) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  generation_mode ENUM('on_demand','bulk') NOT NULL,
  sale_status ENUM('available','reserved','sold','cancelled') NOT NULL DEFAULT 'available',
  sync_status ENUM('pending','synced','failed','not_required') NOT NULL DEFAULT 'pending',
  usage_status ENUM('unused','active','expired','disabled') NOT NULL DEFAULT 'unused',
  source_payment_id BIGINT UNSIGNED NULL,
  first_login_at DATETIME NULL,
  expires_at DATETIME NULL,
  expired_at DATETIME NULL,
  mikrotik_user_id VARCHAR(120) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vouchers_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE SET NULL,
  CONSTRAINT fk_vouchers_package FOREIGN KEY (package_id) REFERENCES packages(id),
  INDEX idx_voucher_states (sale_status, sync_status, usage_status),
  INDEX idx_voucher_expiry (expires_at),
  INDEX idx_voucher_payment (source_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(80) NOT NULL UNIQUE,
  package_id BIGINT UNSIGNED NOT NULL,
  router_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(120) NULL,
  whatsapp_number VARCHAR(30) NOT NULL,
  amount BIGINT UNSIGNED NOT NULL,
  gateway VARCHAR(40) NOT NULL DEFAULT 'viaqris',
  gateway_transaction_id VARCHAR(190) NULL UNIQUE,
  payment_url TEXT NULL,
  payment_payload LONGTEXT NULL,
  status ENUM('pending','paid','expired','cancelled','failed','refunded') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL,
  expires_at DATETIME NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_package FOREIGN KEY (package_id) REFERENCES packages(id),
  CONSTRAINT fk_payments_router FOREIGN KEY (router_id) REFERENCES routers(id),
  INDEX idx_payments_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway VARCHAR(40) NOT NULL,
  event_key VARCHAR(190) NOT NULL,
  payload LONGTEXT NOT NULL,
  processing_status ENUM('received','processed','ignored','failed') NOT NULL DEFAULT 'received',
  error_message TEXT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_webhook_event (gateway, event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS router_sync_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  router_id BIGINT UNSIGNED NOT NULL,
  voucher_id BIGINT UNSIGNED NOT NULL,
  action ENUM('create','update','disable','delete','reconcile') NOT NULL DEFAULT 'create',
  status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sync_router FOREIGN KEY (router_id) REFERENCES routers(id),
  CONSTRAINT fk_sync_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id),
  INDEX idx_sync_queue (status, available_at),
  UNIQUE KEY uq_sync_job (router_id, voucher_id, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_senders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone_number VARCHAR(30) NOT NULL UNIQUE,
  jwt_encrypted TEXT NULL,
  status ENUM('disconnected','connected','error') NOT NULL DEFAULT 'disconnected',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_connected_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp',
  recipient VARCHAR(30) NOT NULL,
  message TEXT NOT NULL,
  related_type VARCHAR(40) NULL,
  related_id BIGINT UNSIGNED NULL,
  status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_message_queue (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  description TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(120) NOT NULL UNIQUE,
  setting_value LONGTEXT NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
