CREATE TABLE IF NOT EXISTS system_ip_bans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_ip VARCHAR(64) NOT NULL,
  failure_count INT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(160) NOT NULL,
  banned_at TIMESTAMP NULL,
  last_failed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY system_ip_bans_client_ip_unique (client_ip),
  INDEX idx_system_ip_bans_active (banned_at),
  INDEX idx_system_ip_bans_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
