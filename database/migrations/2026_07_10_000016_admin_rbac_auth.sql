ALTER TABLE users
  ADD COLUMN admin_type VARCHAR(32) NULL AFTER role,
  ADD COLUMN admin_scope ENUM('global', 'departments') NULL AFTER managed_department_ids,
  ADD COLUMN admin_permission_mode ENUM('preset', 'custom') NOT NULL DEFAULT 'preset' AFTER admin_scope;

UPDATE users
SET admin_type = CASE role
  WHEN 'super_admin' THEN 'super_admin'
  WHEN 'admin' THEN 'full_admin'
  ELSE NULL
END,
admin_scope = CASE
  WHEN role IN ('super_admin', 'admin') THEN 'global'
  ELSE NULL
END;

CREATE TABLE IF NOT EXISTS admin_user_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  permission_code VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY admin_user_permissions_user_code_unique (user_id, permission_code),
  INDEX idx_admin_user_permissions_code (permission_code),
  CONSTRAINT fk_admin_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
