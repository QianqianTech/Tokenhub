-- Built-in OAuth 2.1 authorization server for the MCP endpoint (/mcp).
-- Lets Claude / Codex / ChatGPT / Cursor sign in instead of pasting an API
-- key. Every grant is bound to one API key; tokens carry exactly that key's
-- permissions. Only HMAC hashes of codes and tokens are stored.
-- Idempotent: CREATE TABLE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS oauth_clients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id VARCHAR(512) NOT NULL,
  client_type ENUM('dynamic','metadata_document') NOT NULL DEFAULT 'dynamic',
  client_name VARCHAR(255) NULL,
  client_uri VARCHAR(512) NULL,
  redirect_uris JSON NOT NULL,
  token_endpoint_auth_method VARCHAR(64) NOT NULL DEFAULT 'none',
  client_secret_hash CHAR(64) NULL,
  metadata JSON NULL,
  metadata_fetched_at DATETIME NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_oauth_clients_client_id (client_id(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A pending /oauth/authorize request between the client redirect and the
-- user's sign-in on the TokenHub page (bound to the browser by a cookie).
CREATE TABLE IF NOT EXISTS oauth_authorization_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_hash CHAR(64) NOT NULL,
  browser_hash CHAR(64) NOT NULL,
  client_id VARCHAR(512) NOT NULL,
  redirect_uri VARCHAR(2048) NOT NULL,
  state VARCHAR(1024) NULL,
  scope VARCHAR(255) NOT NULL DEFAULT 'mcp',
  resource VARCHAR(1024) NOT NULL,
  code_challenge VARCHAR(128) NOT NULL,
  pending_user_id BIGINT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_oauth_requests_hash (request_hash),
  KEY idx_oauth_requests_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A user's consent: one client may act with one API key.
CREATE TABLE IF NOT EXISTS oauth_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  grant_id VARCHAR(64) NOT NULL,
  client_id VARCHAR(512) NOT NULL,
  api_key_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  scope VARCHAR(255) NOT NULL DEFAULT 'mcp',
  resource VARCHAR(1024) NOT NULL,
  login_method ENUM('password','api_key') NOT NULL,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  revoked_reason VARCHAR(64) NULL,
  revoked_at DATETIME NULL,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_oauth_grants_grant_id (grant_id),
  KEY idx_oauth_grants_api_key (api_key_id, status),
  KEY idx_oauth_grants_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code_hash CHAR(64) NOT NULL,
  grant_id VARCHAR(64) NOT NULL,
  client_id VARCHAR(512) NOT NULL,
  redirect_uri VARCHAR(2048) NOT NULL,
  code_challenge VARCHAR(128) NOT NULL,
  resource VARCHAR(1024) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_oauth_codes_hash (code_hash),
  KEY idx_oauth_codes_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Access and refresh tokens. A rotated refresh token keeps rotated_at so a
-- concurrent refresh inside the grace period still succeeds; a replay after
-- the grace period revokes the whole grant.
CREATE TABLE IF NOT EXISTS oauth_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  token_type ENUM('access','refresh') NOT NULL,
  grant_id VARCHAR(64) NOT NULL,
  client_id VARCHAR(512) NOT NULL,
  resource VARCHAR(1024) NOT NULL,
  expires_at DATETIME NOT NULL,
  rotated_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_oauth_tokens_hash (token_hash),
  KEY idx_oauth_tokens_grant (grant_id, token_type),
  KEY idx_oauth_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
