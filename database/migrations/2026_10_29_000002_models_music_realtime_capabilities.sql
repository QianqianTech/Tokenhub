-- Music generation and realtime speech (MM-030 / MM-031) are model capabilities;
-- music tasks are stored in multimodal_tasks. Idempotent: each ENUM is widened
-- only while it still lacks 'music_generation'; existing rows are unchanged.
-- Runs after 2026_10_28_000004 (rerank / moderation) and keeps those values.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 1, 'ALTER TABLE models MODIFY COLUMN capability ENUM(''text_generation'',''vision_understanding'',''embedding'',''asr'',''tts'',''image_generation'',''video_generation'',''rerank'',''moderation'',''music_generation'',''realtime'') NOT NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'capability' AND data_type = 'enum' AND column_type NOT LIKE '%''music_generation''%');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 1, 'ALTER TABLE multimodal_tasks MODIFY COLUMN task_type ENUM(''asr'',''tts'',''image_generation'',''video_generation'',''music_generation'') NOT NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'task_type' AND data_type = 'enum' AND column_type NOT LIKE '%''music_generation''%');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
