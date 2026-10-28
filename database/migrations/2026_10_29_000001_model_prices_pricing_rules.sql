-- MM-002 / MM-015 / MM-024: optional pricing rules on a price version
-- (peak/off-peak time windows, context-length tiers, per-resolution media
-- prices, image/audio token prices, upstream cost-plus billing).
-- Idempotent: only adds the column when it is missing.

SET @schema_name = DATABASE();
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE model_prices ADD COLUMN pricing_rules JSON NULL AFTER request_price', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'model_prices' AND column_name = 'pricing_rules');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
