-- BIZ-022: per API key rate-limit overrides (requests per minute). NULL keeps
-- the global EXTERNAL_API_* default, 0 means unlimited for that bucket.
--   rate_limit_model_per_minute   model endpoints (/v1/chat/completions, ...)
--   rate_limit_other_per_minute   every other /v1 endpoint
--   rate_limit_write_per_minute   management writes (POST/PUT/PATCH/DELETE on non-model endpoints)
-- Idempotent: columns are added only when missing.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE api_keys ADD COLUMN rate_limit_model_per_minute INT UNSIGNED NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'api_keys' AND column_name = 'rate_limit_model_per_minute');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE api_keys ADD COLUMN rate_limit_other_per_minute INT UNSIGNED NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'api_keys' AND column_name = 'rate_limit_other_per_minute');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE api_keys ADD COLUMN rate_limit_write_per_minute INT UNSIGNED NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'api_keys' AND column_name = 'rate_limit_write_per_minute');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
