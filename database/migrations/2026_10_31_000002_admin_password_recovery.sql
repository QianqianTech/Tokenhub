-- Optional, unique recovery address. Existing administrators remain usable.
SET @has_email = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email');
SET @ddl = IF(@has_email = 0, 'ALTER TABLE users ADD COLUMN email VARCHAR(254) NULL, ADD UNIQUE KEY users_email_unique (email)', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Kept outside generic system-settings CRUD so SMTP credentials cannot be
-- read or overwritten through the advanced JSON editor.
CREATE TABLE IF NOT EXISTS admin_smtp_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  settings_value JSON NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id CHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(254) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  password_fingerprint CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY admin_password_resets_request_unique (request_id),
  UNIQUE KEY admin_password_resets_token_unique (token_hash),
  KEY idx_admin_password_resets_user (user_id, used_at),
  KEY idx_admin_password_resets_expiry (expires_at),
  CONSTRAINT fk_admin_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
