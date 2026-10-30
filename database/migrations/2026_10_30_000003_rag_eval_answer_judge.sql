-- RAG-041: answer-level evaluation (judge model) result per evaluation case.
SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_eval_results ADD COLUMN judge JSON NULL AFTER sources', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_eval_results' AND column_name = 'judge');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
