-- Who disabled an API key: 'admin' (console) or 'management' (agent
-- management API). An agent may only re-enable keys an agent disabled.
-- Keys disabled before this column existed keep NULL, which the
-- management API treats as 'admin'. Idempotent.

SET @schema_name = DATABASE();
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE api_keys ADD COLUMN disabled_by ENUM(''admin'',''management'') NULL AFTER status', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'api_keys' AND column_name = 'disabled_by');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
