SET @schema_name = DATABASE();

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE audit_logs ADD COLUMN actor_user_id BIGINT UNSIGNED NULL AFTER id',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'actor_user_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE audit_logs ADD COLUMN trace_id VARCHAR(80) NULL AFTER request_id',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'trace_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE audit_logs ADD COLUMN client_ip VARCHAR(64) NULL AFTER trace_id',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'client_ip'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE audit_logs ADD COLUMN details JSON NULL AFTER client_ip',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'details'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE audit_logs SET actor_user_id = actor_id WHERE actor_user_id IS NULL AND actor_id IS NOT NULL;
UPDATE audit_logs SET client_ip = ip_address WHERE client_ip IS NULL AND ip_address IS NOT NULL;
UPDATE audit_logs SET details = metadata WHERE details IS NULL AND metadata IS NOT NULL;
