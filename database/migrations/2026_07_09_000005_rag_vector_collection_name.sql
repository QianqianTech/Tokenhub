SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_vector_records ADD COLUMN collection_name VARCHAR(160) NOT NULL DEFAULT '''' AFTER vector_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_vector_records' AND column_name = 'collection_name');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE rag_vector_records SET collection_name = CONCAT('tokenhub_rag_', dataset_id, '_text') WHERE collection_name = '';
