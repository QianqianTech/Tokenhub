SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_vector_records ADD COLUMN status ENUM(''indexed'',''stale'',''deleted'',''failed'') NOT NULL DEFAULT ''indexed'' AFTER index_status', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_vector_records' AND column_name = 'status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE rag_vector_records SET status = index_status;
