-- TokenHub platform completeness (2026-10-28 audit TH-007..TH-039).
-- Idempotent: every ALTER is guarded through information_schema so the file
-- can be re-run on partially migrated databases. Statements are split on
-- semicolons by MigrationRunner, so no string below contains one.

SET @schema_name = DATABASE();
-- usage_records
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN finished_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'finished_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN usage_source VARCHAR(20) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'usage_source');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN reasoning_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'reasoning_tokens');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN cost_currency CHAR(3) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'cost_currency');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN upstream_request_id VARCHAR(190) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'upstream_request_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN upstream_model VARCHAR(190) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'upstream_model');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN upstream_provider VARCHAR(120) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'upstream_provider');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN upstream_cost DECIMAL(18,8) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'upstream_cost');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN attempts JSON NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'attempts');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN stream_outcome VARCHAR(40) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'stream_outcome');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN first_byte_ms INT UNSIGNED NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'first_byte_ms');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN aggregated_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'aggregated_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN events_emitted_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'events_emitted_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN reconciled_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'reconciled_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD COLUMN reconciliation JSON NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'reconciliation');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- models
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN capabilities JSON NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'capabilities');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN capability_tags JSON NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'capability_tags');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN status_source VARCHAR(20) NOT NULL DEFAULT ''manual''', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'status_source');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN catalog_synced_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'catalog_synced_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN catalog_missing_since TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'catalog_missing_since');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN retired_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'retired_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN max_output_tokens INT UNSIGNED NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'max_output_tokens');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- providers
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE providers ADD COLUMN retired_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'providers' AND column_name = 'retired_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- model_prices
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE model_prices ADD COLUMN cache_read_token_price DECIMAL(18,8) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'model_prices' AND column_name = 'cache_read_token_price');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE model_prices ADD COLUMN cache_write_token_price DECIMAL(18,8) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'model_prices' AND column_name = 'cache_write_token_price');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE model_prices ADD COLUMN reasoning_token_price DECIMAL(18,8) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'model_prices' AND column_name = 'reasoning_token_price');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- alert_events
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN fingerprint VARCHAR(190) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'fingerprint');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN occurrence_count INT UNSIGNED NOT NULL DEFAULT 1', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'occurrence_count');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN first_seen_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'first_seen_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN last_seen_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'last_seen_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN acknowledged_by BIGINT UNSIGNED NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'acknowledged_by');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN acknowledged_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'acknowledged_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN resolved_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'resolved_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN resolution VARCHAR(40) NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'resolution');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN silenced_until TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'silenced_until');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD COLUMN escalated_at TIMESTAMP NULL', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'escalated_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- usage_aggregates_minute
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_minute ADD COLUMN latency_bucket_10000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name = 'latency_bucket_10000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_minute ADD COLUMN latency_bucket_30000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name = 'latency_bucket_30000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_minute ADD COLUMN latency_bucket_120000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name = 'latency_bucket_120000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_minute ADD COLUMN latency_bucket_over_120000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name = 'latency_bucket_over_120000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- usage_aggregates_hour
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_hour ADD COLUMN latency_bucket_10000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name = 'latency_bucket_10000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_hour ADD COLUMN latency_bucket_30000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name = 'latency_bucket_30000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_hour ADD COLUMN latency_bucket_120000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name = 'latency_bucket_120000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_hour ADD COLUMN latency_bucket_over_120000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name = 'latency_bucket_over_120000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- usage_aggregates_day
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_day ADD COLUMN latency_bucket_10000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name = 'latency_bucket_10000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_day ADD COLUMN latency_bucket_30000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name = 'latency_bucket_30000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_day ADD COLUMN latency_bucket_120000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name = 'latency_bucket_120000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_aggregates_day ADD COLUMN latency_bucket_over_120000 BIGINT UNSIGNED NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name = 'latency_bucket_over_120000');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD INDEX idx_usage_upstream_request (upstream_request_id)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'usage_records' AND index_name = 'idx_usage_upstream_request');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE usage_records ADD INDEX idx_usage_pending_stage (aggregated_at, events_emitted_at)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'usage_records' AND index_name = 'idx_usage_pending_stage');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE alert_events ADD INDEX alert_events_fingerprint_idx (fingerprint, status)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'alert_events' AND index_name = 'alert_events_fingerprint_idx');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Lifecycle states: retired keeps history explainable instead of deleting rows.
ALTER TABLE models MODIFY status ENUM('active','disabled','retired') NOT NULL DEFAULT 'active';
ALTER TABLE providers MODIFY status ENUM('active','disabled','retired') NOT NULL DEFAULT 'active';

-- Rows written before staged usage persistence were fully processed by the
-- legacy writer: mark them complete so resume logic leaves them alone.
UPDATE usage_records SET aggregated_at = created_at WHERE aggregated_at IS NULL;
UPDATE usage_records SET events_emitted_at = created_at WHERE events_emitted_at IS NULL;
UPDATE alert_events SET first_seen_at = created_at, last_seen_at = updated_at WHERE first_seen_at IS NULL;
UPDATE alert_events SET fingerprint = event_type WHERE fingerprint IS NULL;

-- Costs in currencies other than BILLING_BASE_CURRENCY are aggregated apart
-- and never summed with the base currency.
CREATE TABLE IF NOT EXISTS usage_cost_aggregates_day (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket_at DATETIME NOT NULL,
  department_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  api_key_id BIGINT UNSIGNED NULL,
  provider_id BIGINT UNSIGNED NULL,
  model_id BIGINT UNSIGNED NULL,
  currency CHAR(3) NOT NULL,
  request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_cost DECIMAL(24,8) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usage_cost_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalog change history: additions, price/capability changes and removals
-- detected by model sync, with before/after snapshots.
CREATE TABLE IF NOT EXISTS model_catalog_changes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL,
  model_id BIGINT UNSIGNED NULL,
  external_name VARCHAR(190) NOT NULL,
  change_type VARCHAR(40) NOT NULL,
  before_snapshot JSON NULL,
  after_snapshot JSON NULL,
  applied TINYINT(1) NOT NULL DEFAULT 0,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_catalog_changes_provider (provider_id, created_at),
  INDEX idx_catalog_changes_model (model_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alert notification deliveries with retry state.
CREATE TABLE IF NOT EXISTS alert_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alert_event_id BIGINT UNSIGNED NOT NULL,
  channel VARCHAR(40) NOT NULL,
  target VARCHAR(500) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  next_attempt_at TIMESTAMP NULL,
  delivered_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_alert_notifications_due (status, next_attempt_at),
  INDEX idx_alert_notifications_event (alert_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
