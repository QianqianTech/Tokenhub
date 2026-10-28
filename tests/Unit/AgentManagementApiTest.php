<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * Agent management API (/v1/management/*) against an in-memory SQLite stand-in
 * for MySQL: department boundary, delegation-aware key administration,
 * rotation keeping the delegation tree, usage scoping and cache invalidation.
 */

use TokenHub\Bootstrap\Kernel;
use TokenHub\Services\AgentManagementApiKeyService;
use TokenHub\Services\AgentManagementDepartmentService;
use TokenHub\Services\AgentManagementUsageService;
use TokenHub\Services\AgentManagementUserService;
use TokenHub\Services\ApiKeyManagementScopeService;
use TokenHub\Services\ApiKeyService;
use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}
$_ENV['APP_KEY'] = 'agent-management-test-key-0123456789abcdef';
(new ReflectionProperty(App::class, 'config'))->setValue(null, \TokenHub\Support\Config::default());
try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}

/** Translates the handful of MySQL-only statements the services use. */
final class AgentManagementTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query);
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
        }
        if (str_contains($query, 'FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?')) {
            $query = 'SELECT 1 AS present FROM pragma_table_info(?) WHERE name = ? LIMIT 1';
        }

        return parent::prepare($query, $options);
    }
}

$pdo = new AgentManagementTestPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->sqliteCreateFunction('FIELD', static function (...$args): int {
    $needle = array_shift($args);
    $index = array_search($needle, $args, true);
    return $index === false ? 0 : $index + 1;
});
foreach ([
    'CREATE TABLE departments (id INTEGER PRIMARY KEY, parent_id INT, name TEXT, path TEXT, level INT DEFAULT 0, status TEXT DEFAULT "active", created_at TEXT, updated_at TEXT)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, department_id INT, username TEXT, password_hash TEXT, role TEXT, status TEXT DEFAULT "active", created_at TEXT, updated_at TEXT)',
    'CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, department_id INT, name TEXT, key_prefix TEXT, key_suffix TEXT, key_hash TEXT UNIQUE, allowed_ip_cidrs TEXT, status TEXT DEFAULT "active", disabled_by TEXT, expires_at TEXT, last_used_at TEXT, created_by INT, created_at TEXT, updated_at TEXT)',
    'CREATE TABLE api_key_management_scopes (id INTEGER PRIMARY KEY, api_key_id INT, scope TEXT)',
    'CREATE TABLE api_key_management_delegations (child_api_key_id INTEGER PRIMARY KEY, parent_api_key_id INT, created_at TEXT)',
    'CREATE TABLE api_key_model_permissions (api_key_id INT, model_id INT, PRIMARY KEY (api_key_id, model_id))',
    'CREATE TABLE api_key_model_quotas (id INTEGER PRIMARY KEY, api_key_id INT, model_id INT, period TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, status TEXT)',
    'CREATE TABLE rag_knowledge_base_bindings (kb_id INT, subject_type TEXT, subject_id INT, permission TEXT, is_default INT)',
    'CREATE TABLE rag_dataset_bindings (dataset_id INT, subject_type TEXT, subject_id INT, permission TEXT, is_default INT, priority INT)',
    'CREATE TABLE models (id INTEGER PRIMARY KEY, name TEXT, status TEXT)',
    'CREATE TABLE quota_policies (id INTEGER PRIMARY KEY, name TEXT, period TEXT, status TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, audio_second_limit INT, tts_char_limit INT, image_limit INT, video_second_limit INT, cost_limit REAL, concurrency_limit INT)',
    'CREATE TABLE quota_bindings (id INTEGER PRIMARY KEY, quota_policy_id INT, target_type TEXT, target_id INT)',
    'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT)',
    'CREATE TABLE usage_aggregates_day (id INTEGER PRIMARY KEY, bucket_at TEXT, department_id INT, user_id INT, api_key_id INT, provider_id INT, model_id INT, capability TEXT, request_count INT DEFAULT 0, success_count INT DEFAULT 0, failed_count INT DEFAULT 0, input_tokens INT DEFAULT 0, output_tokens INT DEFAULT 0, cache_read_tokens INT DEFAULT 0, cache_write_tokens INT DEFAULT 0, total_cost REAL, latency_ms_sum INT DEFAULT 0)',
] as $ddl) {
    $pdo->exec($ddl);
}
$pdo->exec("INSERT INTO departments (id, parent_id, name, path, level) VALUES (1, NULL, '研发部', '/1/', 0), (2, NULL, '财务部', '/2/', 0)");
$pdo->exec("INSERT INTO users (id, department_id, username, role) VALUES (10, 1, 'agent-owner', 'user'), (11, 1, 'worker', 'user'), (12, 1, 'dept-admin', 'admin'), (20, 2, 'other', 'user')");
$pdo->exec("INSERT INTO models (id, name, status) VALUES (501, 'gpt-a', 'active'), (502, 'gpt-b', 'active'), (509, 'gpt-old', 'retired')");
$key = static function (int $id, int $userId, int $departmentId, array $scopes = [], ?int $parent = null) use ($pdo): void {
    $pdo->prepare("INSERT INTO api_keys (id, user_id, department_id, name, key_prefix, key_suffix, key_hash, allowed_ip_cidrs) VALUES (?, ?, ?, ?, 'tkh_test', 'abcd', ?, '[]')")->execute([$id, $userId, $departmentId, 'key-' . $id, 'hash-' . $id]);
    foreach ($scopes as $scope) {
        $pdo->prepare('INSERT INTO api_key_management_scopes (api_key_id, scope) VALUES (?, ?)')->execute([$id, $scope]);
    }
    if ($parent !== null) {
        $pdo->prepare('INSERT INTO api_key_management_delegations (child_api_key_id, parent_api_key_id) VALUES (?, ?)')->execute([$id, $parent]);
    }
};
$all = ['users:write', 'api_keys:write', 'rag:write', 'usage:read'];
$key(100, 10, 1, $all);                                  // root management key (issued by an admin)
$key(101, 10, 1, ['api_keys:write', 'users:read'], 100); // delegated child
$key(104, 10, 1, ['api_keys:read'], 101);                // grandchild
$key(103, 10, 1, ['api_keys:write']);                    // sibling root key, not delegated by 100
$key(102, 11, 1);                                        // plain model-calling key
$key(200, 20, 2);                                        // other department
$pdo->exec('INSERT INTO api_key_model_permissions (api_key_id, model_id) VALUES (102, 501), (102, 502), (101, 501), (100, 501), (100, 502)');
$pdo->exec("CREATE TABLE rag_knowledge_bases (id INTEGER PRIMARY KEY, department_id INT, visibility TEXT, deleted_at TEXT)");
$pdo->exec("INSERT INTO quota_policies (id, name, period, status, request_limit) VALUES (7, '日额度', 'day', 'active', 1000)");
$pdo->exec("INSERT INTO quota_bindings (quota_policy_id, target_type, target_id) VALUES (7, 'department', 1)");
$today = gmdate('Y-m-d 00:00:00');
$pdo->exec("INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, model_id, capability, request_count, success_count, input_tokens, output_tokens, total_cost) VALUES
    ('{$today}', 1, 11, 102, 501, 'text_generation', 5, 5, 100, 50, 0.5),
    ('{$today}', 1, 10, 100, 502, 'text_generation', 2, 1, 10, 5, 0.1),
    ('{$today}', 2, 20, 200, 501, 'text_generation', 99, 99, 9999, 9999, 9.9)");

$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);
(new ReflectionProperty(App::class, 'context'))->setValue(null, RequestContext::fromHeaders([]));

$row = static fn (int $id): array => $pdo->query('SELECT * FROM api_keys WHERE id = ' . $id)->fetch();
$actor = static function (int $id) use ($row): array {
    $data = $row($id);
    return ['id' => (int) $data['id'], 'user_id' => (int) $data['user_id'], 'department_id' => (int) $data['department_id']];
};
$expectError = static function (callable $fn, string $code, int $status, string $message): void {
    try {
        $fn();
    } catch (HttpAbortException $exception) {
        assert_same($code, $exception->errorCode, $message);
        assert_same($status, $exception->status, $message . ' (status)');
        return;
    }
    throw new RuntimeException($message . ' Expected ' . $code);
};
$scopes = new ApiKeyManagementScopeService();
$keys = new AgentManagementApiKeyService();

// Scopes and delegation-aware targeting.
assert_true($scopes->hasScope(100, 'usage:read'), 'usage:read is a valid, grantable scope.');
assert_true($scopes->hasScope(101, 'api_keys:read'), 'write implies read.');
assert_true(!$scopes->hasScope(101, 'usage:read'), 'Scopes are not inherited from the parent.');
assert_true($scopes->hasAnyScope(104) && !$scopes->hasAnyScope(102), 'hasAnyScope distinguishes management keys.');
assert_true($scopes->canManageKey(100, 101) && $scopes->canManageKey(100, 104), 'A key manages its delegated descendants.');
assert_true($scopes->canManageKey(101, 102) && $scopes->canManageKey(103, 102), 'Plain keys of the department are manageable.');
assert_true(!$scopes->canManageKey(101, 100), 'A child can never manage its parent.');
assert_true(!$scopes->canManageKey(101, 103) && !$scopes->canManageKey(103, 101), 'Sibling management trees are isolated.');

// Read endpoints.
$list = $keys->list($actor(101), ['status' => 'active']);
assert_same(5, $list['pagination']['total'], 'Listing is limited to the department.');
assert_same([], $list['data'][0]['allowed_ip_cidrs'], 'allowed_ip_cidrs is decoded.');
$shown = $keys->show(102, $actor(101));
assert_same([501, 502], $shown['model_ids'], 'Detail lists granted models.');
assert_same(true, $shown['manageable'], 'Detail tells the agent whether it may change the key.');
assert_same(false, $shown['rotatable'], 'Ordinary keys of other users cannot be rotated by an agent.');
assert_same(false, $keys->show(100, $actor(101))['manageable'], 'Parent is shown as not manageable.');
assert_same([false, true], [$keys->show(101, $actor(101))['manageable'], $keys->show(101, $actor(101))['rotatable']], 'A key may rotate, but not edit, itself.');
$expectError(static fn () => $keys->show(200, $actor(100)), 'MANAGEMENT_RESOURCE_NOT_FOUND', 404, 'Other departments are invisible.');
$expectError(static fn () => $keys->list($actor(100), ['status' => 'weird']), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Unknown status filter is rejected.');

// PATCH.
$expectError(static fn () => $keys->update(100, $actor(101), ['status' => 'disabled']), 'MANAGEMENT_TARGET_FORBIDDEN', 403, 'A child cannot disable its parent.');
$expectError(static fn () => $keys->update(101, $actor(101), ['name' => 'me']), 'MANAGEMENT_TARGET_FORBIDDEN', 403, 'A key cannot edit itself.');
$expectError(static fn () => $keys->update(102, $actor(101), []), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Empty PATCH is rejected.');
$expectError(static fn () => $keys->update(102, $actor(101), ['key_hash' => 'x']), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Unknown fields are rejected.');
$expectError(static fn () => $keys->update(102, $actor(101), ['status' => 'deleted']), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Deletion goes through DELETE.');
$expectError(static fn () => $keys->update(102, $actor(101), ['expires_at' => '2000-01-01T00:00:00Z']), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Past expiry is rejected.');
$expectError(static fn () => $keys->update(102, $actor(101), ['allowed_ip_cidrs' => ['10.0.0.0/33']]), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Invalid CIDR is rejected.');
$expectError(static fn () => $keys->update(102, $actor(101), ['name' => '  ']), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Blank name is rejected.');
App::redis()->set((new ApiKeyService())->cacheKey('hash-102'), ['id' => 102], 600);
$expectError(static fn () => $keys->update(102, $actor(101), ['allowed_ip_cidrs' => ['fd00::/8']]), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'IPv6 ranges are rejected (the matcher is IPv4-only).');
$updated = $keys->update(102, $actor(101), ['name' => '  worker key ', 'allowed_ip_cidrs' => ['10.0.0.0/8', '192.168.1.5'], 'expires_at' => '2099-01-01T08:00:00+08:00']);
assert_same('worker key', $updated['name'], 'Name is trimmed and stored.');
assert_same(['10.0.0.0/8', '192.168.1.5'], $updated['allowed_ip_cidrs'], 'CIDRs are stored.');
assert_same('2099-01-01 00:00:00', $updated['expires_at'], 'Expiry is normalized to UTC.');
assert_same(null, App::redis()->get((new ApiKeyService())->cacheKey('hash-102')), 'Changing a key drops its authentication cache.');
assert_same('disabled', $keys->update(102, $actor(101), ['status' => 'disabled'])['status'], 'Keys can be disabled.');
assert_same('management', $row(102)['disabled_by'], 'An agent disable is recorded as such.');
assert_same('management', $keys->show(102, $actor(101))['disabled_by'], 'Detail says who disabled the key.');
assert_same('active', $keys->update(102, $actor(101), ['status' => 'active'])['status'], 'Keys an agent disabled can be re-enabled by an agent.');
assert_same(null, $row(102)['disabled_by'], 'Re-enabling clears disabled_by.');
(new ApiKeyService())->update(102, ['status' => 'disabled']);
assert_same('admin', $row(102)['disabled_by'], 'A console disable is recorded as admin.');
$expectError(static fn () => $keys->update(102, $actor(101), ['status' => 'active']), 'MANAGEMENT_KEY_DISABLED_BY_ADMIN', 409, 'Agents cannot re-enable keys an administrator disabled.');
assert_same('disabled', $keys->update(102, $actor(101), ['name' => 'still off'])['status'], 'Other edits of an admin-disabled key are still allowed.');
(new ApiKeyService())->update(102, ['status' => 'active']);
(new ApiKeyService())->batchStatus('disable', [102]);
assert_same('admin', $row(102)['disabled_by'], 'Batch disable in the console is recorded as admin.');
$expectError(static fn () => $keys->update(102, $actor(101), ['status' => 'active']), 'MANAGEMENT_KEY_DISABLED_BY_ADMIN', 409, 'Batch-disabled keys stay off for agents.');
$pdo->exec("UPDATE api_keys SET disabled_by = NULL WHERE id = 102");
$expectError(static fn () => $keys->update(102, $actor(101), ['status' => 'active']), 'MANAGEMENT_KEY_DISABLED_BY_ADMIN', 409, 'Keys disabled before disabled_by existed count as admin-disabled.');
(new ApiKeyService())->batchStatus('enable', [102]);
assert_same(['active', null], [$row(102)['status'], $row(102)['disabled_by']], 'Console enable clears disabled_by.');

// Model grants and creation only accept active models.
$expectError(static fn () => $keys->replaceModels(102, $actor(101), [509]), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Retired models cannot be granted.');
$expectError(static fn () => $keys->replaceModels(100, $actor(103), [501]), 'MANAGEMENT_TARGET_FORBIDDEN', 403, 'A sibling cannot re-grant another management key.');
$expectError(static fn () => $keys->create($actor(101), ['user_id' => 11, 'model_ids' => [509]]), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Creation cannot grant retired models.');
$expectError(static fn () => $keys->create($actor(101), ['user_id' => 11, 'department_id' => 2]), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Creation rejects fields an agent may not set.');
$expectError(static fn () => $keys->create($actor(101), ['user_id' => 12]), 'MANAGEMENT_RESOURCE_NOT_FOUND', 404, 'Keys cannot be issued to administrators.');
$expectError(static fn () => $keys->create($actor(101), ['user_id' => 11, 'model_ids' => [502]]), 'MANAGEMENT_MODEL_DELEGATION_DENIED', 403, 'An agent cannot grant models it may not use itself.');
$expectError(static fn () => $keys->replaceModels(102, $actor(101), [501, 502]), 'MANAGEMENT_MODEL_DELEGATION_DENIED', 403, 'Re-granting is limited to the caller\'s own models.');
$created = $keys->create($actor(101), ['user_id' => 11, 'name' => 'bot', 'model_ids' => [501], 'allowed_ip_cidrs' => ['10.1.0.0/16']]);
assert_true(str_starts_with($created['key'], 'tkh_'), 'Plaintext key is returned once.');
assert_same('[501]', json_encode($keys->show((int) $created['id'], $actor(101))['model_ids']), 'Granted models are stored.');

// Rotation.
$expectError(static fn () => $keys->rotate(100, $actor(101), []), 'MANAGEMENT_TARGET_FORBIDDEN', 403, 'A child cannot rotate its parent.');
$expectError(static fn () => $keys->rotate(101, $actor(101), ['grace_minutes' => 99999]), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Grace period is bounded.');
$expectError(static fn () => $keys->rotate(101, $actor(101), ['grace_minutes' => '30']), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Grace period must be an integer.');
$expectError(static fn () => $keys->rotate(102, $actor(101), ['grace_minutes' => 30]), 'MANAGEMENT_TARGET_FORBIDDEN', 403, 'Rotating another user\'s ordinary key would hand over its secret.');
$pdo->exec("INSERT INTO api_key_management_scopes (api_key_id, scope) VALUES (102, 'users:read')");
$pdo->exec('INSERT INTO api_key_management_delegations (child_api_key_id, parent_api_key_id) VALUES (102, 101)');
$rotatedPlain = $keys->rotate(102, $actor(101), ['grace_minutes' => 30]);
assert_same(102, $rotatedPlain['replaces_api_key_id'], 'Rotation reports the replaced key.');
assert_same('[501,502]', json_encode($keys->show((int) $rotatedPlain['id'], $actor(101))['model_ids']), 'Rotation copies model grants.');
assert_true(strtotime($row(102)['expires_at'] . ' UTC') <= time() + 30 * 60 + 5, 'The old key expires after the grace period.');
$pdo->exec("UPDATE api_keys SET expires_at = '2000-01-01 00:00:00' WHERE id = 102");
$expectError(static fn () => $keys->rotate(102, $actor(101), ['grace_minutes' => 0]), 'MANAGEMENT_KEY_NOT_ACTIVE', 409, 'Expired keys cannot be rotated again.');
$expectError(static fn () => $keys->update(102, $actor(101), ['expires_at' => null]), 'MANAGEMENT_KEY_EXPIRED', 409, 'A rotated-out (expired) key cannot be revived.');

$rotatedSelf = $keys->rotate(101, $actor(101), ['grace_minutes' => 0]);
$newChild = (int) $rotatedSelf['id'];
assert_same(['users:read', 'api_keys:write'], $scopes->scopesFor($newChild), 'Rotation keeps management scopes.');
assert_same(100, (int) $pdo->query("SELECT parent_api_key_id FROM api_key_management_delegations WHERE child_api_key_id = {$newChild}")->fetchColumn(), 'The rotated key stays under its parent.');
assert_same($newChild, (int) $pdo->query('SELECT parent_api_key_id FROM api_key_management_delegations WHERE child_api_key_id = 104')->fetchColumn(), 'Children move to the rotated key.');
$pdo->exec("UPDATE api_keys SET expires_at = '2000-01-01 00:00:00' WHERE id = 101");
assert_true(!$scopes->hasScope(101, 'api_keys:read'), 'The old key loses its scopes once expired.');
assert_true($scopes->hasScope(104, 'api_keys:read'), 'Grandchildren keep working through the rotated key.');
$pdo->exec("UPDATE api_keys SET status = 'disabled' WHERE id = 100");
assert_true(!$scopes->hasScope($newChild, 'api_keys:write') && !$scopes->hasScope(104, 'api_keys:read'), 'Disabling the root still revokes the rotated subtree.');
$pdo->exec("UPDATE api_keys SET status = 'active', expires_at = '2000-01-01 00:00:00' WHERE id = 100");
assert_true(!$scopes->hasScope($newChild, 'api_keys:write'), 'An expired parent revokes delegated scopes too.');
$pdo->exec('UPDATE api_keys SET expires_at = NULL WHERE id = 100');

// Delete.
$expectError(static fn () => $keys->delete(103, $actor($newChild)), 'MANAGEMENT_TARGET_FORBIDDEN', 403, 'Cannot delete a sibling management key.');
$expectError(static fn () => $keys->delete(200, $actor(100)), 'MANAGEMENT_RESOURCE_NOT_FOUND', 404, 'Cannot delete keys of other departments.');
assert_same(true, $keys->delete((int) $created['id'], $actor($newChild))['deleted'], 'Plain keys can be deleted.');
$expectError(static fn () => $keys->update((int) $created['id'], $actor($newChild), ['status' => 'active']), 'MANAGEMENT_KEY_DELETED', 409, 'Deleted keys stay deleted.');

// Quota.
$quota = $keys->quota((int) $rotatedPlain['id'], $actor(100));
assert_same(7, $quota['policies'][0]['policy_id'] ?? null, 'Quota view includes department policies.');

// Usage.
$usage = new AgentManagementUsageService();
$summary = $usage->summary($actor(100), []);
assert_same(7, $summary['totals']['request_count'], 'Department usage excludes other departments.');
assert_same(['department_id' => 1, 'user_id' => null, 'api_key_id' => null], $summary['scope'], 'Scope is reported.');
assert_same(5, $usage->summary($actor(100), ['user_id' => '11'])['totals']['request_count'], 'Usage can be narrowed to a user.');
assert_same(2, $usage->summary($actor(100), ['api_key_id' => '100'])['totals']['request_count'], 'Usage can be narrowed to a key.');
assert_same(1, count($usage->trends($actor(100), ['user_id' => '11'])['buckets']), 'Trends use the same scoping.');
$expectError(static fn () => $usage->summary($actor(100), ['user_id' => '20']), 'MANAGEMENT_RESOURCE_NOT_FOUND', 404, 'Other departments\' users are rejected.');
$expectError(static fn () => $usage->summary($actor(100), ['api_key_id' => '200']), 'MANAGEMENT_RESOURCE_NOT_FOUND', 404, 'Other departments\' keys are rejected.');
$expectError(static fn () => $usage->summary($actor(100), ['user_id' => 'abc']), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Malformed ids are rejected.');
$expectError(static fn () => $usage->summary($actor(100), ['from' => 'not a date']), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Malformed dates are rejected.');

// Department profile.
$department = (new AgentManagementDepartmentService())->current($actor(100));
assert_same('研发部', $department['name'], 'Department profile is returned.');
assert_same(2, $department['counts']['users'], 'Only active ordinary users are counted.');
assert_same($all, $department['management_scopes'], 'The caller sees its own scopes.');

// Users: disabling through PATCH drops cached keys immediately.
$users = new AgentManagementUserService();
assert_same('worker', $users->show(11, $actor(100))['username'], 'Single user lookup works.');
$expectError(static fn () => $users->show(12, $actor(100)), 'MANAGEMENT_RESOURCE_NOT_FOUND', 404, 'Administrators are not exposed.');
$newHash = $row((int) $rotatedPlain['id'])['key_hash'];
App::redis()->set((new ApiKeyService())->cacheKey($newHash), ['id' => (int) $rotatedPlain['id']], 600);
$users->update(11, $actor(100), ['status' => 'disabled']);
assert_same(null, App::redis()->get((new ApiKeyService())->cacheKey($newHash)), 'PATCH status=disabled drops the user\'s cached keys.');
$expectError(static fn () => $users->update(11, $actor(100), ['password' => '']), 'MANAGEMENT_INVALID_PAYLOAD', 422, 'Empty passwords are rejected.');

// Admin narrowing a parent's scopes prunes the delegated subtree.
(new ApiKeyService())->update(100, ['management_scopes' => ['users:read', 'rag:write']]);
assert_same(['users:read', 'rag:write'], $scopes->scopesFor(100), 'Admins can edit management scopes.');
assert_same(['users:read'], $scopes->scopesFor($newChild), 'A child keeps only scopes its parent still holds.');
assert_same([], $scopes->scopesFor(104), 'Grandchildren are pruned transitively.');
$expectError(static fn () => (new ApiKeyService())->update(100, ['management_scopes' => ['root']]), 'ADMIN_INVALID_PAYLOAD', 422, 'Unknown scopes are rejected.');
$listed = array_column((new ApiKeyService())->listWithUsage(['limit' => 100]), 'management_scopes', 'id');
assert_same(['users:read', 'rag:write'], $listed[100] ?? null, 'Admin key list exposes management scopes.');

// Users who own management keys outside the caller's subtree are off limits:
// disabling them would kill those keys (review finding: child disabling the
// owner of its parent key).
$pdo->exec("INSERT INTO users (id, department_id, username, role) VALUES (13, 1, 'second-owner', 'user')");
$key(110, 13, 1, ['users:write', 'api_keys:write']);      // root key owned by user 13
$key(111, 11, 1, ['users:write'], 110);                    // child of 110, owned by user 11
$expectError(static fn () => $users->update(13, $actor(111), ['status' => 'disabled']), 'MANAGEMENT_TARGET_FORBIDDEN', 403, 'A child cannot disable the owner of its parent key.');
$expectError(static fn () => $users->disable(13, $actor(111)), 'MANAGEMENT_TARGET_FORBIDDEN', 403, 'Nor through DELETE.');
$expectError(static fn () => $users->update(10, $actor(111), ['password' => 'x']), 'MANAGEMENT_TARGET_FORBIDDEN', 403, 'Nor the owner of another management tree.');
$pdo->exec("UPDATE users SET status = 'active' WHERE id = 11");
assert_true($scopes->hasScope(111, 'users:write'), 'Delegated scope is active while the parent owner is active.');
$pdo->exec("UPDATE users SET status = 'disabled' WHERE id = 13");
assert_true(!$scopes->hasScope(111, 'users:write'), 'Disabling the parent key\'s owner revokes the delegated subtree.');
$pdo->exec("UPDATE users SET status = 'active' WHERE id = 13");
$pdo->exec("UPDATE departments SET status = 'disabled' WHERE id = 1");
assert_true(!$scopes->hasScope(110, 'users:write'), 'A disabled department revokes management scopes.');
$pdo->exec("UPDATE departments SET status = 'active' WHERE id = 1");

// Admin scope edits keep the delegation invariant upward too.
$expectError(static fn () => (new ApiKeyService())->update(111, ['management_scopes' => ['users:write', 'rag:write']]), 'ADMIN_INVALID_PAYLOAD', 422, 'A delegated key cannot be given scopes its parent lacks.');
(new ApiKeyService())->update(111, ['management_scopes' => ['users:read']]);
assert_same(['users:read'], $scopes->scopesFor(111), 'Narrowing within the parent\'s scopes is allowed.');

// Department-scoped admins can only bind knowledge bases they manage.
$pdo->exec("INSERT INTO rag_knowledge_bases (id, department_id, visibility) VALUES (71, 2, 'department'), (72, 2, 'shared')");
App::context()->setAdminUser(['id' => 1, 'role' => 'admin', 'admin_scope' => 'departments', 'managed_department_ids' => '[1]']);
$expectError(static fn () => (new ApiKeyService())->replaceRagBindings(102, [71], []), 'ADMIN_PERMISSION_DENIED', 403, 'Another department\'s knowledge base cannot be bound.');
(new ReflectionProperty(App::class, 'context'))->setValue(null, RequestContext::fromHeaders([]));

// Outbound masking for queries that leave without a model route
// (POST /v1/rag/search, MCP search_knowledge_base).
$pdo->exec('CREATE TABLE desensitization_policies (id INTEGER PRIMARY KEY, status TEXT, scope_type TEXT, scope_id INT, action TEXT, parsers TEXT)');
$pdo->exec('CREATE TABLE desensitization_events (id INTEGER PRIMARY KEY, policy_id INT, request_id TEXT, target_type TEXT, action TEXT, match_count INT, summary TEXT)');
$pdo->exec("INSERT INTO desensitization_policies (id, status, scope_type, scope_id, action) VALUES (1, 'active', 'department', 1, 'replace'), (2, 'active', 'api_key', 103, 'block'), (3, 'active', 'model', 0, 'block')");
$desensitization = new \TokenHub\Services\DesensitizationService();
assert_same('联系 **** 或 ****', $desensitization->outboundText('联系 13800138000 或 someone@example.com', $actor(100), 'rag_search'), 'Department policy masks the query.');
$event = $pdo->query('SELECT * FROM desensitization_events ORDER BY id DESC LIMIT 1')->fetch();
assert_same([1, 'replace', 2], [(int) $event['policy_id'], $event['action'], (int) $event['match_count']], 'The masking is recorded as an outbound event.');
assert_same('rag_search', json_decode($event['summary'], true)['purpose'] ?? null, 'The event names the purpose.');
$expectError(static fn () => $desensitization->outboundText('token: abc123', $actor(103), 'mcp_search'), 'DESENSITIZATION_BLOCKED', 422, 'A blocking key policy stops the query.');
assert_same('普通问题', $desensitization->outboundText('普通问题', $actor(103), 'mcp_search'), 'Blocking policies only block when something matched.');
assert_same('no policy 13800138000', $desensitization->outboundText('no policy 13800138000', ['id' => 200, 'department_id' => 2], 'rag_search'), 'Model-scoped policies need a route and do not apply.');

// Routes.
$routes = array_map(static fn (array $route): string => $route['method'] . ' ' . $route['path'], Kernel::router()->routes());
foreach ([
    'GET /v1/management/department', 'GET /v1/management/usage/summary', 'GET /v1/management/usage/trends', 'GET /v1/management/users/{id}',
    'GET /v1/management/api-keys/{id}', 'PATCH /v1/management/api-keys/{id}', 'POST /v1/management/api-keys/{id}:rotate', 'GET /v1/management/api-keys/{id}/quota',
] as $expected) {
    assert_true(in_array($expected, $routes, true), $expected . ' is routed.');
}

echo "OK\n";
