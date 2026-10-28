<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ContentArchiveService;

$service = new ContentArchiveService();

assert_true($service->canViewRaw(['role' => 'super_admin'], ['raw_allowed_roles' => ['super_admin']]), 'Super admin should view raw content by default.');
assert_true(!$service->canViewRaw(['role' => 'admin'], ['raw_allowed_roles' => ['super_admin']]), 'Admin should not view raw content unless configured.');
assert_true($service->canViewRaw(['role' => 'admin'], ['raw_allowed_roles' => ['super_admin', 'admin']]), 'Configured admin role should view raw content.');

[$userWhere, $userBindings] = $service->scopeSql(['role' => 'user', 'id' => 11, 'department_id' => 3], 'r');
assert_true(str_contains($userWhere, 'r.user_id = ?'), 'User scope should constrain by own user id.');
assert_same([11], $userBindings, 'User scope should bind own user id.');

[$superWhere, $superBindings] = $service->scopeSql(['role' => 'super_admin', 'id' => 1], 'r');
assert_same('', $superWhere, 'Super admin scope should not add filters.');
assert_same([], $superBindings, 'Super admin scope should not bind filters.');

$filters = $service->normalizeFilters([
    'user_id' => '5',
    'department_id' => '8',
    'api_key_id' => '13',
    'model_id' => '21',
    'from' => '2026-07-01 00:00:00',
    'to' => '2026-07-08 23:59:59',
    'include_raw' => 'true',
    'Authorization' => 'Bearer secret',
]);

assert_same(5, $filters['user_id'], 'Filters should normalize user_id.');
assert_same(8, $filters['department_id'], 'Filters should normalize department_id.');
assert_same(13, $filters['api_key_id'], 'Filters should normalize api_key_id.');
assert_same(21, $filters['model_id'], 'Filters should normalize model_id.');
assert_same(true, $filters['include_raw'], 'Filters should normalize include_raw.');
assert_true(!array_key_exists('Authorization', $filters), 'Filters should drop sensitive arbitrary keys.');
