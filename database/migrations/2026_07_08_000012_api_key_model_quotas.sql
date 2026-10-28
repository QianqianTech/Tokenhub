CREATE TABLE IF NOT EXISTS api_key_model_quotas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id BIGINT UNSIGNED NOT NULL,
  model_id BIGINT UNSIGNED NOT NULL,
  period ENUM('minute','hour','day','month') NOT NULL DEFAULT 'day',
  request_limit BIGINT UNSIGNED NULL,
  input_token_limit BIGINT UNSIGNED NULL,
  output_token_limit BIGINT UNSIGNED NULL,
  total_token_limit BIGINT UNSIGNED NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_api_key_model_quota_period (api_key_id, model_id, period),
  INDEX idx_api_key_model_quotas_key (api_key_id),
  INDEX idx_api_key_model_quotas_model (model_id),
  INDEX idx_api_key_model_quotas_status (status),
  CONSTRAINT fk_api_key_model_quotas_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE,
  CONSTRAINT fk_api_key_model_quotas_model FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
