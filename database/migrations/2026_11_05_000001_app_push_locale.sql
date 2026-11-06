-- Persist the language of each device's notifications.
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_app_push_subscriptions ADD COLUMN locale VARCHAR(5) NOT NULL DEFAULT ''zh-CN''', 'SELECT 1') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admin_app_push_subscriptions' AND column_name = 'locale');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
