SET @schema_name = DATABASE();

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD COLUMN disposition_status ENUM(''open'',''reviewed'',''false_positive'',''auto_banned'',''manual_banned'',''unbanned'') NOT NULL DEFAULT ''open'' AFTER summary', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'waf_events' AND column_name = 'disposition_status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD COLUMN false_positive TINYINT(1) NOT NULL DEFAULT 0 AFTER disposition_status', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'waf_events' AND column_name = 'false_positive'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD COLUMN handled_by BIGINT UNSIGNED NULL AFTER false_positive', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'waf_events' AND column_name = 'handled_by'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD COLUMN handled_at TIMESTAMP NULL AFTER handled_by', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'waf_events' AND column_name = 'handled_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD COLUMN disposition_note VARCHAR(500) NULL AFTER handled_at', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'waf_events' AND column_name = 'disposition_note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD COLUMN risk_score INT UNSIGNED NOT NULL DEFAULT 0 AFTER disposition_note', 'DO 1')
  FROM information_schema.columns
  WHERE table_schema = @schema_name AND table_name = 'waf_events' AND column_name = 'risk_score'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE waf_events ADD INDEX idx_waf_events_disposition (disposition_status, false_positive)', 'DO 1')
  FROM information_schema.statistics
  WHERE table_schema = @schema_name AND table_name = 'waf_events' AND index_name = 'idx_waf_events_disposition'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
