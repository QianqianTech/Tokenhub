SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD COLUMN detection_mode VARCHAR(16) NULL AFTER action', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'waf_events' AND column_name = 'detection_mode');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD COLUMN risk_score INT UNSIGNED NULL AFTER detection_mode', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'waf_events' AND column_name = 'risk_score');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD COLUMN attack_categories JSON NULL AFTER risk_score', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'waf_events' AND column_name = 'attack_categories');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD INDEX idx_waf_events_detection (detection_mode, risk_score, created_at)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'waf_events' AND index_name = 'idx_waf_events_detection');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
