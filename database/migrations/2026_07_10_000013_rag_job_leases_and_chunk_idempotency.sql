SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN source_preview_item_id VARCHAR(80) NULL AFTER upload_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_documents' AND column_name = 'source_preview_item_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE rag_documents
SET source_preview_item_id = JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.source_preview_item_id'))
WHERE source_preview_item_id IS NULL
  AND JSON_EXTRACT(metadata, '$.source_preview_item_id') IS NOT NULL;

UPDATE rag_documents duplicate_document
JOIN (
  SELECT upload_id, source_preview_item_id, MIN(id) AS keeper_id
  FROM rag_documents
  WHERE source_preview_item_id IS NOT NULL
  GROUP BY upload_id, source_preview_item_id
  HAVING COUNT(*) > 1
) duplicates
  ON duplicates.upload_id = duplicate_document.upload_id
 AND duplicates.source_preview_item_id = duplicate_document.source_preview_item_id
SET duplicate_document.source_preview_item_id = NULL
WHERE duplicate_document.id <> duplicates.keeper_id;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD UNIQUE INDEX uq_rag_docs_upload_preview (upload_id, source_preview_item_id)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'rag_documents' AND index_name = 'uq_rag_docs_upload_preview');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_embedding_jobs ADD COLUMN lock_token VARCHAR(64) NULL AFTER locked_at', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_embedding_jobs' AND column_name = 'lock_token');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_embedding_jobs ADD COLUMN dispatched_at DATETIME NULL AFTER next_run_at', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_embedding_jobs' AND column_name = 'dispatched_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
