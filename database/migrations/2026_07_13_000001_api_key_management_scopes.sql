CREATE TABLE IF NOT EXISTS api_key_management_scopes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id BIGINT UNSIGNED NOT NULL,
  scope VARCHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY api_key_management_scopes_key_scope_unique (api_key_id, scope),
  INDEX idx_api_key_management_scopes_scope (scope),
  CONSTRAINT fk_api_key_management_scopes_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
