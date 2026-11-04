<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * SEC-101 / SEC-103 / SEC-104 / SEC-106 regression tests for the generic
 * admin CRUD (CrudService), against an in-memory SQLite stand-in:
 * - only allow-listed columns can be written; malformed keys such as "role "
 *   (trailing space) and sensitive columns (path, level, storage_path,
 *   password_hash, admin_scope, ...) are refused with 422;
 * - department path/level are computed, parents must be inside the scope;
 * - quota policies are global-admin only, quota bindings are scope checked;
 * - every response is sanitized, including the no-op update.
 * Also cross-checks that every field the console forms send is writable.
 */

use TokenHub\Services\CrudService;
use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}

final class CrudColumnsTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = preg_replace('/\bFOR UPDATE(?: SKIP LOCKED)?\b/i', '', $query) ?? $query;
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
        }

        return parent::prepare($query, $options);
    }
}

(new ReflectionProperty(App::class, 'config'))->setValue(null, \TokenHub\Support\Config::default());
$pdo = new CrudColumnsTestPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
foreach ([
    'CREATE TABLE departments (id INTEGER PRIMARY KEY, parent_id INT, name TEXT, path TEXT, level INT DEFAULT 0, sort_order INT DEFAULT 0, status TEXT DEFAULT "active", created_at TEXT, updated_at TEXT)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, department_id INT, username TEXT, password_hash TEXT, role TEXT DEFAULT "user", status TEXT DEFAULT "active", managed_department_ids TEXT, admin_scope TEXT, admin_type TEXT, admin_permission_mode TEXT, email TEXT, created_at TEXT, updated_at TEXT)',
    'CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, department_id INT, name TEXT, key_hash TEXT, status TEXT DEFAULT "active")',
    'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT, created_at TEXT)',
    'CREATE TABLE quota_policies (id INTEGER PRIMARY KEY, name TEXT, period TEXT, request_limit INT, input_token_limit INT, output_token_limit INT, total_token_limit INT, audio_second_limit INT, tts_char_limit INT, image_limit INT, video_second_limit INT, cost_limit REAL, concurrency_limit INT, status TEXT DEFAULT "active", created_at TEXT, updated_at TEXT)',
    'CREATE TABLE quota_bindings (id INTEGER PRIMARY KEY, quota_policy_id INT, target_type TEXT, target_id INT, created_at TEXT)',
    'CREATE TABLE media_assets (id INTEGER PRIMARY KEY, asset_id TEXT, owner_user_id INT, department_id INT, media_type TEXT, storage_path TEXT, mime_type TEXT, status TEXT, created_at TEXT)',
    'CREATE TABLE rag_uploads (id INTEGER PRIMARY KEY, upload_id TEXT, kb_id INT, department_id INT, storage_path TEXT, status TEXT)',
    'CREATE TABLE providers (id INTEGER PRIMARY KEY, name TEXT, protocol TEXT, base_url TEXT, encrypted_secret TEXT, default_headers TEXT, timeout_ms INT, retry_count INT DEFAULT 1, status TEXT DEFAULT "active", created_at TEXT, updated_at TEXT)',
] as $ddl) {
    $pdo->exec($ddl);
}
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);
(new ReflectionProperty(App::class, 'context'))->setValue(null, RequestContext::fromHeaders([]));

$pdo->exec("INSERT INTO departments (id, parent_id, name, path, level) VALUES (1, NULL, 'R&D', '/1/', 0), (2, NULL, 'Finance', '/2/', 0), (3, 1, 'Platform', '/1/3/', 1), (4, 3, 'Infra', '/1/3/4/', 2)");
$pdo->exec("INSERT INTO users (id, department_id, username, password_hash, role, managed_department_ids, admin_scope, admin_type) VALUES
    (1, 1, 'dept-admin', 'x', 'admin', '[1]', 'departments', 'full_admin'),
    (5, 3, 'sub-admin', 'x', 'admin', '[3]', 'departments', 'full_admin'),
    (9, 2, 'root', 'x', 'super_admin', NULL, 'global', NULL),
    (11, 3, 'dev', 'secret-hash', 'user', NULL, NULL, NULL),
    (21, 2, 'fin', 'x', 'user', NULL, NULL, NULL)");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (110, 11, 3, 'dev'), (210, 21, 2, 'fin')");
$pdo->exec("INSERT INTO quota_policies (id, name, period, request_limit) VALUES (1, 'default', 'day', 100)");
$pdo->exec("INSERT INTO quota_bindings (id, quota_policy_id, target_type, target_id) VALUES (1, 1, 'department', 2), (2, 1, 'user', 11)");
$pdo->exec("INSERT INTO media_assets (id, asset_id, owner_user_id, department_id, media_type, storage_path) VALUES (1, 'ast_1', 11, 1, 'image', '/srv/tokenhub/storage/media/2026/10/a.png')");
$pdo->exec("INSERT INTO rag_uploads (id, upload_id, kb_id, department_id, storage_path) VALUES (1, 'upl_1', 1, 1, '/srv/tokenhub/storage/rag/uploads/a.docx')");
$pdo->exec("INSERT INTO providers (id, name, protocol, base_url, encrypted_secret) VALUES (1, 'p', 'openai', 'https://api.example.com', 'v2:k1:ciphertext')");

$deptAdmin = App::db()->first('SELECT * FROM users WHERE id = 1');
$subAdmin = App::db()->first('SELECT * FROM users WHERE id = 5');
$root = App::db()->first('SELECT * FROM users WHERE id = 9');
$crud = new CrudService();

$expect = static function (callable $callback, string $code, int $status, string $message): void {
    try {
        $callback();
    } catch (HttpAbortException $exception) {
        assert_same($code, $exception->errorCode, $message . ' (code)');
        assert_same($status, $exception->status, $message . ' (status)');
        return;
    }
    throw new RuntimeException($message . ' (no abort raised)');
};
$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

// ---------------------------------------------------------------- SEC-101 users
App::context()->setAdminUser($deptAdmin);
$expect(static fn () => $crud->create('users', ['department_id' => 3, 'username' => 'evil', 'password' => 'Passw0rd!', 'role ' => 'super_admin']), 'ADMIN_VALIDATION_FAILED', 422, 'A "role " key with a trailing space is refused, not passed to SQL.');
assert_same(0, $count("SELECT COUNT(*) FROM users WHERE username = 'evil'"), 'No user was created through the trailing-space key.');
$expect(static fn () => $crud->create('users', ['department_id' => 3, 'username' => 'evil', 'ROLE' => 'super_admin']), 'ADMIN_VALIDATION_FAILED', 422, 'Upper-case keys are refused.');
$expect(static fn () => $crud->create('users', ['department_id' => 3, 'username' => 'evil', 'role`' => 'x']), 'ADMIN_VALIDATION_FAILED', 422, 'Quoting characters in keys are refused.');
$expect(static fn () => $crud->create('users', ['department_id' => 3, 'username' => 'evil', 'role = "super_admin", status' => 'active']), 'ADMIN_VALIDATION_FAILED', 422, 'SQL fragments in keys are refused.');
$expect(static fn () => $crud->create('users', ['department_id' => 3, 0 => 'x']), 'ADMIN_VALIDATION_FAILED', 422, 'Numeric keys are refused.');
$expect(static fn () => $crud->create('users', ['department_id' => 3, 'username' => 'evil', 'role' => 'super_admin']), 'ADMIN_PERMISSION_DENIED', 403, 'A department admin cannot create a super admin.');
$expect(static fn () => $crud->create('users', ['department_id' => 3, 'username' => 'evil', 'password_hash' => '$2y$10$known']), 'ADMIN_VALIDATION_FAILED', 422, 'password_hash is never written through CRUD.');
$expect(static fn () => $crud->create('users', ['department_id' => 3, 'username' => 'evil', 'admin_scope' => 'global']), 'ADMIN_VALIDATION_FAILED', 422, 'admin_scope is never written through CRUD.');
$expect(static fn () => $crud->update('users', 11, ['admin_type' => 'full_admin']), 'ADMIN_VALIDATION_FAILED', 422, 'admin_type is never written through CRUD.');
$expect(static fn () => $crud->update('users', 11, ['email' => 'attacker@example.com']), 'ADMIN_VALIDATION_FAILED', 422, 'Recovery email belongs to the admin-users endpoints.');
$expect(static fn () => $crud->update('users', 11, ['role ' => 'admin']), 'ADMIN_VALIDATION_FAILED', 422, 'Trailing-space role key is refused on update too.');
assert_same('user', (string) $pdo->query('SELECT role FROM users WHERE id = 11')->fetchColumn(), 'The target user keeps its role.');
$expect(static fn () => $crud->create('users', ['department_id' => 3, 'username' => 'evil', 'managed_department_ids' => [2]]), 'ADMIN_PERMISSION_DENIED', 403, 'A department admin cannot hand out departments outside the scope.');

$created = $crud->create('users', ['department_id' => 3, 'username' => 'new-user', 'password' => 'Passw0rd!', 'role' => 'user', 'managed_department_ids' => [3]]);
assert_same('new-user', $created['username'] ?? null, 'The console user form (department, username, password, role, managed departments) still works.');
assert_true(!array_key_exists('password_hash', $created), 'Created users are returned without password_hash.');
assert_true(password_verify('Passw0rd!', (string) $pdo->query("SELECT password_hash FROM users WHERE username = 'new-user'")->fetchColumn()), 'The password is stored hashed.');
$auditDetails = (string) $pdo->query("SELECT details FROM audit_logs WHERE action = 'admin.create' AND target_type = 'users' ORDER BY id DESC LIMIT 1")->fetchColumn();
assert_true(!str_contains($auditDetails, 'password_hash') && !str_contains($auditDetails, '$2y$'), 'The audit row does not carry the password hash.');

App::context()->setAdminUser($root);
$expect(static fn () => $crud->create('users', ['department_id' => 2, 'username' => 'x', 'role' => 'root']), 'ADMIN_VALIDATION_FAILED', 422, 'Unknown roles are refused.');
$superCreated = $crud->create('users', ['department_id' => 2, 'username' => 'promoted', 'role' => 'admin', 'managed_department_ids' => [2]]);
assert_same('admin', $superCreated['role'] ?? null, 'A super admin can still create administrators through the users form.');

// ---------------------------------------------------------------- SEC-106 no-op update is sanitized
$noop = $crud->update('users', 11, ['expected_updated_at' => '']);
assert_true(!array_key_exists('password_hash', $noop), 'An empty update returns the row without password_hash.');
$noopProvider = $crud->update('providers', 1, ['id' => 1]);
assert_true(!array_key_exists('encrypted_secret', $noopProvider), 'An empty provider update returns no encrypted_secret.');
assert_same('configured', $noopProvider['secret_status'] ?? null, 'The sanitized provider row reports the secret status.');

// Provider configuration: replacing only the Key must preserve the connection
// settings, encrypt the new credential, and keep it out of responses/audits.
$beforeProvider = App::db()->first('SELECT * FROM providers WHERE id = 1');
$rotatedProvider = $crud->update('providers', 1, ['secret' => 'sk-synthetic-provider-replacement']);
$afterProvider = App::db()->first('SELECT * FROM providers WHERE id = 1');
$replacementCipher = (string) $afterProvider['encrypted_secret'];
assert_same('sk-synthetic-provider-replacement', \TokenHub\Support\Crypto::decrypt($replacementCipher), 'The new Key is usable after saving.');
assert_true($replacementCipher !== 'sk-synthetic-provider-replacement', 'The replacement Key is encrypted at rest.');
foreach (['name', 'protocol', 'base_url', 'timeout_ms', 'retry_count', 'default_headers', 'status'] as $column) {
    assert_same($beforeProvider[$column], $afterProvider[$column], "Changing only the Key keeps provider {$column}.");
}
assert_same('configured', $rotatedProvider['secret_status'] ?? null, 'Replacing a Key reports the configured status.');
assert_true(!array_key_exists('secret', $rotatedProvider) && !array_key_exists('encrypted_secret', $rotatedProvider), 'Replacing a Key returns no credential.');
$editedProvider = $crud->update('providers', 1, [
    'name' => '千问 Token Plan',
    'base_url' => 'https://token-plan.maas.qianwenaiapi.com/compatible-mode/v1',
    'secret' => '',
]);
assert_same($replacementCipher, App::db()->first('SELECT encrypted_secret FROM providers WHERE id = 1')['encrypted_secret'], 'Leaving the Key blank keeps the existing encrypted credential.');
assert_same('https://token-plan.maas.qianwenaiapi.com/compatible-mode/v1', $editedProvider['base_url'], 'The new Qwen Token Plan base URL can be saved on an existing provider.');
$providerAudits = (string) $pdo->query("SELECT group_concat(details) FROM audit_logs WHERE target_type = 'providers'")->fetchColumn();
assert_true(!str_contains($providerAudits, 'sk-synthetic-provider-replacement') && !str_contains($providerAudits, $replacementCipher), 'Provider edits do not put plaintext or encrypted credentials into audit details.');

// ---------------------------------------------------------------- SEC-101 storage paths
$expect(static fn () => $crud->update('media_assets', 1, ['storage_path' => '/srv/tokenhub/.env']), 'ADMIN_VALIDATION_FAILED', 422, 'media_assets.storage_path cannot be redirected to .env, even by a super admin.');
$expect(static fn () => $crud->update('media_assets', 1, ['storage_path' => '../../.env']), 'ADMIN_VALIDATION_FAILED', 422, 'Relative traversal in storage_path is refused.');
$expect(static fn () => $crud->update('rag_uploads', 1, ['storage_path' => '/srv/tokenhub/.env']), 'ADMIN_VALIDATION_FAILED', 422, 'rag_uploads.storage_path is not writable through CRUD.');
assert_same('/srv/tokenhub/storage/media/2026/10/a.png', (string) $pdo->query('SELECT storage_path FROM media_assets WHERE id = 1')->fetchColumn(), 'The stored media path is unchanged.');
$expect(static fn () => $crud->update('providers', 1, ['encrypted_secret' => 'v2:k1:forged']), 'ADMIN_VALIDATION_FAILED', 422, 'encrypted_secret is only written from the secret field.');

// ---------------------------------------------------------------- SEC-103 departments
$expect(static fn () => $crud->update('departments', 1, ['path' => '/']), 'ADMIN_VALIDATION_FAILED', 422, 'Department path cannot be written.');
$expect(static fn () => $crud->update('departments', 1, ['level' => 0]), 'ADMIN_VALIDATION_FAILED', 422, 'Department level cannot be written.');
$expect(static fn () => $crud->create('departments', ['name' => 'x', 'path' => '/']), 'ADMIN_VALIDATION_FAILED', 422, 'Department path cannot be set on create.');
App::context()->setAdminUser($deptAdmin);
$expect(static fn () => $crud->update('departments', 3, ['path' => '/']), 'ADMIN_VALIDATION_FAILED', 422, 'A scoped admin cannot widen its scope through path.');
assert_same('/1/3/', (string) $pdo->query('SELECT path FROM departments WHERE id = 3')->fetchColumn(), 'The department path is unchanged.');
$expect(static fn () => $crud->create('departments', ['name' => 'Rogue', 'parent_id' => 2]), 'ADMIN_PERMISSION_DENIED', 403, 'A scoped admin cannot create under a foreign parent.');
$expect(static fn () => $crud->create('departments', ['name' => 'Rogue root']), 'ADMIN_PERMISSION_DENIED', 403, 'A scoped admin cannot create a top-level department.');
$expect(static fn () => $crud->update('departments', 3, ['parent_id' => 2]), 'ADMIN_PERMISSION_DENIED', 403, 'A scoped admin cannot move a department under a foreign parent.');
$expect(static fn () => $crud->update('departments', 3, ['parent_id' => 4]), 'ADMIN_INVALID_DEPARTMENT_MOVE', 422, 'A department cannot move into its own descendant.');
$child = $crud->create('departments', ['name' => 'Team', 'parent_id' => 3, 'sort_order' => 2]);
assert_same('/1/3/' . $child['id'] . '/', $child['path'] ?? null, 'The department form (name, parent, sort order) works and path is computed.');
assert_same(2, (int) ($child['level'] ?? -1), 'Level is computed from the parent.');
App::context()->setAdminUser($subAdmin);
$renamed = $crud->update('departments', 3, ['name' => 'Platform 2', 'parent_id' => 1, 'sort_order' => 1]);
assert_same('Platform 2', $renamed['name'] ?? null, 'Editing the managed root department resends its (foreign) parent unchanged and is allowed.');

// ---------------------------------------------------------------- SEC-104 quotas
App::context()->setAdminUser($deptAdmin);
$expect(static fn () => $crud->create('quota_policies', ['name' => 'mine', 'period' => 'day', 'request_limit' => 1]), 'ADMIN_PERMISSION_DENIED', 403, 'Scoped admins cannot create quota policies.');
$expect(static fn () => $crud->update('quota_policies', 1, ['request_limit' => 999999]), 'ADMIN_PERMISSION_DENIED', 403, 'Scoped admins cannot change platform quota policies.');
$expect(static fn () => $crud->disable('quota_policies', 1), 'ADMIN_PERMISSION_DENIED', 403, 'Scoped admins cannot disable platform quota policies.');
assert_same(100, (int) $pdo->query('SELECT request_limit FROM quota_policies WHERE id = 1')->fetchColumn(), 'The policy is unchanged.');
$expect(static fn () => $crud->create('quota_bindings', ['quota_policy_id' => 1, 'target_type' => 'department', 'target_id' => 2]), 'ADMIN_PERMISSION_DENIED', 403, 'Binding a foreign department is refused.');
$expect(static fn () => $crud->create('quota_bindings', ['quota_policy_id' => 1, 'target_type' => 'api_key', 'target_id' => 210]), 'ADMIN_PERMISSION_DENIED', 403, 'Binding a foreign API key is refused.');
$expect(static fn () => $crud->create('quota_bindings', ['quota_policy_id' => 1, 'target_type' => 'user', 'target_id' => 21]), 'ADMIN_PERMISSION_DENIED', 403, 'Binding a foreign user is refused.');
$expect(static fn () => $crud->create('quota_bindings', ['quota_policy_id' => 1, 'target_type' => 'global', 'target_id' => 1]), 'ADMIN_VALIDATION_FAILED', 422, 'Unknown target types are refused.');
$expect(static fn () => $crud->update('quota_bindings', 1, ['quota_policy_id' => 1]), 'ADMIN_PERMISSION_DENIED', 403, 'Changing a binding of a foreign target is refused.');
$expect(static fn () => $crud->update('quota_bindings', 2, ['target_type' => 'department', 'target_id' => 2]), 'ADMIN_PERMISSION_DENIED', 403, 'Re-pointing an own binding at a foreign target is refused.');
$binding = $crud->create('quota_bindings', ['quota_policy_id' => 1, 'target_type' => 'api_key', 'target_id' => 110]);
assert_same(110, (int) ($binding['target_id'] ?? 0), 'Binding an API key inside the scope works.');
App::context()->setAdminUser($root);
$policy = $crud->create('quota_policies', ['name' => 'global', 'period' => 'month', 'request_limit' => '', 'cost_limit' => 10, 'status' => 'active']);
assert_same('global', $policy['name'] ?? null, 'A global admin can still create quota policies through the form.');

// ---------------------------------------------------------------- console forms stay writable
$resourcesJs = (string) file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js');
$formFields = static function (string $key) use ($resourcesJs): array {
    $start = strpos($resourcesJs, '"' . $key . '": {');
    assert_true($start !== false, "resources.js defines {$key}.");
    $depth = 0;
    $end = $start;
    for ($i = strpos($resourcesJs, '{', $start), $n = strlen($resourcesJs); $i < $n; $i++) {
        $char = $resourcesJs[$i];
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }
    $block = substr($resourcesJs, $start, $end - $start);
    $formAt = strpos($block, 'form: [');
    if ($formAt === false) {
        return [];
    }
    preg_match_all('/\{ name: "([a-z_]+)"/', substr($block, $formAt), $matches);

    return array_values(array_unique($matches[1]));
};
$resourceTables = [
    'departments' => 'departments', 'users' => 'users', 'providers' => 'providers', 'models' => 'models', 'model-prices' => 'model_prices',
    'api-key-model-quotas' => 'api_key_model_quotas', 'quota-policies' => 'quota_policies', 'quota-bindings' => 'quota_bindings',
    'desensitization-policies' => 'desensitization_policies', 'rag-knowledge-bases' => 'rag_knowledge_bases',
    'rag-vector-datasets' => 'rag_vector_datasets', 'rag-vector-configs' => 'rag_vector_configs',
];
// Fields the page modules add beside the resources.js forms.
$extra = [
    'departments' => ['status'], // departments.js edit dialog
    'models' => ['capabilities', 'capability_tags'], // capability editor
    'quota-bindings' => ['target_type', 'target_id'], // target_ref is split by resourcePayload()
    'rag-knowledge-bases' => ['chunking_profile', 'retrieval_profile'], // knowledgeBasePayload()
];
$virtual = ['quota-bindings' => ['target_ref']];
// Knowledge-base form keys folded into chunking_profile / retrieval_profile by forms.js.
$formsJs = (string) file_get_contents(__DIR__ . '/../../public/admin/assets/js/lib/forms.js');
foreach (['KB_RETRIEVAL_KEYS', 'KB_FORM_KEYS'] as $constant) {
    if (preg_match('/const ' . $constant . ' = \[([^\]]*)\]/', $formsJs, $m) === 1) {
        preg_match_all('/"([a-z_]+)"/', $m[1], $keys);
        $virtual['rag-knowledge-bases'] = [...($virtual['rag-knowledge-bases'] ?? []), ...$keys[1]];
    }
}
foreach ($resourceTables as $resource => $table) {
    $fields = array_diff([...$formFields($resource), ...($extra[$resource] ?? [])], $virtual[$resource] ?? []);
    assert_true($fields !== [], "The {$resource} form has fields.");
    foreach ($fields as $field) {
        assert_true(in_array($field, CrudService::writableColumns($table, true), true), "Console form field {$resource}.{$field} must stay writable on create.");
        assert_true(in_array($field, CrudService::writableColumns($table, false), true), "Console form field {$resource}.{$field} must stay writable on update.");
    }
}
foreach (['setting_key', 'setting_value', 'description'] as $field) {
    assert_true(in_array($field, CrudService::writableColumns('system_settings'), true), "system-settings.js writes {$field}.");
}
foreach ([
    'users' => ['password_hash', 'admin_scope', 'admin_type', 'admin_permission_mode', 'email', 'id', 'last_login_at'],
    'departments' => ['path', 'level', 'id'],
    'providers' => ['encrypted_secret'],
    'media_assets' => ['storage_path', 'department_id', 'owner_user_id'],
    'rag_uploads' => ['storage_path', 'status'],
    'rag_import_templates' => ['storage_path'],
    'rag_image_assets' => ['storage_path', 'thumbnail_path'],
    'rag_knowledge_bases' => ['owner_user_id', 'visibility'],
    'rag_vector_datasets' => ['collection_prefix', 'acl_mode'],
    'rag_knowledge_base_bindings' => ['granted_by_user_id'],
] as $table => $forbidden) {
    foreach ($forbidden as $column) {
        assert_true(!in_array($column, CrudService::writableColumns($table, true), true), "{$table}.{$column} must not be writable through generic CRUD.");
    }
}

echo "PASS\n";
