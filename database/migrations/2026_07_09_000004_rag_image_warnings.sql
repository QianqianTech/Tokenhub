SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_image_assets ADD COLUMN warnings JSON NULL AFTER safety_flags', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_image_assets' AND column_name = 'warnings');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
