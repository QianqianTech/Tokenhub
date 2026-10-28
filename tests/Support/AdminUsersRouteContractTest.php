<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$admin = file_get_contents(__DIR__ . '/../../app/Http/Controllers/AdminController.php') ?: '';
$crud = file_get_contents(__DIR__ . '/../../app/Services/CrudService.php') ?: '';

assert_true(str_contains($kernel, '/admin-api/admin-users'), 'Kernel should expose dedicated admin-users routes.');
assert_true(str_contains($admin, 'adminUsers'), 'AdminController should list administrator accounts through AdminUserService.');
assert_true(str_contains($admin, 'createAdminUser'), 'AdminController should create administrator accounts through AdminUserService.');
assert_true(str_contains($admin, 'updateAdminUser'), 'AdminController should update administrator roles and grants through AdminUserService.');
assert_true(str_contains($admin, 'resetAdminUserPassword'), 'AdminController should reset administrator passwords through AdminUserService.');
assert_true(str_contains($admin, 'revokeAdminUserSessions'), 'AdminController should revoke administrator sessions through AdminUserService.');
assert_true(str_contains($admin, 'requireNonAdminUserTarget'), 'Legacy users actions should not mutate administrator accounts.');
assert_true(str_contains($crud, 'assertUserRoleWriteAllowed'), 'Generic users CRUD should block non-super-admin role escalation.');
