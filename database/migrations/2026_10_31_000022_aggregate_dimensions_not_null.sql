-- BIZ-013: aggregate dimension columns become NOT NULL DEFAULT 0.
--
-- The unique keys of the aggregate tables contain department_id, user_id,
-- api_key_id, provider_id and model_id. Those columns were nullable and NULL
-- never equals NULL in a unique index, so "INSERT ... ON DUPLICATE KEY
-- UPDATE" inserted a new row for every request without a department, key or
-- model instead of adding to the existing one: the tables grew and sums were
-- split over many rows.
--
-- For each table that still has a nullable dimension column:
--   1. groups that collide once NULL is read as 0 are summed into one row
--      (every metric column is SUM()med; created_at keeps the earliest);
--   2. the remaining NULLs become 0;
--   3. the columns become NOT NULL DEFAULT 0.
-- Run it with the queue workers stopped (upgrade.sh does): rows written by a
-- worker between steps 1 and 3 could otherwise block the ALTER.
-- Idempotent: nothing happens once no dimension column is nullable.

SET @schema_name = DATABASE();
SET SESSION group_concat_max_len = 65536;

-- ---------------------------------------------------------------- usage_aggregates_minute
SET @needs = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name IN ('department_id','user_id','api_key_id','provider_id','model_id') AND is_nullable = 'YES');
SET @metric_sum = (SELECT GROUP_CONCAT(CONCAT('SUM(x.`', column_name, '`) AS `', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','capability','created_at','updated_at'));
SET @metric_src = (SELECT GROUP_CONCAT(CONCAT('t.`', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','capability','created_at','updated_at'));
SET @metric_cols = (SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_minute' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','capability','created_at','updated_at'));
DROP TEMPORARY TABLE IF EXISTS bz13_groups;
DROP TEMPORARY TABLE IF EXISTS bz13_merged;
SET @sql = IF(@needs > 0, 'CREATE TEMPORARY TABLE bz13_groups AS SELECT x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability FROM (SELECT bucket_at, COALESCE(department_id, 0) AS department_id, COALESCE(user_id, 0) AS user_id, COALESCE(api_key_id, 0) AS api_key_id, COALESCE(provider_id, 0) AS provider_id, COALESCE(model_id, 0) AS model_id, capability FROM usage_aggregates_minute) x GROUP BY x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability HAVING COUNT(*) > 1', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, CONCAT('CREATE TEMPORARY TABLE bz13_merged AS SELECT x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability, ', @metric_sum, ', MIN(x.created_at) AS created_at FROM (SELECT t.bucket_at, COALESCE(t.department_id, 0) AS department_id, COALESCE(t.user_id, 0) AS user_id, COALESCE(t.api_key_id, 0) AS api_key_id, COALESCE(t.provider_id, 0) AS provider_id, COALESCE(t.model_id, 0) AS model_id, t.capability, ', @metric_src, ', t.created_at FROM usage_aggregates_minute t JOIN bz13_groups g ON g.bucket_at = t.bucket_at AND g.department_id = COALESCE(t.department_id, 0) AND g.user_id = COALESCE(t.user_id, 0) AND g.api_key_id = COALESCE(t.api_key_id, 0) AND g.provider_id = COALESCE(t.provider_id, 0) AND g.model_id = COALESCE(t.model_id, 0) AND g.capability = t.capability) x GROUP BY x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability'), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'DELETE t FROM usage_aggregates_minute t JOIN bz13_groups g ON g.bucket_at = t.bucket_at AND g.department_id = COALESCE(t.department_id, 0) AND g.user_id = COALESCE(t.user_id, 0) AND g.api_key_id = COALESCE(t.api_key_id, 0) AND g.provider_id = COALESCE(t.provider_id, 0) AND g.model_id = COALESCE(t.model_id, 0) AND g.capability = t.capability', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, CONCAT('INSERT INTO usage_aggregates_minute (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, ', @metric_cols, ', created_at) SELECT bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, ', @metric_cols, ', created_at FROM bz13_merged'), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'UPDATE usage_aggregates_minute SET department_id = COALESCE(department_id, 0), user_id = COALESCE(user_id, 0), api_key_id = COALESCE(api_key_id, 0), provider_id = COALESCE(provider_id, 0), model_id = COALESCE(model_id, 0) WHERE department_id IS NULL OR user_id IS NULL OR api_key_id IS NULL OR provider_id IS NULL OR model_id IS NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'ALTER TABLE usage_aggregates_minute MODIFY COLUMN department_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN api_key_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN provider_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN model_id BIGINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
DROP TEMPORARY TABLE IF EXISTS bz13_groups;
DROP TEMPORARY TABLE IF EXISTS bz13_merged;

-- ---------------------------------------------------------------- usage_aggregates_hour
SET @needs = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name IN ('department_id','user_id','api_key_id','provider_id','model_id') AND is_nullable = 'YES');
SET @metric_sum = (SELECT GROUP_CONCAT(CONCAT('SUM(x.`', column_name, '`) AS `', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','capability','created_at','updated_at'));
SET @metric_src = (SELECT GROUP_CONCAT(CONCAT('t.`', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','capability','created_at','updated_at'));
SET @metric_cols = (SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_hour' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','capability','created_at','updated_at'));
DROP TEMPORARY TABLE IF EXISTS bz13_groups;
DROP TEMPORARY TABLE IF EXISTS bz13_merged;
SET @sql = IF(@needs > 0, 'CREATE TEMPORARY TABLE bz13_groups AS SELECT x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability FROM (SELECT bucket_at, COALESCE(department_id, 0) AS department_id, COALESCE(user_id, 0) AS user_id, COALESCE(api_key_id, 0) AS api_key_id, COALESCE(provider_id, 0) AS provider_id, COALESCE(model_id, 0) AS model_id, capability FROM usage_aggregates_hour) x GROUP BY x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability HAVING COUNT(*) > 1', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, CONCAT('CREATE TEMPORARY TABLE bz13_merged AS SELECT x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability, ', @metric_sum, ', MIN(x.created_at) AS created_at FROM (SELECT t.bucket_at, COALESCE(t.department_id, 0) AS department_id, COALESCE(t.user_id, 0) AS user_id, COALESCE(t.api_key_id, 0) AS api_key_id, COALESCE(t.provider_id, 0) AS provider_id, COALESCE(t.model_id, 0) AS model_id, t.capability, ', @metric_src, ', t.created_at FROM usage_aggregates_hour t JOIN bz13_groups g ON g.bucket_at = t.bucket_at AND g.department_id = COALESCE(t.department_id, 0) AND g.user_id = COALESCE(t.user_id, 0) AND g.api_key_id = COALESCE(t.api_key_id, 0) AND g.provider_id = COALESCE(t.provider_id, 0) AND g.model_id = COALESCE(t.model_id, 0) AND g.capability = t.capability) x GROUP BY x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability'), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'DELETE t FROM usage_aggregates_hour t JOIN bz13_groups g ON g.bucket_at = t.bucket_at AND g.department_id = COALESCE(t.department_id, 0) AND g.user_id = COALESCE(t.user_id, 0) AND g.api_key_id = COALESCE(t.api_key_id, 0) AND g.provider_id = COALESCE(t.provider_id, 0) AND g.model_id = COALESCE(t.model_id, 0) AND g.capability = t.capability', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, CONCAT('INSERT INTO usage_aggregates_hour (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, ', @metric_cols, ', created_at) SELECT bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, ', @metric_cols, ', created_at FROM bz13_merged'), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'UPDATE usage_aggregates_hour SET department_id = COALESCE(department_id, 0), user_id = COALESCE(user_id, 0), api_key_id = COALESCE(api_key_id, 0), provider_id = COALESCE(provider_id, 0), model_id = COALESCE(model_id, 0) WHERE department_id IS NULL OR user_id IS NULL OR api_key_id IS NULL OR provider_id IS NULL OR model_id IS NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'ALTER TABLE usage_aggregates_hour MODIFY COLUMN department_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN api_key_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN provider_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN model_id BIGINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
DROP TEMPORARY TABLE IF EXISTS bz13_groups;
DROP TEMPORARY TABLE IF EXISTS bz13_merged;

-- ---------------------------------------------------------------- usage_aggregates_day
SET @needs = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name IN ('department_id','user_id','api_key_id','provider_id','model_id') AND is_nullable = 'YES');
SET @metric_sum = (SELECT GROUP_CONCAT(CONCAT('SUM(x.`', column_name, '`) AS `', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','capability','created_at','updated_at'));
SET @metric_src = (SELECT GROUP_CONCAT(CONCAT('t.`', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','capability','created_at','updated_at'));
SET @metric_cols = (SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_aggregates_day' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','capability','created_at','updated_at'));
DROP TEMPORARY TABLE IF EXISTS bz13_groups;
DROP TEMPORARY TABLE IF EXISTS bz13_merged;
SET @sql = IF(@needs > 0, 'CREATE TEMPORARY TABLE bz13_groups AS SELECT x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability FROM (SELECT bucket_at, COALESCE(department_id, 0) AS department_id, COALESCE(user_id, 0) AS user_id, COALESCE(api_key_id, 0) AS api_key_id, COALESCE(provider_id, 0) AS provider_id, COALESCE(model_id, 0) AS model_id, capability FROM usage_aggregates_day) x GROUP BY x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability HAVING COUNT(*) > 1', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, CONCAT('CREATE TEMPORARY TABLE bz13_merged AS SELECT x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability, ', @metric_sum, ', MIN(x.created_at) AS created_at FROM (SELECT t.bucket_at, COALESCE(t.department_id, 0) AS department_id, COALESCE(t.user_id, 0) AS user_id, COALESCE(t.api_key_id, 0) AS api_key_id, COALESCE(t.provider_id, 0) AS provider_id, COALESCE(t.model_id, 0) AS model_id, t.capability, ', @metric_src, ', t.created_at FROM usage_aggregates_day t JOIN bz13_groups g ON g.bucket_at = t.bucket_at AND g.department_id = COALESCE(t.department_id, 0) AND g.user_id = COALESCE(t.user_id, 0) AND g.api_key_id = COALESCE(t.api_key_id, 0) AND g.provider_id = COALESCE(t.provider_id, 0) AND g.model_id = COALESCE(t.model_id, 0) AND g.capability = t.capability) x GROUP BY x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.capability'), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'DELETE t FROM usage_aggregates_day t JOIN bz13_groups g ON g.bucket_at = t.bucket_at AND g.department_id = COALESCE(t.department_id, 0) AND g.user_id = COALESCE(t.user_id, 0) AND g.api_key_id = COALESCE(t.api_key_id, 0) AND g.provider_id = COALESCE(t.provider_id, 0) AND g.model_id = COALESCE(t.model_id, 0) AND g.capability = t.capability', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, CONCAT('INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, ', @metric_cols, ', created_at) SELECT bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, ', @metric_cols, ', created_at FROM bz13_merged'), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'UPDATE usage_aggregates_day SET department_id = COALESCE(department_id, 0), user_id = COALESCE(user_id, 0), api_key_id = COALESCE(api_key_id, 0), provider_id = COALESCE(provider_id, 0), model_id = COALESCE(model_id, 0) WHERE department_id IS NULL OR user_id IS NULL OR api_key_id IS NULL OR provider_id IS NULL OR model_id IS NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'ALTER TABLE usage_aggregates_day MODIFY COLUMN department_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN api_key_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN provider_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN model_id BIGINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
DROP TEMPORARY TABLE IF EXISTS bz13_groups;
DROP TEMPORARY TABLE IF EXISTS bz13_merged;

-- ---------------------------------------------------------------- usage_cost_aggregates_day
SET @needs = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_cost_aggregates_day' AND column_name IN ('department_id','user_id','api_key_id','provider_id','model_id') AND is_nullable = 'YES');
SET @metric_sum = (SELECT GROUP_CONCAT(CONCAT('SUM(x.`', column_name, '`) AS `', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_cost_aggregates_day' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','currency','created_at','updated_at'));
SET @metric_src = (SELECT GROUP_CONCAT(CONCAT('t.`', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_cost_aggregates_day' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','currency','created_at','updated_at'));
SET @metric_cols = (SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position SEPARATOR ', ') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'usage_cost_aggregates_day' AND column_name NOT IN ('id','bucket_at','department_id','user_id','api_key_id','provider_id','model_id','currency','created_at','updated_at'));
DROP TEMPORARY TABLE IF EXISTS bz13_groups;
DROP TEMPORARY TABLE IF EXISTS bz13_merged;
SET @sql = IF(@needs > 0, 'CREATE TEMPORARY TABLE bz13_groups AS SELECT x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.currency FROM (SELECT bucket_at, COALESCE(department_id, 0) AS department_id, COALESCE(user_id, 0) AS user_id, COALESCE(api_key_id, 0) AS api_key_id, COALESCE(provider_id, 0) AS provider_id, COALESCE(model_id, 0) AS model_id, currency FROM usage_cost_aggregates_day) x GROUP BY x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.currency HAVING COUNT(*) > 1', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, CONCAT('CREATE TEMPORARY TABLE bz13_merged AS SELECT x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.currency, ', @metric_sum, ', MIN(x.created_at) AS created_at FROM (SELECT t.bucket_at, COALESCE(t.department_id, 0) AS department_id, COALESCE(t.user_id, 0) AS user_id, COALESCE(t.api_key_id, 0) AS api_key_id, COALESCE(t.provider_id, 0) AS provider_id, COALESCE(t.model_id, 0) AS model_id, t.currency, ', @metric_src, ', t.created_at FROM usage_cost_aggregates_day t JOIN bz13_groups g ON g.bucket_at = t.bucket_at AND g.department_id = COALESCE(t.department_id, 0) AND g.user_id = COALESCE(t.user_id, 0) AND g.api_key_id = COALESCE(t.api_key_id, 0) AND g.provider_id = COALESCE(t.provider_id, 0) AND g.model_id = COALESCE(t.model_id, 0) AND g.currency = t.currency) x GROUP BY x.bucket_at, x.department_id, x.user_id, x.api_key_id, x.provider_id, x.model_id, x.currency'), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'DELETE t FROM usage_cost_aggregates_day t JOIN bz13_groups g ON g.bucket_at = t.bucket_at AND g.department_id = COALESCE(t.department_id, 0) AND g.user_id = COALESCE(t.user_id, 0) AND g.api_key_id = COALESCE(t.api_key_id, 0) AND g.provider_id = COALESCE(t.provider_id, 0) AND g.model_id = COALESCE(t.model_id, 0) AND g.currency = t.currency', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, CONCAT('INSERT INTO usage_cost_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, currency, ', @metric_cols, ', created_at) SELECT bucket_at, department_id, user_id, api_key_id, provider_id, model_id, currency, ', @metric_cols, ', created_at FROM bz13_merged'), 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'UPDATE usage_cost_aggregates_day SET department_id = COALESCE(department_id, 0), user_id = COALESCE(user_id, 0), api_key_id = COALESCE(api_key_id, 0), provider_id = COALESCE(provider_id, 0), model_id = COALESCE(model_id, 0) WHERE department_id IS NULL OR user_id IS NULL OR api_key_id IS NULL OR provider_id IS NULL OR model_id IS NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql = IF(@needs > 0, 'ALTER TABLE usage_cost_aggregates_day MODIFY COLUMN department_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN api_key_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN provider_id BIGINT UNSIGNED NOT NULL DEFAULT 0, MODIFY COLUMN model_id BIGINT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
DROP TEMPORARY TABLE IF EXISTS bz13_groups;
DROP TEMPORARY TABLE IF EXISTS bz13_merged;
