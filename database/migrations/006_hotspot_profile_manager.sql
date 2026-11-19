CREATE TABLE IF NOT EXISTS hotspot_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  shared_users SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rate_limit VARCHAR(120) NOT NULL DEFAULT '',
  idle_timeout VARCHAR(50) NOT NULL DEFAULT 'none',
  keepalive_timeout VARCHAR(50) NOT NULL DEFAULT 'none',
  status_autorefresh VARCHAR(50) NOT NULL DEFAULT 'none',
  add_mac_cookie TINYINT(1) NOT NULL DEFAULT 1,
  mac_cookie_timeout VARCHAR(50) NOT NULL DEFAULT '3d',
  address_pool VARCHAR(120) NOT NULL DEFAULT 'none',
  address_list VARCHAR(120) NOT NULL DEFAULT '',
  transparent_proxy TINYINT(1) NOT NULL DEFAULT 1,
  is_managed TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_hotspot_profiles_active (is_active,is_managed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotspot_profile_router_sync (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id BIGINT UNSIGNED NOT NULL,
  router_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'unknown',
  config_hash CHAR(64) NULL,
  remote_hash CHAR(64) NULL,
  last_checked_at DATETIME NULL,
  last_synced_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_hp_sync_profile FOREIGN KEY (profile_id) REFERENCES hotspot_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_hp_sync_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE,
  UNIQUE KEY uq_hotspot_profile_router (profile_id,router_id),
  INDEX idx_hp_sync_router_status (router_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotspot_profile_sync_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile_id BIGINT UNSIGNED NOT NULL,
  router_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_hp_queue_profile FOREIGN KEY (profile_id) REFERENCES hotspot_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_hp_queue_router FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE CASCADE,
  UNIQUE KEY uq_hotspot_profile_sync_job (profile_id,router_id),
  INDEX idx_hotspot_profile_queue (status,available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hotspot_profiles
  (name,shared_users,rate_limit,idle_timeout,keepalive_timeout,status_autorefresh,add_mac_cookie,mac_cookie_timeout,address_pool,address_list,transparent_proxy,is_managed,is_active)
SELECT DISTINCT
  TRIM(mikrotik_profile),1,'','none','none','none',1,'3d','none','',1,0,1
FROM packages
WHERE TRIM(COALESCE(mikrotik_profile,''))<>'';
