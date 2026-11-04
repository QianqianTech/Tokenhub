-- BIZ-020 / BIZ-021: asynchronous task polling and upstream cancellation.
--   next_poll_at            when tasks.poll may poll the task again; a failed
--                           poll pushes it back instead of keeping it at the
--                           head of the queue.
--   poll_failures           consecutive failed polls (back-off).
--   upstream_cancel_status  outcome of the vendor cancel call made when the
--                           task timed out: cancelled / refused / unsupported / error.
--   upstream_final_status   vendor-side status seen when TokenHub gave up on
--                           the task (shows tasks the vendor still billed).
-- Idempotent: every column / index is added only when missing.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD COLUMN next_poll_at DATETIME NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'next_poll_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD COLUMN poll_failures INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'poll_failures');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD COLUMN upstream_cancel_status VARCHAR(40) NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'upstream_cancel_status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD COLUMN upstream_final_status VARCHAR(40) NULL', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND column_name = 'upstream_final_status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE multimodal_tasks ADD INDEX idx_multimodal_poll (status, next_poll_at)', 'SELECT 1') FROM information_schema.statistics WHERE table_schema = @schema_name AND table_name = 'multimodal_tasks' AND index_name = 'idx_multimodal_poll');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
