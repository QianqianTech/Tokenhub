SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_answer_feedback ADD COLUMN retrieval_id VARCHAR(64) NULL AFTER feedback_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_answer_feedback' AND column_name = 'retrieval_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_answer_feedback ADD COLUMN reason TEXT NULL AFTER rating', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_answer_feedback' AND column_name = 'reason');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_answer_feedback ADD COLUMN selected_source_ids JSON NULL AFTER source_chunk_ids', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_answer_feedback' AND column_name = 'selected_source_ids');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_answer_feedback ADD COLUMN expected_answer MEDIUMTEXT NULL AFTER selected_source_ids', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_answer_feedback' AND column_name = 'expected_answer');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_answer_feedback ADD COLUMN metadata JSON NULL AFTER suggested_answer', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_answer_feedback' AND column_name = 'metadata');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_correction_tasks ADD COLUMN task_id VARCHAR(64) NULL UNIQUE AFTER correction_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_correction_tasks' AND column_name = 'task_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_correction_tasks ADD COLUMN feedback_id VARCHAR(64) NULL AFTER task_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_correction_tasks' AND column_name = 'feedback_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_correction_tasks ADD COLUMN retrieval_id VARCHAR(64) NULL AFTER feedback_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_correction_tasks' AND column_name = 'retrieval_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_correction_tasks ADD COLUMN kb_ids JSON NULL AFTER retrieval_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_correction_tasks' AND column_name = 'kb_ids');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_correction_tasks ADD COLUMN dataset_ids JSON NULL AFTER kb_ids', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_correction_tasks' AND column_name = 'dataset_ids');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_correction_tasks ADD COLUMN issue_type VARCHAR(80) NULL AFTER dataset_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_correction_tasks' AND column_name = 'issue_type');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE rag_correction_tasks MODIFY COLUMN status ENUM('open','pending','approved','running','succeeded','failed','cancelled') NOT NULL DEFAULT 'pending';

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_correction_tasks ADD COLUMN priority INT NOT NULL DEFAULT 50 AFTER status', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_correction_tasks' AND column_name = 'priority');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_correction_tasks ADD COLUMN details JSON NULL AFTER reason', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_correction_tasks' AND column_name = 'details');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
