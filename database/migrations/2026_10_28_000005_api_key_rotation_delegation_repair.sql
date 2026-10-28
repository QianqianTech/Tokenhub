-- Rotations made before 2026-10-28 copied a management key's scopes to the
-- replacement key but not its place in the delegation tree: the replacement
-- became a free-standing root key and the children stayed attached to the
-- old (now expired) key. Delegation checks now treat an expired ancestor as
-- revoking the chain, so repair those trees from the audit trail.
--
-- Source of truth: the `api_key.create` audit row written for every
-- replacement key, target_id = new key id, details.rotated_from = old key id.
-- Each statement is repeated so chains of rotations (A -> B -> C) settle.
-- Idempotent: re-running changes nothing.

INSERT IGNORE INTO api_key_management_delegations (child_api_key_id, parent_api_key_id)
SELECT nk.id, d.parent_api_key_id
FROM audit_logs a
JOIN api_keys nk ON nk.id = CAST(a.target_id AS UNSIGNED)
JOIN api_key_management_delegations d ON d.child_api_key_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.rotated_from')) AS UNSIGNED)
WHERE a.action = 'api_key.create' AND a.target_type = 'api_keys' AND JSON_VALID(a.details) AND JSON_EXTRACT(a.details, '$.rotated_from') IS NOT NULL
  AND d.parent_api_key_id <> nk.id;

UPDATE api_key_management_delegations d
JOIN audit_logs a ON a.action = 'api_key.create' AND a.target_type = 'api_keys' AND JSON_VALID(a.details)
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.rotated_from')) AS UNSIGNED) = d.parent_api_key_id
JOIN api_keys nk ON nk.id = CAST(a.target_id AS UNSIGNED)
SET d.parent_api_key_id = nk.id
WHERE d.child_api_key_id <> nk.id;

INSERT IGNORE INTO api_key_management_delegations (child_api_key_id, parent_api_key_id)
SELECT nk.id, d.parent_api_key_id
FROM audit_logs a
JOIN api_keys nk ON nk.id = CAST(a.target_id AS UNSIGNED)
JOIN api_key_management_delegations d ON d.child_api_key_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.rotated_from')) AS UNSIGNED)
WHERE a.action = 'api_key.create' AND a.target_type = 'api_keys' AND JSON_VALID(a.details) AND JSON_EXTRACT(a.details, '$.rotated_from') IS NOT NULL
  AND d.parent_api_key_id <> nk.id;

UPDATE api_key_management_delegations d
JOIN audit_logs a ON a.action = 'api_key.create' AND a.target_type = 'api_keys' AND JSON_VALID(a.details)
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.rotated_from')) AS UNSIGNED) = d.parent_api_key_id
JOIN api_keys nk ON nk.id = CAST(a.target_id AS UNSIGNED)
SET d.parent_api_key_id = nk.id
WHERE d.child_api_key_id <> nk.id;

UPDATE api_key_management_delegations d
JOIN audit_logs a ON a.action = 'api_key.create' AND a.target_type = 'api_keys' AND JSON_VALID(a.details)
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.rotated_from')) AS UNSIGNED) = d.parent_api_key_id
JOIN api_keys nk ON nk.id = CAST(a.target_id AS UNSIGNED)
SET d.parent_api_key_id = nk.id
WHERE d.child_api_key_id <> nk.id;
