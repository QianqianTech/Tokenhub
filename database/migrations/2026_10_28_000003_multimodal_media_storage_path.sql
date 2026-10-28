-- Generated media keep the upstream URL as storage_path until downloaded.
-- Signed object-storage URLs (Volcengine TOS with STS token, S3/OSS
-- presigned URLs) regularly exceed 800 characters; with strict SQL mode the
-- INSERT failed after the upstream call had already succeeded and was billed.
-- Idempotent: only widens when the column is still shorter.

SET @schema_name = DATABASE();
SET @sql = (SELECT IF(COUNT(*) = 1, 'ALTER TABLE media_assets MODIFY COLUMN storage_path VARCHAR(2048) NOT NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'media_assets' AND column_name = 'storage_path' AND character_maximum_length < 2048);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
