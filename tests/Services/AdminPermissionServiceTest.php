<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\PermissionService;

$permissions = new PermissionService();

$super = ['id' => 1, 'role' => 'super_admin', 'admin_type' => 'super_admin', 'admin_scope' => 'global'];
$full = ['id' => 2, 'role' => 'admin', 'admin_type' => 'full_admin', 'admin_scope' => 'global'];
$userAdmin = ['id' => 3, 'role' => 'admin', 'admin_type' => 'user_admin', 'admin_scope' => 'departments'];
$ragAdmin = ['id' => 4, 'role' => 'admin', 'admin_type' => 'rag_admin', 'admin_scope' => 'departments'];
$apiAdmin = ['id' => 5, 'role' => 'admin', 'admin_type' => 'api_admin', 'admin_scope' => 'global'];
$custom = ['id' => 6, 'role' => 'admin', 'admin_type' => 'custom', 'admin_scope' => 'departments', 'admin_permission_mode' => 'custom'];

assert_true($permissions->hasCapability($super, 'admins.manage'), 'Super administrators should manage administrators.');
assert_true($permissions->hasCapability($full, 'system.manage'), 'Full administrators should manage system settings.');
assert_true(!$permissions->hasCapability($full, 'admins.manage'), 'Full administrators must not manage administrator accounts.');
assert_true($permissions->hasCapability($userAdmin, 'users.manage'), 'User administrators should manage users.');
assert_true(!$permissions->hasCapability($userAdmin, 'rag.manage'), 'User administrators must not manage RAG resources.');
assert_true($permissions->hasCapability($ragAdmin, 'rag.manage'), 'RAG administrators should manage RAG resources.');
assert_true(!$permissions->hasCapability($ragAdmin, 'api_catalog.manage'), 'RAG administrators must not manage API catalog configuration.');
assert_true($permissions->hasCapability($apiAdmin, 'api_catalog.manage'), 'API administrators should manage API catalog configuration.');
assert_true(!$permissions->hasCapability($custom, 'users.manage'), 'Custom administrators should receive no implied capabilities.');
assert_true($permissions->usesGlobalScope($full), 'Full administrators should have global data scope by default.');
assert_true(!$permissions->usesGlobalScope($userAdmin), 'Department-scoped administrators should retain department filtering.');
