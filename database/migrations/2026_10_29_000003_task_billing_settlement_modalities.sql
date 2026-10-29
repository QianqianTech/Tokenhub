-- Asynchronous task billing settlement and input-modality capabilities (2026-10-29).
-- Idempotent: every change is guarded through information_schema. Statements
-- are split on semicolons by MigrationRunner, so no string below contains one.

SET @schema_name = DATABASE();

-- multimodal_tasks.settled_at: set once the charge made at submission was
-- refunded (failed / cancelled / expired) or trued up to the measured usage.
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD COLUMN settled_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'settled_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD INDEX idx_multimodal_settlement (status, settled_at)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND index_name = 'idx_multimodal_settlement');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Tasks that finished before this release keep their historical charge.
UPDATE multimodal_tasks SET settled_at = COALESCE(finished_at, updated_at, created_at) WHERE settled_at IS NULL AND status IN ('succeeded', 'failed', 'expired');

-- Journal of every settlement (refund or true-up).
CREATE TABLE IF NOT EXISTS usage_settlements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id VARCHAR(80) NOT NULL,
  usage_record_id BIGINT UNSIGNED NOT NULL,
  api_key_id BIGINT UNSIGNED NULL,
  model_id BIGINT UNSIGNED NULL,
  reason VARCHAR(40) NOT NULL,
  charged JSON NULL,
  final JSON NULL,
  cost_delta DECIMAL(18,8) NULL,
  currency CHAR(3) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usage_settlement_task (task_id),
  INDEX idx_usage_settlement_record (usage_record_id),
  INDEX idx_usage_settlement_key (api_key_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- models.capability: every capability the gateway routes (vision / video /
-- audio understanding, rerank, moderation, music, realtime).
SET @sql = (SELECT IF(COUNT(*) = 1, 'ALTER TABLE models MODIFY COLUMN capability ENUM(''text_generation'',''vision_understanding'',''video_understanding'',''audio_understanding'',''embedding'',''asr'',''tts'',''image_generation'',''video_generation'',''rerank'',''moderation'',''music_generation'',''realtime'') NOT NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'capability' AND data_type = 'enum' AND (column_type NOT LIKE '%''video_understanding''%' OR column_type NOT LIKE '%''music_generation''%' OR column_type NOT LIKE '%''realtime''%' OR column_type NOT LIKE '%''rerank''%'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
