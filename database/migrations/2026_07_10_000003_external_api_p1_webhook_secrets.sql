SET @schema_name = DATABASE();
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE external_webhooks ADD COLUMN previous_secret VARCHAR(255) NULL AFTER secret', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'external_webhooks' AND column_name = 'previous_secret');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE external_webhooks ADD COLUMN previous_secret_expires_at TIMESTAMP NULL AFTER previous_secret', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'external_webhooks' AND column_name = 'previous_secret_expires_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
