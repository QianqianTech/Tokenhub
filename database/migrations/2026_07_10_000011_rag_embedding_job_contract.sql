SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_embedding_jobs ADD COLUMN vector_config_id BIGINT UNSIGNED NULL AFTER chunk_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_embedding_jobs' AND column_name = 'vector_config_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_embedding_jobs ADD COLUMN execution_backend ENUM(''php_provider'',''python_local'') NOT NULL DEFAULT ''python_local'' AFTER job_type', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_embedding_jobs' AND column_name = 'execution_backend');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_embedding_jobs ADD INDEX idx_rag_jobs_backend_status (execution_backend, status, next_run_at)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'rag_embedding_jobs' AND index_name = 'idx_rag_jobs_backend_status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE rag_embedding_jobs j
LEFT JOIN rag_vector_datasets d ON d.id = j.dataset_id
LEFT JOIN rag_vector_configs c ON c.id = CASE
  WHEN j.job_type = 'image_embedding' THEN d.image_vector_config_id
  WHEN j.job_type = 'multimodal_embedding' THEN d.multimodal_vector_config_id
  ELSE d.text_vector_config_id
END
SET j.vector_config_id = COALESCE(j.vector_config_id, c.id),
    j.execution_backend = IF(c.provider_id IS NOT NULL AND c.model_id IS NOT NULL, 'php_provider', 'python_local');
