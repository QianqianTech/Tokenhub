<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * Knowledge-set access control (RAG-002, RAG-005, RAG-006, RAG-010..RAG-015):
 * department tree inheritance with include_descendants, visibility semantics,
 * owner access, expiry, API key restriction mode, dataset inherit/custom
 * modes, no fan-out of dataset grants, user department moves and inactive
 * ancestors.
 */

use TokenHub\Services\RagAccessResolver;
use TokenHub\Services\RagBindingService;
use TokenHub\Services\RagPermissionService;

$pdo = rag_sqlite_fixture();

// 集团(1) > 研发中心(2) > 平台组(3); 财务部(4)
$pdo->exec("INSERT INTO departments (id, parent_id, name, path, level) VALUES (1, NULL, '集团', '/1/', 0), (2, 1, '研发中心', '/1/2/', 1), (3, 2, '平台组', '/1/2/3/', 2), (4, NULL, '财务部', '/4/', 0)");
$pdo->exec("INSERT INTO users (id, department_id, username) VALUES (10, 3, 'dev'), (20, 4, 'finance')");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (100, 10, 3, 'dev-key'), (101, 10, 3, 'dev-restricted'), (200, 20, 4, 'finance-key')");

$kb = static function (int $id, string $visibility, ?int $department, ?int $owner = null) use ($pdo): void {
    $pdo->prepare('INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id, visibility, owner_user_id) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, 'kb_' . $id, 'KB ' . $id, $department, $visibility, $owner]);
};
$ds = static function (int $id, int $kbId, string $mode = 'inherit') use ($pdo): void {
    $pdo->prepare('INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name, acl_mode) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, 'ds_' . $id, $kbId, 'DS ' . $id, $mode]);
};
$kbGrant = static function (int $kbId, string $type, int $subject, string $permission = 'read', int $descendants = 1, ?string $expires = null) use ($pdo): void {
    $pdo->prepare('INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission, include_descendants, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$kbId, $type, $subject, $permission, $descendants, $expires]);
};

$kb(1, 'private', 1);  $ds(11, 1); $ds(12, 1, 'custom');      $kbGrant(1, 'department', 1);           // inherited by sub-departments
$kb(2, 'private', 1);  $ds(21, 2);                             $kbGrant(2, 'department', 1, 'read', 0); // only 集团 itself
$kb(3, 'department', 2); $ds(31, 3);                                                                   // 研发中心 and below
$kb(4, 'shared', 4);   $ds(41, 4);                                                                     // everyone
$kb(5, 'private', 3);  $ds(51, 5);                             $kbGrant(5, 'api_key', 101);             // only the restricted key
$kb(6, 'private', 3, 10); $ds(61, 6);                                                                  // owned by user 10
$kb(7, 'private', 3);  $ds(71, 7);                             $kbGrant(7, 'user', 10, 'write', 1, '2000-01-01 00:00:00'); // expired
$kb(8, 'private', 4);  $ds(81, 8); $ds(82, 8);
(new RagBindingService())->grantDataset(81, 'user', 10, 'read');                                       // one dataset only
$kb(9, 'private', 3);  $ds(91, 9);                             $kbGrant(9, 'department', 3, 'write');

$devKey = ['id' => 100, 'user_id' => 10, 'department_id' => 3];
$restrictedKey = ['id' => 101, 'user_id' => 10, 'department_id' => 3];
$financeKey = ['id' => 200, 'user_id' => 20, 'department_id' => 4];

$kbIds = static fn (array $caller): array => array_map(static fn (array $row): string => $row['kb_id'], (new RagPermissionService())->listKnowledgeBases($caller)['knowledge_bases']);
$datasetIds = static function (array $caller, string $kbId): array {
    try {
        return array_map(static fn (array $row): string => $row['dataset_id'], (new RagPermissionService())->listDatasets($caller, $kbId)['datasets']);
    } catch (\TokenHub\Support\HttpAbortException) {
        return [];
    }
};

$visible = $kbIds($devKey);
sort($visible);
assert_same(['kb_1', 'kb_3', 'kb_4', 'kb_6', 'kb_8', 'kb_9'], $visible, 'Dev key sees inherited, department-visible, shared, owned, dataset-granted and own-department knowledge bases.');
assert_true(!in_array('kb_2', $visible, true), 'A grant with include_descendants=0 stays on the granted department.');
assert_true(!in_array('kb_7', $visible, true), 'Expired grants do not apply.');
assert_true(!in_array('kb_5', $visible, true), 'Another key\'s grant does not leak to the user\'s other keys.');

assert_same(['kb_5'], $kbIds($restrictedKey), 'A key with explicit grants is restricted to them (no user, department or visibility inheritance).');

$finance = $kbIds($financeKey);
sort($finance);
assert_same(['kb_4'], $finance, 'Another department only sees the shared knowledge base (kb_8 belongs to 财务部 but is private with no grant).');

assert_same(['ds_11'], $datasetIds($devKey, 'kb_1'), 'Inherit datasets follow the knowledge base grant; custom datasets need their own grant.');
assert_same(['ds_81'], $datasetIds($devKey, 'kb_8'), 'Granting one dataset does not grant its siblings or the knowledge base (no fan-out).');
assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM rag_knowledge_base_bindings WHERE kb_id = 8")->fetchColumn(), 'grantDataset writes no knowledge-base row.');

$permission = new RagPermissionService();
assert_true($permission->hasKnowledgeBasePermission($devKey, 6, 'admin'), 'The knowledge base owner has admin.');
assert_true($permission->hasKnowledgeBasePermission($devKey, 9, 'write'), 'Own-department write grant applies.');
assert_true(!$permission->hasKnowledgeBasePermission($devKey, 1, 'write'), 'An inherited read grant does not allow writes.');
assert_true(!$permission->hasKnowledgeBasePermission($devKey, 8, 'write'), 'A dataset read grant does not give write on the knowledge base.');
rag_expect_abort(static fn () => (new RagPermissionService())->datasetByExternalId($devKey, 'ds_12', 'read'), 'RAG_DATASET_FORBIDDEN', 'Custom dataset without own grant is forbidden.');
rag_expect_abort(static fn () => (new RagPermissionService())->resolveDatasets($financeKey, [], [], ['kb_1']), 'RAG_KB_FORBIDDEN', 'Resolving a foreign knowledge base is forbidden.');

// Default datasets: key > user > own department > ancestors, then priority.
$pdo->exec("UPDATE rag_dataset_bindings SET is_default = 1, priority = 5 WHERE dataset_id = 81");
$pdo->exec("INSERT INTO rag_dataset_bindings (dataset_id, subject_type, subject_id, permission, is_default, priority) VALUES (11, 'department', 1, 'read', 1, 1)");
$defaults = array_map(static fn (array $row): string => $row['dataset_id'], (new RagPermissionService())->resolveDatasets($devKey));
assert_same(['ds_81', 'ds_11'], $defaults, 'User defaults come before ancestor-department defaults regardless of priority.');

// Department move: the user's current department wins over the stale key row.
$pdo->exec('UPDATE users SET department_id = 4 WHERE id = 10');
$moved = $kbIds($devKey);
sort($moved);
assert_same(['kb_4', 'kb_6', 'kb_8'], $moved, 'After moving to 财务部 the old department grants stop applying; owner and user grants remain.');
$pdo->exec('UPDATE users SET department_id = 3 WHERE id = 10');

// Inactive ancestor cuts inheritance from itself and above.
$pdo->exec("UPDATE departments SET status = 'disabled' WHERE id = 2");
$resolver = new RagAccessResolver($devKey);
assert_same([3], $resolver->departmentIds(), 'Only active departments below the disabled one remain in the chain.');
$cut = $kbIds($devKey);
sort($cut);
assert_same(['kb_4', 'kb_6', 'kb_8', 'kb_9'], $cut, 'Grants on or above a disabled department no longer apply.');
$pdo->exec("UPDATE departments SET status = 'active' WHERE id = 2");

// Malformed path never matches unrelated departments.
$pdo->exec("INSERT INTO departments (id, parent_id, name, path, level) VALUES (5, NULL, '异常', '/', 0)");
$pdo->exec("INSERT INTO users (id, department_id, username) VALUES (30, 5, 'odd')");
assert_same([5], (new RagAccessResolver(['id' => 0, 'user_id' => 30, 'department_id' => 5]))->departmentIds(), 'A malformed path falls back to the department itself.');

// Revocation.
(new RagBindingService())->revokeDataset(81, 'user', 10);
assert_true(!in_array('kb_8', $kbIds($devKey), true), 'Revoking the dataset grant removes access.');

echo "PASS\n";
