-- TokenHub APP, second batch: audit channel/device (APP-009), passkeys for
-- step-up (APP-040), push rules (APP-037) and device risk signals (APP-043).
-- Idempotent: CREATE TABLE IF NOT EXISTS and guarded ALTER TABLE only.

SET @schema_name = DATABASE();

-- audit_logs.channel: 'web' console, 'app' TokenHub APP, NULL system/API.
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD COLUMN channel VARCHAR(16) NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'channel');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD COLUMN device_id BIGINT UNSIGNED NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'device_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_device (device_id)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND index_name = 'idx_audit_logs_device');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Pending WebAuthn challenge of a device (one at a time, short-lived).
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_app_devices ADD COLUMN webauthn_challenge VARCHAR(128) NULL, ADD COLUMN webauthn_challenge_expires_at DATETIME NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'admin_app_devices' AND column_name = 'webauthn_challenge');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Client software seen on the latest refresh (device risk signal).
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_app_devices ADD COLUMN last_user_agent VARCHAR(255) NULL AFTER last_ip', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'admin_app_devices' AND column_name = 'last_user_agent');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS admin_app_passkeys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  credential_id VARCHAR(1024) NOT NULL,
  credential_hash CHAR(64) NOT NULL,
  public_key_pem TEXT NOT NULL,
  algorithm INT NOT NULL,
  sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(100) NULL,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_admin_app_passkeys_credential (credential_hash),
  KEY idx_admin_app_passkeys_device (device_id),
  CONSTRAINT fk_admin_app_passkeys_device FOREIGN KEY (device_id) REFERENCES admin_app_devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_app_passkeys_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Push rules: lowest severity to deliver and an optional quiet period.
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_app_push_subscriptions ADD COLUMN min_severity VARCHAR(16) NOT NULL DEFAULT ''warning'', ADD COLUMN quiet_start TIME NULL, ADD COLUMN quiet_end TIME NULL, ADD COLUMN time_zone VARCHAR(64) NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'admin_app_push_subscriptions' AND column_name = 'min_severity');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
