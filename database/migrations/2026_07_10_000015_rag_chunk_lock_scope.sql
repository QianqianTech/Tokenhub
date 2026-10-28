SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE rag_chunks DROP INDEX uq_rag_chunks_document_ordinal', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'rag_chunks' AND index_name = 'uq_rag_chunks_document_ordinal');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
