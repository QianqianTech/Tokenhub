SET @schema_name = DATABASE();

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
    'ALTER TABLE audit_logs ADD COLUMN actor_user_id BIGINT UNSIGNED NULL AFTER trace_id',
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
    'ALTER TABLE audit_logs ADD COLUMN client_ip VARCHAR(64) NULL AFTER target_id',
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
    'ALTER TABLE audit_logs ADD COLUMN details JSON NULL AFTER user_agent',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'details'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
