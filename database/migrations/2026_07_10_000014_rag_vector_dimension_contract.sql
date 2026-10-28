SET @schema_name = DATABASE();

UPDATE rag_vector_configs SET dimension = 1 WHERE dimension < 1;
UPDATE rag_vector_records SET dimension = 1 WHERE dimension < 1;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_vector_configs ADD CONSTRAINT chk_rag_vector_config_dimension CHECK (dimension >= 1)', 'SELECT 1') FROM information_schema.table_constraints WHERE constraint_schema = @schema_name AND table_name = 'rag_vector_configs' AND constraint_name = 'chk_rag_vector_config_dimension');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_vector_records ADD CONSTRAINT chk_rag_vector_record_dimension CHECK (dimension >= 1)', 'SELECT 1') FROM information_schema.table_constraints WHERE constraint_schema = @schema_name AND table_name = 'rag_vector_records' AND constraint_name = 'chk_rag_vector_record_dimension');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
