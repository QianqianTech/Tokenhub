SET @schema_name = DATABASE();

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE usage_aggregates_minute
      ADD COLUMN latency_bucket_100 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_ms_sum,
      ADD COLUMN latency_bucket_300 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_100,
      ADD COLUMN latency_bucket_1000 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_300,
      ADD COLUMN latency_bucket_3000 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_1000,
      ADD COLUMN latency_bucket_over_3000 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_3000',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name = 'latency_bucket_100'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE usage_aggregates_hour
      ADD COLUMN latency_bucket_100 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_ms_sum,
      ADD COLUMN latency_bucket_300 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_100,
      ADD COLUMN latency_bucket_1000 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_300,
      ADD COLUMN latency_bucket_3000 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_1000,
      ADD COLUMN latency_bucket_over_3000 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_3000',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name = 'latency_bucket_100'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE usage_aggregates_day
      ADD COLUMN latency_bucket_100 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_ms_sum,
      ADD COLUMN latency_bucket_300 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_100,
      ADD COLUMN latency_bucket_1000 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_300,
      ADD COLUMN latency_bucket_3000 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_1000,
      ADD COLUMN latency_bucket_over_3000 BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER latency_bucket_3000',
    'DO 1'
  )
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name = 'latency_bucket_100'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
