CREATE TABLE IF NOT EXISTS system_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(160) NOT NULL UNIQUE,
  setting_value JSON NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  path VARCHAR(500) NOT NULL DEFAULT '/',
  level INT UNSIGNED NOT NULL DEFAULT 0,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_departments_parent (parent_id),
  INDEX idx_departments_path (path),
  CONSTRAINT fk_departments_parent FOREIGN KEY (parent_id) REFERENCES departments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_id BIGINT UNSIGNED NOT NULL,
  username VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','admin','user') NOT NULL DEFAULT 'user',
  status ENUM('active','disabled','departed') NOT NULL DEFAULT 'active',
  managed_department_ids JSON NULL,
  last_login_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_department (department_id),
  CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  session_hash CHAR(64) NOT NULL UNIQUE,
  csrf_token_hash CHAR(64) NOT NULL,
  client_ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  expires_at TIMESTAMP NOT NULL,
  revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_admin_sessions_user (user_id),
  CONSTRAINT fk_admin_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_type VARCHAR(40) NOT NULL DEFAULT 'system',
  action VARCHAR(120) NOT NULL,
  target_type VARCHAR(120) NULL,
  target_id VARCHAR(120) NULL,
  request_id VARCHAR(80) NULL,
  trace_id VARCHAR(80) NULL,
  client_ip VARCHAR(64) NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_actor (actor_user_id),
  INDEX idx_audit_action (action),
  INDEX idx_audit_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS providers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  protocol ENUM('openai','anthropic','native') NOT NULL,
  base_url VARCHAR(500) NOT NULL,
  encrypted_secret TEXT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  timeout_ms INT UNSIGNED NOT NULL DEFAULT 60000,
  retry_count INT UNSIGNED NOT NULL DEFAULT 1,
  default_headers JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_providers_protocol_status (protocol, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS models (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  external_name VARCHAR(190) NOT NULL,
  capability ENUM('text_generation','vision_understanding','embedding','asr','tts','image_generation','video_generation') NOT NULL,
  context_length INT UNSIGNED NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  route_priority INT UNSIGNED NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_models_provider_name (provider_id, name),
  INDEX idx_models_name_status (name, status),
  CONSTRAINT fk_models_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  model_id BIGINT UNSIGNED NOT NULL,
  input_token_price DECIMAL(18,8) NULL,
  output_token_price DECIMAL(18,8) NULL,
  audio_second_price DECIMAL(18,8) NULL,
  tts_char_price DECIMAL(18,8) NULL,
  image_price DECIMAL(18,8) NULL,
  video_second_price DECIMAL(18,8) NULL,
  request_price DECIMAL(18,8) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  effective_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_model_prices_model_time (model_id, effective_at),
  CONSTRAINT fk_model_prices_model FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  department_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  key_prefix VARCHAR(24) NOT NULL,
  key_suffix VARCHAR(12) NOT NULL,
  key_hash CHAR(64) NOT NULL UNIQUE,
  allowed_ip_cidrs JSON NULL,
  status ENUM('active','disabled','deleted') NOT NULL DEFAULT 'active',
  expires_at TIMESTAMP NULL,
  last_used_at TIMESTAMP NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_api_keys_user (user_id),
  INDEX idx_api_keys_department (department_id),
  CONSTRAINT fk_api_keys_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_api_keys_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_key_model_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id BIGINT UNSIGNED NOT NULL,
  model_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_key_model (api_key_id, model_id),
  CONSTRAINT fk_key_model_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE,
  CONSTRAINT fk_key_model_model FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS quota_policies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  period ENUM('minute','hour','day','month') NOT NULL DEFAULT 'day',
  request_limit BIGINT UNSIGNED NULL,
  input_token_limit BIGINT UNSIGNED NULL,
  output_token_limit BIGINT UNSIGNED NULL,
  total_token_limit BIGINT UNSIGNED NULL,
  audio_second_limit BIGINT UNSIGNED NULL,
  tts_char_limit BIGINT UNSIGNED NULL,
  image_limit BIGINT UNSIGNED NULL,
  video_second_limit BIGINT UNSIGNED NULL,
  cost_limit DECIMAL(18,8) NULL,
  concurrency_limit INT UNSIGNED NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quota_bindings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quota_policy_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('api_key','user','department') NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_quota_bindings_target (target_type, target_id),
  CONSTRAINT fk_quota_bindings_policy FOREIGN KEY (quota_policy_id) REFERENCES quota_policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id VARCHAR(80) NOT NULL,
  trace_id VARCHAR(80) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  department_id BIGINT UNSIGNED NULL,
  api_key_id BIGINT UNSIGNED NULL,
  provider_id BIGINT UNSIGNED NULL,
  model_id BIGINT UNSIGNED NULL,
  model_name VARCHAR(190) NULL,
  protocol VARCHAR(40) NOT NULL,
  endpoint VARCHAR(160) NOT NULL,
  capability VARCHAR(80) NOT NULL DEFAULT 'text_generation',
  status ENUM('success','failed','blocked','timeout') NOT NULL,
  http_status INT UNSIGNED NULL,
  error_code VARCHAR(80) NULL,
  input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_read_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_write_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  audio_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tts_chars BIGINT UNSIGNED NOT NULL DEFAULT 0,
  image_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  video_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cost_amount DECIMAL(18,8) NULL,
  price_snapshot JSON NULL,
  latency_ms INT UNSIGNED NULL,
  client_ip VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usage_request (request_id),
  INDEX idx_usage_time (created_at),
  INDEX idx_usage_scope (department_id, user_id, api_key_id),
  INDEX idx_usage_model (model_id, provider_id),
  INDEX idx_usage_status (status, error_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_aggregates_minute (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket_at DATETIME NOT NULL,
  department_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  api_key_id BIGINT UNSIGNED NULL,
  provider_id BIGINT UNSIGNED NULL,
  model_id BIGINT UNSIGNED NULL,
  capability VARCHAR(80) NOT NULL,
  request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  success_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  failed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_read_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_write_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_cost DECIMAL(18,8) NULL,
  latency_ms_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
  latency_bucket_100 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  latency_bucket_300 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  latency_bucket_1000 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  latency_bucket_3000 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  latency_bucket_over_3000 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usage_minute (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_aggregates_hour LIKE usage_aggregates_minute;
CREATE TABLE IF NOT EXISTS usage_aggregates_day LIKE usage_aggregates_minute;

CREATE TABLE IF NOT EXISTS multimodal_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id VARCHAR(80) NOT NULL UNIQUE,
  task_type ENUM('asr','tts','image_generation','video_generation') NOT NULL,
  status ENUM('queued','processing','succeeded','failed','expired') NOT NULL DEFAULT 'queued',
  provider_task_id VARCHAR(190) NULL,
  user_id BIGINT UNSIGNED NULL,
  department_id BIGINT UNSIGNED NULL,
  api_key_id BIGINT UNSIGNED NULL,
  provider_id BIGINT UNSIGNED NULL,
  model_id BIGINT UNSIGNED NULL,
  result JSON NULL,
  error_code VARCHAR(80) NULL,
  error_message VARCHAR(255) NULL,
  latency_ms INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_multimodal_status (status, task_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_id VARCHAR(80) NOT NULL UNIQUE,
  task_id BIGINT UNSIGNED NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  department_id BIGINT UNSIGNED NULL,
  media_type ENUM('image','audio','video','file') NOT NULL,
  storage_path VARCHAR(800) NOT NULL,
  mime_type VARCHAR(120) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  duration_seconds BIGINT UNSIGNED NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  expires_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_media_owner (owner_user_id, department_id),
  CONSTRAINT fk_media_task FOREIGN KEY (task_id) REFERENCES multimodal_tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_contents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usage_record_id BIGINT UNSIGNED NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NULL,
  department_id BIGINT UNSIGNED NULL,
  api_key_id BIGINT UNSIGNED NULL,
  model_id BIGINT UNSIGNED NULL,
  session_id VARCHAR(120) NULL,
  retention_mode ENUM('none','summary','redacted','raw') NOT NULL DEFAULT 'summary',
  encrypted_input MEDIUMTEXT NULL,
  encrypted_output MEDIUMTEXT NULL,
  redacted_input MEDIUMTEXT NULL,
  redacted_output MEDIUMTEXT NULL,
  summary TEXT NULL,
  expires_at TIMESTAMP NULL,
  cleaned_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_usage_contents_user (user_id, created_at),
  INDEX idx_usage_contents_department (department_id, created_at),
  INDEX idx_usage_contents_api_key (api_key_id, created_at),
  INDEX idx_usage_contents_model (model_id, created_at),
  INDEX idx_usage_contents_session (session_id),
  INDEX idx_usage_contents_expires (expires_at),
  CONSTRAINT fk_usage_contents_record FOREIGN KEY (usage_record_id) REFERENCES usage_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS export_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  export_id VARCHAR(80) NOT NULL UNIQUE,
  requested_by BIGINT UNSIGNED NULL,
  export_type ENUM('usage','session_archive','logs') NOT NULL,
  status ENUM('queued','processing','succeeded','failed','expired') NOT NULL DEFAULT 'queued',
  format ENUM('csv') NOT NULL DEFAULT 'csv',
  filters JSON NULL,
  file_path VARCHAR(800) NULL,
  error_code VARCHAR(80) NULL,
  download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at TIMESTAMP NULL,
  finished_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_export_status (status, export_type),
  INDEX idx_export_requested (requested_by, created_at),
  INDEX idx_export_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_share_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  share_code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  username VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  scope_type ENUM('global','department','user','api_key') NOT NULL DEFAULT 'global',
  scope_ids JSON NULL,
  allowed_metrics JSON NULL,
  allow_drilldown TINYINT(1) NOT NULL DEFAULT 0,
  allowed_ip_cidrs JSON NULL,
  expires_at TIMESTAMP NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  session_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_share_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  share_link_id BIGINT UNSIGNED NOT NULL,
  session_hash CHAR(64) NOT NULL UNIQUE,
  session_version BIGINT UNSIGNED NOT NULL,
  client_ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_share_sessions_link FOREIGN KEY (share_link_id) REFERENCES dashboard_share_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_share_access_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  share_link_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  client_ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  reason VARCHAR(160) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_share_access_link (share_link_id, event_type),
  CONSTRAINT fk_share_access_link FOREIGN KEY (share_link_id) REFERENCES dashboard_share_links(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waf_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_code VARCHAR(120) NOT NULL UNIQUE,
  scope VARCHAR(80) NOT NULL DEFAULT 'global',
  matcher JSON NOT NULL,
  action ENUM('block','redact','remove_header','allow') NOT NULL DEFAULT 'block',
  priority INT UNSIGNED NOT NULL DEFAULT 100,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waf_rule_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_code VARCHAR(80) NOT NULL UNIQUE,
  rules_snapshot JSON NOT NULL,
  caddyfile_fragment MEDIUMTEXT NOT NULL,
  status ENUM('draft','published','rolled_back','failed') NOT NULL DEFAULT 'draft',
  published_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waf_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_code VARCHAR(120) NULL,
  request_id VARCHAR(80) NULL,
  client_ip VARCHAR(64) NULL,
  method VARCHAR(16) NULL,
  path VARCHAR(500) NULL,
  action VARCHAR(40) NOT NULL,
  summary JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_waf_events_time (created_at),
  INDEX idx_waf_events_rule (rule_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desensitization_policies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  scope_type ENUM('system','department','api_key','model','provider') NOT NULL DEFAULT 'system',
  scope_id BIGINT UNSIGNED NULL,
  parsers JSON NOT NULL,
  action ENUM('block','replace','summary') NOT NULL DEFAULT 'replace',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_desens_scope (scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS desensitization_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  policy_id BIGINT UNSIGNED NULL,
  request_id VARCHAR(80) NULL,
  target_type VARCHAR(80) NOT NULL,
  action VARCHAR(40) NOT NULL,
  match_count INT UNSIGNED NOT NULL DEFAULT 0,
  summary JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_desens_events_time (created_at),
  CONSTRAINT fk_desens_events_policy FOREIGN KEY (policy_id) REFERENCES desensitization_policies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS log_retention_policies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  log_type VARCHAR(80) NOT NULL UNIQUE,
  online_retention_days INT UNSIGNED NOT NULL,
  archive_retention_days INT UNSIGNED NOT NULL,
  min_retention_days INT UNSIGNED NOT NULL DEFAULT 30,
  compress_enabled TINYINT(1) NOT NULL DEFAULT 1,
  encrypt_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @schema_name = DATABASE();

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE log_retention_policies ADD COLUMN online_retention_days INT UNSIGNED NOT NULL DEFAULT 30 AFTER log_type', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'log_retention_policies' AND column_name = 'online_retention_days'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE log_retention_policies ADD COLUMN archive_retention_days INT UNSIGNED NOT NULL DEFAULT 180 AFTER online_retention_days', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'log_retention_policies' AND column_name = 'archive_retention_days'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE log_retention_policies ADD COLUMN min_retention_days INT UNSIGNED NOT NULL DEFAULT 30 AFTER archive_retention_days', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'log_retention_policies' AND column_name = 'min_retention_days'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE log_retention_policies ADD COLUMN compress_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER min_retention_days', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'log_retention_policies' AND column_name = 'compress_enabled'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE log_retention_policies ADD COLUMN encrypt_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER compress_enabled', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'log_retention_policies' AND column_name = 'encrypt_enabled'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO departments (id, parent_id, name, path, level, sort_order, status)
VALUES (1, NULL, 'Root', '/1/', 0, 0, 'active');

INSERT IGNORE INTO log_retention_policies (log_type, online_retention_days, archive_retention_days, min_retention_days)
VALUES
('caddy-access', 30, 180, 30),
('caddy-security', 90, 365, 90),
('gateway-access', 30, 180, 30),
('provider', 30, 180, 30),
('usage', 90, 365, 30),
('audit', 180, 730, 180),
('security', 180, 730, 180),
('performance', 30, 180, 30),
('worker', 30, 180, 30),
('export', 90, 365, 90);
