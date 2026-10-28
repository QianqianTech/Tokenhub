CREATE TABLE IF NOT EXISTS external_api_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id VARCHAR(80) NOT NULL UNIQUE,
  api_key_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(120) NOT NULL,
  source VARCHAR(32) NOT NULL,
  resource_id VARCHAR(120) NULL,
  status VARCHAR(32) NOT NULL,
  summary JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_external_api_events_key_id (api_key_id, id),
  CONSTRAINT fk_external_api_events_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
