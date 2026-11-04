-- TokenHub APP (PWA / mobile / desktop) sign-in for administrators.
-- An administrator generates a one-time link in the web console; opening it on
-- a device exchanges the code for a long-lived, rotating device credential
-- (refresh token) plus short-lived access tokens (admin_sessions rows with
-- channel = 'app'). Only HMAC hashes of codes and tokens are stored.
-- Idempotent: CREATE TABLE IF NOT EXISTS and guarded ALTER TABLE only.

CREATE TABLE IF NOT EXISTS admin_app_link_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  code_hash CHAR(64) NOT NULL,
  note VARCHAR(100) NULL,
  allowed_ip_cidrs JSON NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  used_device_id BIGINT UNSIGNED NULL,
  created_ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_admin_app_link_codes_hash (code_hash),
  KEY idx_admin_app_link_codes_user (user_id, expires_at),
  KEY idx_admin_app_link_codes_expires (expires_at),
  CONSTRAINT fk_admin_app_link_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_app_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  platform VARCHAR(32) NOT NULL DEFAULT 'web',
  client_type ENUM('pwa','native') NOT NULL DEFAULT 'pwa',
  user_agent VARCHAR(255) NULL,
  refresh_hash CHAR(64) NOT NULL,
  prev_refresh_hash CHAR(64) NULL,
  prev_valid_until DATETIME NULL,
  bound_over_http TINYINT(1) NOT NULL DEFAULT 0,
  pin_hash VARCHAR(255) NULL,
  pin_failures INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  revoke_reason VARCHAR(64) NULL,
  revoked_at DATETIME NULL,
  link_code_id BIGINT UNSIGNED NULL,
  last_seen_at DATETIME NULL,
  last_ip VARCHAR(64) NULL,
  idle_expires_at DATETIME NOT NULL,
  absolute_expires_at DATETIME NOT NULL,
  created_ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_admin_app_devices_refresh (refresh_hash),
  KEY idx_admin_app_devices_prev_refresh (prev_refresh_hash),
  KEY idx_admin_app_devices_user (user_id, status),
  KEY idx_admin_app_devices_expiry (status, idle_expires_at),
  CONSTRAINT fk_admin_app_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @schema_name = DATABASE();

-- admin_sessions.channel: 'web' = console cookie session, 'app' = APP bearer access token.
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_sessions ADD COLUMN channel VARCHAR(16) NOT NULL DEFAULT ''web'' AFTER user_agent', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'admin_sessions' AND column_name = 'channel');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_sessions ADD COLUMN device_id BIGINT UNSIGNED NULL AFTER channel', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'admin_sessions' AND column_name = 'device_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Last successful step-up verification (PIN / passkey) of an APP access session.
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_sessions ADD COLUMN step_up_at DATETIME NULL AFTER device_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'admin_sessions' AND column_name = 'step_up_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_sessions ADD INDEX idx_admin_sessions_device (device_id)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'admin_sessions' AND index_name = 'idx_admin_sessions_device');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
