-- BIZ-019: deliveries of a deleted / disabled webhook (or a deleted API key)
-- are marked "cancelled" instead of being retried.
-- Idempotent: the ENUM is widened only while it lacks 'cancelled'.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 1, 'ALTER TABLE external_webhook_deliveries MODIFY COLUMN status ENUM(''pending'',''succeeded'',''failed'',''dead'',''cancelled'') NOT NULL DEFAULT ''pending''', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'external_webhook_deliveries' AND column_name = 'status' AND column_type NOT LIKE '%cancelled%');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
