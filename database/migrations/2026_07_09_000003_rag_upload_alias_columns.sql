SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_uploads ADD COLUMN file_hash CHAR(64) NOT NULL DEFAULT '''' AFTER sha256', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_uploads' AND column_name = 'file_hash');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE rag_uploads SET file_hash = sha256 WHERE file_hash = '';

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_uploads ADD COLUMN progress DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER progress_percent', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_uploads' AND column_name = 'progress');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE rag_uploads SET progress = progress_percent WHERE progress = 0 AND progress_percent <> 0;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_uploads ADD COLUMN stage VARCHAR(80) NULL AFTER current_stage', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_uploads' AND column_name = 'stage');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE rag_uploads SET stage = current_stage WHERE stage IS NULL AND current_stage IS NOT NULL;
