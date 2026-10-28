SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_uploads ADD COLUMN review_status ENUM(''not_required'',''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''not_required'' AFTER confirm_required', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_uploads' AND column_name = 'review_status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_uploads ADD COLUMN reviewed_by_user_id BIGINT UNSIGNED NULL AFTER review_status', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_uploads' AND column_name = 'reviewed_by_user_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_uploads ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by_user_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_uploads' AND column_name = 'reviewed_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_uploads ADD COLUMN review_comment VARCHAR(500) NULL AFTER reviewed_at', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'rag_uploads' AND column_name = 'review_comment');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE rag_uploads MODIFY COLUMN status ENUM('uploaded','previewing','waiting_review','waiting_confirm','vectorizing','indexed','partial_failed','failed','rejected','deleted') NOT NULL DEFAULT 'uploaded';
