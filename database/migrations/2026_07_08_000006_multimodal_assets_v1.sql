SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD COLUMN usage_record_id BIGINT UNSIGNED NULL AFTER id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'usage_record_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD COLUMN progress TINYINT UNSIGNED NULL AFTER status', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'progress');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD COLUMN submit_payload JSON NULL AFTER model_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'submit_payload');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD COLUMN finished_at TIMESTAMP NULL AFTER latency_ms', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'finished_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD INDEX idx_multimodal_usage_record (usage_record_id)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND index_name = 'idx_multimodal_usage_record');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD INDEX idx_multimodal_owner (user_id, department_id, api_key_id)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND index_name = 'idx_multimodal_owner');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD CONSTRAINT fk_multimodal_usage_record FOREIGN KEY (usage_record_id) REFERENCES usage_records(id) ON DELETE SET NULL', 'SELECT 1') FROM information_schema.table_constraints WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND constraint_name = 'fk_multimodal_usage_record');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD COLUMN usage_record_id BIGINT UNSIGNED NULL AFTER id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'media_assets' AND column_name = 'usage_record_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD COLUMN api_key_id BIGINT UNSIGNED NULL AFTER department_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'media_assets' AND column_name = 'api_key_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD COLUMN access_scope ENUM(''user'',''department'',''api_key'',''screen'',''private'') NOT NULL DEFAULT ''department'' AFTER api_key_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'media_assets' AND column_name = 'access_scope');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD INDEX idx_media_usage_record (usage_record_id)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'media_assets' AND index_name = 'idx_media_usage_record');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD INDEX idx_media_api_key (api_key_id)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'media_assets' AND index_name = 'idx_media_api_key');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE media_assets ADD CONSTRAINT fk_media_usage_record FOREIGN KEY (usage_record_id) REFERENCES usage_records(id) ON DELETE SET NULL', 'SELECT 1') FROM information_schema.table_constraints WHERE table_schema = @schema_name AND table_name = 'media_assets' AND constraint_name = 'fk_media_usage_record');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
