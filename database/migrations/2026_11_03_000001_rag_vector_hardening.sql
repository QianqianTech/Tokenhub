-- RAG vector hardening (V-04, V-07).
-- * rag_vector_records (content_hash, vector_config_id): vector reuse looks up an identical
--   embedding input for every indexed chunk; without this index each lookup scanned the whole
--   table (including the JSON vector column).
-- * rag_retrieval_logs.vector_status: "ok", "degraded" (some vector searches failed and the
--   result fell back to keyword retrieval for them), "error" (every vector search failed) or
--   "none" (no vector config). Makes a broken embedding model visible instead of silent.
SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_vector_records ADD INDEX idx_rag_vectors_reuse (content_hash, vector_config_id)', 'DO 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'rag_vector_records' AND index_name = 'idx_rag_vectors_reuse');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_retrieval_logs ADD COLUMN vector_status VARCHAR(16) NULL AFTER fallback_used', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_retrieval_logs' AND column_name = 'vector_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
