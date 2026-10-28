SET @schema_name = DATABASE();

UPDATE rag_embedding_jobs keeper
JOIN (
  SELECT upload_id, chunk_id, job_type,
         MAX(attempts) AS merged_attempts,
         MAX(processed_items) AS merged_processed_items,
         MAX(progress_percent) AS merged_progress_percent
  FROM rag_embedding_jobs
  WHERE upload_id IS NOT NULL AND chunk_id IS NOT NULL
  GROUP BY upload_id, chunk_id, job_type
  HAVING COUNT(*) > 1
) merged
  ON merged.upload_id = keeper.upload_id
 AND merged.chunk_id = keeper.chunk_id
 AND merged.job_type = keeper.job_type
SET keeper.attempts = GREATEST(keeper.attempts, merged.merged_attempts),
    keeper.processed_items = GREATEST(keeper.processed_items, merged.merged_processed_items),
    keeper.progress_percent = GREATEST(keeper.progress_percent, merged.merged_progress_percent);

DELETE duplicate_job
FROM rag_embedding_jobs duplicate_job
JOIN rag_embedding_jobs keeper
  ON keeper.upload_id = duplicate_job.upload_id
 AND keeper.chunk_id = duplicate_job.chunk_id
 AND keeper.job_type = duplicate_job.job_type
 AND (
   CASE keeper.status
     WHEN 'succeeded' THEN 4
     WHEN 'running' THEN 3
     WHEN 'retrying' THEN 3
     WHEN 'queued' THEN 2
     WHEN 'failed' THEN 1
     ELSE 0
   END > CASE duplicate_job.status
     WHEN 'succeeded' THEN 4
     WHEN 'running' THEN 3
     WHEN 'retrying' THEN 3
     WHEN 'queued' THEN 2
     WHEN 'failed' THEN 1
     ELSE 0
   END
   OR (
     CASE keeper.status
       WHEN 'succeeded' THEN 4
       WHEN 'running' THEN 3
       WHEN 'retrying' THEN 3
       WHEN 'queued' THEN 2
       WHEN 'failed' THEN 1
       ELSE 0
     END = CASE duplicate_job.status
       WHEN 'succeeded' THEN 4
       WHEN 'running' THEN 3
       WHEN 'retrying' THEN 3
       WHEN 'queued' THEN 2
       WHEN 'failed' THEN 1
       ELSE 0
     END
     AND keeper.id < duplicate_job.id
   )
 )
WHERE duplicate_job.upload_id IS NOT NULL
  AND duplicate_job.chunk_id IS NOT NULL;

SET @sql = (SELECT IF(
  COUNT(*) = 0,
  'ALTER TABLE rag_embedding_jobs ADD UNIQUE INDEX uq_rag_jobs_upload_chunk_type (upload_id, chunk_id, job_type)',
  'SELECT 1'
) FROM information_schema.statistics
  WHERE table_schema = @schema_name
    AND table_name = 'rag_embedding_jobs'
    AND index_name = 'uq_rag_jobs_upload_chunk_type');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
