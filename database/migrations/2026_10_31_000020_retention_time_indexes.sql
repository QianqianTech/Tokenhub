-- BIZ-025 / BIZ-009 / BIZ-006: indexes for time-based purges and cleanups.
-- Every retention job deletes "WHERE <time column> < cutoff LIMIT n"; without an
-- index whose first column is the time column each batch is a full table scan.
-- Idempotent: an index is added only when the table exists and no index starts
-- with that column yet.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @schema_name AND table_name = 'audit_logs') = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'created_at' AND seq_in_index = 1) = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_created_at (created_at)', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @schema_name AND table_name = 'external_api_events') = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'external_api_events' AND column_name = 'created_at' AND seq_in_index = 1) = 0, 'ALTER TABLE external_api_events ADD INDEX idx_external_api_events_created_at (created_at)', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @schema_name AND table_name = 'external_webhook_deliveries') = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'external_webhook_deliveries' AND column_name = 'created_at' AND seq_in_index = 1) = 0, 'ALTER TABLE external_webhook_deliveries ADD INDEX idx_external_webhook_deliveries_created_at (created_at)', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @schema_name AND table_name = 'alert_notifications') = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'alert_notifications' AND column_name = 'created_at' AND seq_in_index = 1) = 0, 'ALTER TABLE alert_notifications ADD INDEX idx_alert_notifications_created_at (created_at)', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @schema_name AND table_name = 'alert_events') = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'alert_events' AND column_name = 'created_at' AND seq_in_index = 1) = 0, 'ALTER TABLE alert_events ADD INDEX alert_events_created_idx (created_at)', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @schema_name AND table_name = 'dashboard_share_access_logs') = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'dashboard_share_access_logs' AND column_name = 'created_at' AND seq_in_index = 1) = 0, 'ALTER TABLE dashboard_share_access_logs ADD INDEX idx_share_access_created_at (created_at)', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @schema_name AND table_name = 'rag_retrieval_logs') = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'rag_retrieval_logs' AND column_name = 'created_at' AND seq_in_index = 1) = 0, 'ALTER TABLE rag_retrieval_logs ADD INDEX idx_rag_retrieval_created_at (created_at)', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @schema_name AND table_name = 'media_assets') = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'media_assets' AND column_name = 'expires_at' AND seq_in_index = 1) = 0, 'ALTER TABLE media_assets ADD INDEX idx_media_assets_expires_at (expires_at)', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @schema_name AND table_name = 'usage_records') = 1 AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'usage_records' AND column_name = 'created_at' AND seq_in_index = 1) = 0, 'ALTER TABLE usage_records ADD INDEX idx_usage_time (created_at)', 'SELECT 1'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
