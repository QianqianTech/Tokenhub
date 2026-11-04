-- TokenHub APP, install packages and device approval:
-- * admin_app_push_subscriptions.kind: 'webpush' (browser), 'ios' (APNs) or
--   'android' (FCM); native tokens are stored as endpoint "<kind>://<token>".
-- * admin_app_devices: optional approval of new devices in the web console
--   (APP_NEW_DEVICE_APPROVAL): status 'pending' with a short match code.
-- Idempotent: guarded ALTER TABLE only.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_app_push_subscriptions ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT ''webpush'' AFTER device_id', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'admin_app_push_subscriptions' AND column_name = 'kind');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_app_devices MODIFY COLUMN status ENUM(''pending'',''active'',''revoked'',''expired'') NOT NULL DEFAULT ''active''', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'admin_app_devices' AND column_name = 'status' AND column_type LIKE '%pending%');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE admin_app_devices ADD COLUMN approval_code CHAR(4) NULL AFTER status, ADD COLUMN approved_at DATETIME NULL AFTER approval_code, ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER approved_at', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'admin_app_devices' AND column_name = 'approval_code');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
