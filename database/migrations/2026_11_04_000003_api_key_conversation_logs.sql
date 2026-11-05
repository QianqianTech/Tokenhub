-- Conversation logs are switched on per API key (会话记录按密钥开启):
-- conversation_log_enabled turns logging on for the key, conversation_log_retention_days
-- overrides the default retention (NULL = default from system_settings conversation_log.settings).
-- The conversation logs themselves are kept outside this database (ConversationLogDb: SQLite
-- file in CONVERSATION_LOG_PATH by default, or CONVERSATION_LOG_DB_DSN).
SET @schema_name = DATABASE();

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE api_keys ADD COLUMN conversation_log_enabled TINYINT(1) NOT NULL DEFAULT 0', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'api_keys' AND column_name = 'conversation_log_enabled');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE api_keys ADD COLUMN conversation_log_retention_days INT UNSIGNED NULL AFTER conversation_log_enabled', 'DO 1') FROM information_schema.columns WHERE table_schema = @schema_name AND table_name = 'api_keys' AND column_name = 'conversation_log_retention_days');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
