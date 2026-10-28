-- Gateway endpoints /v1/rerank and /v1/moderations route to models whose
-- capability is 'rerank' / 'moderation'. Legacy /v1/completions reuses
-- 'text_generation'. Idempotent: only widens the ENUM when it still lacks
-- the new values; existing rows keep their capability.

SET @schema_name = DATABASE();
SET @sql = (SELECT IF(COUNT(*) = 1, 'ALTER TABLE models MODIFY COLUMN capability ENUM(''text_generation'',''vision_understanding'',''embedding'',''asr'',''tts'',''image_generation'',''video_generation'',''rerank'',''moderation'') NOT NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'capability' AND data_type = 'enum' AND column_type NOT LIKE '%''rerank''%');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
