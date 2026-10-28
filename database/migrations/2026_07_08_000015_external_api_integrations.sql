SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD COLUMN api_key_id BIGINT UNSIGNED NULL AFTER department_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'media_assets' AND column_name = 'api_key_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD COLUMN access_scope ENUM(''user'',''department'',''api_key'',''screen'',''private'') NOT NULL DEFAULT ''department'' AFTER api_key_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'media_assets' AND column_name = 'access_scope');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD COLUMN deleted_at TIMESTAMP NULL AFTER expires_at', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'media_assets' AND column_name = 'deleted_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD INDEX idx_media_api_key_deleted (api_key_id, deleted_at)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'media_assets' AND index_name = 'idx_media_api_key_deleted');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS external_feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  department_id BIGINT UNSIGNED NULL,
  request_id VARCHAR(80) NULL,
  rating TINYINT UNSIGNED NULL,
  tags JSON NULL,
  comment TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_external_feedback_api_key (api_key_id, created_at),
  KEY idx_external_feedback_request (request_id),
  CONSTRAINT fk_external_feedback_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_webhooks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  webhook_id VARCHAR(80) NOT NULL UNIQUE,
  api_key_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  department_id BIGINT UNSIGNED NOT NULL,
  url VARCHAR(800) NOT NULL,
  event_types JSON NOT NULL,
  secret VARCHAR(255) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_delivery_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_external_webhooks_key (api_key_id, status, deleted_at),
  CONSTRAINT fk_external_webhooks_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_webhook_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_id VARCHAR(80) NOT NULL UNIQUE,
  webhook_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(120) NOT NULL,
  payload_json JSON NOT NULL,
  status ENUM('pending','succeeded','failed','dead') NOT NULL DEFAULT 'pending',
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  response_status INT UNSIGNED NULL,
  error_summary VARCHAR(500) NULL,
  next_attempt_at TIMESTAMP NULL,
  delivered_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_external_webhook_deliveries_webhook (webhook_id, created_at),
  KEY idx_external_webhook_deliveries_due (status, next_attempt_at),
  CONSTRAINT fk_external_webhook_deliveries_webhook FOREIGN KEY (webhook_id) REFERENCES external_webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
