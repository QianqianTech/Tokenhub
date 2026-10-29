-- RAG knowledge-set access control (RAG-010).
-- * bindings gain include_descendants / expires_at / granted_by_user_id / updated_at
-- * datasets gain acl_mode: inherit (follows the knowledge base ACL, dataset grants add to it)
--   or custom (only dataset grants count)
-- * chunks and documents carry dataset_id so retrieval no longer joins through rag_uploads
-- * documents carry a document_key used for versioned re-uploads
-- Every statement is guarded so the file can be re-run.
SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_knowledge_base_bindings ADD COLUMN include_descendants TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_knowledge_base_bindings' AND column_name = 'include_descendants');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_knowledge_base_bindings ADD COLUMN expires_at DATETIME NULL AFTER include_descendants', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_knowledge_base_bindings' AND column_name = 'expires_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_knowledge_base_bindings ADD COLUMN granted_by_user_id BIGINT UNSIGNED NULL AFTER expires_at', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_knowledge_base_bindings' AND column_name = 'granted_by_user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_knowledge_base_bindings ADD COLUMN updated_at DATETIME NULL AFTER created_at', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_knowledge_base_bindings' AND column_name = 'updated_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_dataset_bindings ADD COLUMN include_descendants TINYINT(1) NOT NULL DEFAULT 1 AFTER priority', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_dataset_bindings' AND column_name = 'include_descendants');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_dataset_bindings ADD COLUMN expires_at DATETIME NULL AFTER include_descendants', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_dataset_bindings' AND column_name = 'expires_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_dataset_bindings ADD COLUMN granted_by_user_id BIGINT UNSIGNED NULL AFTER expires_at', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_dataset_bindings' AND column_name = 'granted_by_user_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_dataset_bindings ADD COLUMN updated_at DATETIME NULL AFTER created_at', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_dataset_bindings' AND column_name = 'updated_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_vector_datasets ADD COLUMN acl_mode ENUM(''inherit'',''custom'') NOT NULL DEFAULT ''inherit'' AFTER status', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_vector_datasets' AND column_name = 'acl_mode');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Before this migration a dataset was readable only when the subject held BOTH a knowledge
-- base binding and a dataset binding. Datasets where some knowledge-base subject has no
-- dataset binding keep that narrower access by switching to custom mode.
UPDATE rag_vector_datasets d
SET d.acl_mode = 'custom'
WHERE d.acl_mode = 'inherit'
  AND EXISTS (
    SELECT 1 FROM rag_knowledge_base_bindings kb
    WHERE kb.kb_id = d.kb_id
      AND NOT EXISTS (
        SELECT 1 FROM rag_dataset_bindings db
        WHERE db.dataset_id = d.id AND db.subject_type = kb.subject_type AND db.subject_id = kb.subject_id
      )
  );

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN dataset_id BIGINT UNSIGNED NULL AFTER kb_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_documents' AND column_name = 'dataset_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN document_key VARCHAR(255) NULL AFTER title', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_documents' AND column_name = 'document_key');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD INDEX idx_rag_docs_dataset_key (dataset_id, document_key, deleted_at)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'rag_documents' AND index_name = 'idx_rag_docs_dataset_key');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_chunks ADD COLUMN dataset_id BIGINT UNSIGNED NULL AFTER kb_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_chunks' AND column_name = 'dataset_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_chunks ADD INDEX idx_rag_chunks_dataset (dataset_id, lifecycle_status, enabled)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'rag_chunks' AND index_name = 'idx_rag_chunks_dataset');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE rag_documents doc
JOIN rag_uploads u ON u.id = doc.upload_id
SET doc.dataset_id = u.dataset_id
WHERE doc.dataset_id IS NULL AND u.dataset_id IS NOT NULL;

UPDATE rag_documents doc
JOIN rag_uploads u ON u.id = doc.upload_id
SET doc.document_key = LEFT(COALESCE(NULLIF(doc.source_uri, ''), CONCAT('upload:', u.upload_id, ':', COALESCE(doc.source_preview_item_id, doc.document_id))), 255)
WHERE doc.document_key IS NULL;

UPDATE rag_chunks c
JOIN rag_documents doc ON doc.id = c.document_id
SET c.dataset_id = doc.dataset_id
WHERE c.dataset_id IS NULL AND doc.dataset_id IS NOT NULL;
