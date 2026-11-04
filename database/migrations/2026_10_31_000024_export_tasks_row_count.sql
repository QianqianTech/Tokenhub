-- BIZ-002: exports are written page by page (keyset pagination) and record how
-- many rows they contain; truncated = 1 (and error_code EXPORT_TRUNCATED) when
-- EXPORT_MAX_ROWS cut the export short, so missing rows are never silent.
-- Idempotent: columns are added only when missing.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE export_tasks ADD COLUMN row_count BIGINT UNSIGNED NULL AFTER error_code', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'export_tasks' AND column_name = 'row_count');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE export_tasks ADD COLUMN truncated TINYINT(1) NOT NULL DEFAULT 0 AFTER row_count', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'export_tasks' AND column_name = 'truncated');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
