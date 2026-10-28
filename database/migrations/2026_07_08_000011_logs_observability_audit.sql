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

SET @sql = (
  SELECT IF(COUNT(*) = 1, 'UPDATE log_retention_policies SET online_retention_days = retention_days WHERE online_retention_days = 30 AND retention_days IS NOT NULL', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'log_retention_policies' AND column_name = 'retention_days'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 1, 'ALTER TABLE log_retention_policies MODIFY retention_days INT UNSIGNED NOT NULL DEFAULT 30', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'log_retention_policies' AND column_name = 'retention_days'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 1, 'UPDATE log_retention_policies SET archive_retention_days = archive_days WHERE archive_retention_days = 180 AND archive_days IS NOT NULL', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'log_retention_policies' AND column_name = 'archive_days'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS log_archive_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  log_type VARCHAR(80) NOT NULL,
  source_path VARCHAR(800) NULL,
  archive_path VARCHAR(800) NOT NULL,
  compressed TINYINT(1) NOT NULL DEFAULT 1,
  encrypted TINYINT(1) NOT NULL DEFAULT 1,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY log_archive_files_type_idx (log_type),
  KEY log_archive_files_created_idx (created_at),
  UNIQUE KEY log_archive_files_path_unique (archive_path(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(120) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  message VARCHAR(500) NOT NULL,
  metadata JSON NULL,
  status ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY alert_events_type_idx (event_type),
  KEY alert_events_status_idx (status),
  KEY alert_events_created_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO log_retention_policies (log_type, online_retention_days, archive_retention_days, min_retention_days, compress_enabled, encrypt_enabled)
VALUES
('caddy-access', 30, 180, 30, 1, 1),
('caddy-security', 90, 365, 90, 1, 1),
('gateway-access', 30, 180, 30, 1, 1),
('provider', 30, 180, 30, 1, 1),
('usage', 90, 365, 30, 1, 1),
('audit', 180, 730, 180, 1, 1),
('security', 180, 730, 180, 1, 1),
('performance', 30, 180, 30, 1, 1),
('worker', 30, 180, 30, 1, 1),
('export', 90, 365, 90, 1, 1)
ON DUPLICATE KEY UPDATE
online_retention_days = GREATEST(online_retention_days, VALUES(min_retention_days)),
archive_retention_days = GREATEST(archive_retention_days, VALUES(archive_retention_days)),
min_retention_days = GREATEST(min_retention_days, VALUES(min_retention_days)),
compress_enabled = VALUES(compress_enabled),
encrypt_enabled = VALUES(encrypt_enabled);
