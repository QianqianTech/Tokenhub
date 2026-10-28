CREATE TABLE IF NOT EXISTS api_key_management_delegations (
  child_api_key_id BIGINT UNSIGNED NOT NULL,
  parent_api_key_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (child_api_key_id),
  INDEX idx_api_key_management_delegations_parent (parent_api_key_id),
  CONSTRAINT fk_api_key_management_delegations_child FOREIGN KEY (child_api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE,
  CONSTRAINT fk_api_key_management_delegations_parent FOREIGN KEY (parent_api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
