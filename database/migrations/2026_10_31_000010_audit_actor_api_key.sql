-- SEC-110: audit rows record the API key behind an Agent-management or other
-- API-key initiated write (actor_api_key_id). Idempotent: guarded ALTER only.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD COLUMN actor_api_key_id BIGINT UNSIGNED NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND column_name = 'actor_api_key_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_actor_api_key (actor_api_key_id)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'audit_logs' AND index_name = 'idx_audit_logs_actor_api_key');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
