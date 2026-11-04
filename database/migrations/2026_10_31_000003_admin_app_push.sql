-- TokenHub APP Web Push subscriptions (one per APP device). Alerts are queued
-- in alert_notifications with channel = 'web_push' and target 'subscription:<id>'.
-- Idempotent: CREATE TABLE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS admin_app_push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NOT NULL,
  endpoint VARCHAR(1000) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(200) NOT NULL,
  auth VARCHAR(100) NOT NULL,
  last_success_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_admin_app_push_endpoint (endpoint_hash),
  UNIQUE KEY uniq_admin_app_push_device (device_id),
  KEY idx_admin_app_push_user (user_id),
  CONSTRAINT fk_admin_app_push_device FOREIGN KEY (device_id) REFERENCES admin_app_devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_admin_app_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
