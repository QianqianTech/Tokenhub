SET @schema_name = DATABASE();

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE usage_records
      ADD COLUMN cache_read_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER total_tokens,
      ADD COLUMN cache_write_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER cache_read_tokens',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'cache_read_tokens'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE usage_aggregates_minute
      ADD COLUMN cache_read_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER output_tokens,
      ADD COLUMN cache_write_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER cache_read_tokens',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name = 'cache_read_tokens'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE usage_aggregates_hour
      ADD COLUMN cache_read_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER output_tokens,
      ADD COLUMN cache_write_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER cache_read_tokens',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name = 'cache_read_tokens'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE usage_aggregates_day
      ADD COLUMN cache_read_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER output_tokens,
      ADD COLUMN cache_write_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER cache_read_tokens',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name = 'cache_read_tokens'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
