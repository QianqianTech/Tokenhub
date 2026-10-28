SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER usage_record_id', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND column_name = 'user_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER user_id', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND column_name = 'department_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD COLUMN api_key_id BIGINT UNSIGNED NULL AFTER department_id', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND column_name = 'api_key_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD COLUMN model_id BIGINT UNSIGNED NULL AFTER api_key_id', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND column_name = 'model_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD COLUMN session_id VARCHAR(120) NULL AFTER model_id', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND column_name = 'session_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND column_name = 'updated_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD INDEX idx_usage_contents_user (user_id, created_at)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND index_name = 'idx_usage_contents_user');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD INDEX idx_usage_contents_department (department_id, created_at)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND index_name = 'idx_usage_contents_department');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD INDEX idx_usage_contents_api_key (api_key_id, created_at)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND index_name = 'idx_usage_contents_api_key');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD INDEX idx_usage_contents_model (model_id, created_at)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND index_name = 'idx_usage_contents_model');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD INDEX idx_usage_contents_session (session_id)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND index_name = 'idx_usage_contents_session');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_contents ADD INDEX idx_usage_contents_expires (expires_at)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'usage_contents' AND index_name = 'idx_usage_contents_expires');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE export_tasks ADD COLUMN format ENUM(''csv'') NOT NULL DEFAULT ''csv'' AFTER status', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'export_tasks' AND column_name = 'format');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE export_tasks ADD COLUMN download_count BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER error_code', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'export_tasks' AND column_name = 'download_count');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE export_tasks ADD COLUMN finished_at TIMESTAMP NULL AFTER expires_at', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'export_tasks' AND column_name = 'finished_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE export_tasks ADD INDEX idx_export_requested (requested_by, created_at)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'export_tasks' AND index_name = 'idx_export_requested');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE export_tasks ADD INDEX idx_export_expires (expires_at)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'export_tasks' AND index_name = 'idx_export_expires');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD COLUMN trace_id VARCHAR(64) NULL AFTER request_id', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'trace_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD COLUMN actor_user_id BIGINT UNSIGNED NULL AFTER trace_id', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'actor_user_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD COLUMN client_ip VARCHAR(64) NULL AFTER target_id', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'client_ip');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD COLUMN details JSON NULL AFTER client_ip', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'details');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_actor (actor_user_id)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND index_name = 'idx_audit_actor');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE audit_logs SET actor_user_id = actor_id WHERE actor_user_id IS NULL AND actor_id IS NOT NULL;
UPDATE audit_logs SET client_ip = ip_address WHERE client_ip IS NULL AND ip_address IS NOT NULL;
UPDATE audit_logs SET details = metadata WHERE details IS NULL AND metadata IS NOT NULL;

INSERT INTO system_settings (setting_key, setting_value, description)
VALUES (
  'content_retention.policy',
  JSON_OBJECT(
    'mode', 'summary',
    'default_days', 30,
    'max_days', 180,
    'raw_allowed_roles', JSON_ARRAY('super_admin'),
    'departments', JSON_OBJECT(),
    'users', JSON_OBJECT(),
    'api_keys', JSON_OBJECT()
  ),
  'Content retention policy'
)
ON DUPLICATE KEY UPDATE setting_key = setting_key;
