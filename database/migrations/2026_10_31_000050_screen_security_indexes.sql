-- Operations screen (SEC-003, SCR-002): the security panel reads today's
-- desensitization events grouped by action, and old screen access-log rows are
-- pruned by time. Idempotent: guarded ALTER TABLE only.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE desensitization_events ADD INDEX idx_desens_events_action_time (action, created_at)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'desensitization_events' AND index_name = 'idx_desens_events_action_time');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE dashboard_share_access_logs ADD INDEX idx_share_access_time (created_at)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'dashboard_share_access_logs' AND index_name = 'idx_share_access_time');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
