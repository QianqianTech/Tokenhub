-- Keyword retrieval without full scans (RAG-032).
-- * rag_vector_datasets.lexical_index_status: "ready" when every chunk of the dataset is in the
--   Qdrant sparse lexical collection. Datasets that already hold chunks start as "none" and use a
--   bounded scan until `php bin/rag-index.php backfill-lexical` (or reindex) has run.
-- * FULLTEXT (ngram) index on rag_chunks for installs that use the MySQL vector fallback.
--   MariaDB has no ngram parser, so the index is only created on MySQL.
SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_vector_datasets ADD COLUMN lexical_index_status ENUM(''none'',''building'',''ready'') NOT NULL DEFAULT ''ready'' AFTER acl_mode', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_vector_datasets' AND column_name = 'lexical_index_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE rag_vector_datasets d
SET d.lexical_index_status = 'none'
WHERE EXISTS (SELECT 1 FROM rag_vector_records r WHERE r.dataset_id = d.id AND r.modality = 'text' AND r.status = 'indexed')
  AND d.lexical_index_status = 'ready';

SET @sql = (SELECT IF(COUNT(*) = 0 AND VERSION() NOT LIKE '%MariaDB%', 'ALTER TABLE rag_chunks ADD FULLTEXT INDEX ft_rag_chunks_text (title, content) WITH PARSER ngram', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'rag_chunks' AND index_name = 'ft_rag_chunks_text');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
