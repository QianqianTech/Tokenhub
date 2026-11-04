-- MT-003 channel load balancing and capacity limits (one channel = one models row).
-- * route_weight: share of traffic among channels with the same route_priority
--   (default 100; 0 = used only when the others fail). See ModelRouter::balance.
-- * max_in_flight / rpm_limit / tpm_limit: optional per-channel limits enforced
--   by the gateway (ChannelLimiter); NULL or 0 = unlimited.
-- Idempotent: guarded ALTER TABLE only.

SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN route_weight INT UNSIGNED NOT NULL DEFAULT 100 AFTER route_priority', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'route_weight');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN max_in_flight INT UNSIGNED NULL AFTER route_weight', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'max_in_flight');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN rpm_limit INT UNSIGNED NULL AFTER max_in_flight', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'rpm_limit');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE models ADD COLUMN tpm_limit BIGINT UNSIGNED NULL AFTER rpm_limit', 'SELECT 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'models' AND column_name = 'tpm_limit');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
