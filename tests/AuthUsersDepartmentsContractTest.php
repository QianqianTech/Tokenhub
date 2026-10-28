<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);

$kernel = file_get_contents($root . '/app/Bootstrap/Kernel.php') ?: '';
$admin = file_get_contents($root . '/app/Http/Controllers/AdminController.php') ?: '';
$crud = file_get_contents($root . '/app/Services/CrudService.php') ?: '';
$department = is_file($root . '/app/Services/DepartmentService.php') ? (file_get_contents($root . '/app/Services/DepartmentService.php') ?: '') : '';
$apiKey = file_get_contents($root . '/app/Services/ApiKeyService.php') ?: '';
$auth = file_get_contents($root . '/app/Services/AuthService.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/2026_07_08_000002_auth_users_departments.sql') ?: '';

assert_true(str_contains($migration, 'CREATE TABLE IF NOT EXISTS departments'), '02 migration should define departments table.');
assert_true(str_contains($migration, 'CREATE TABLE IF NOT EXISTS users'), '02 migration should define users table.');
assert_true(str_contains($migration, 'CREATE TABLE IF NOT EXISTS admin_sessions'), '02 migration should define admin_sessions table.');

assert_true(str_contains($kernel, '/admin-api/departments/{id}/move'), 'Kernel should expose department move endpoint.');
assert_true(str_contains($kernel, '/admin-api/departments/sort'), 'Kernel should expose department sort endpoint.');
assert_true(str_contains($kernel, '/admin-api/departments/{id}/status'), 'Kernel should expose department status endpoint.');
assert_true(str_contains($kernel, '/admin-api/users/{id}/reset-password'), 'Kernel should expose user password reset endpoint.');
assert_true(str_contains($kernel, '/admin-api/users/{id}/department'), 'Kernel should expose user department adjustment endpoint.');
assert_true(str_contains($kernel, '/admin-api/users/{id}/status'), 'Kernel should expose user status endpoint.');

assert_true(str_contains($admin, 'moveDepartment'), 'AdminController should implement department move action.');
assert_true(str_contains($admin, 'sortDepartments'), 'AdminController should implement department sort action.');
assert_true(str_contains($admin, 'updateDepartmentStatus'), 'AdminController should implement department status action.');
assert_true(str_contains($admin, 'resetUserPassword'), 'AdminController should implement user password reset action.');
assert_true(str_contains($admin, 'moveUserDepartment'), 'AdminController should implement user department adjustment action.');
assert_true(str_contains($admin, 'updateUserStatus'), 'AdminController should implement user status action.');

assert_true(str_contains($department, 'function move'), 'DepartmentService should move departments.');
assert_true(str_contains($department, 'function sort'), 'DepartmentService should sort departments.');
assert_true(str_contains($department, 'function rebuildPath'), 'DepartmentService should rebuild descendant paths.');
assert_true(str_contains($department, 'invalidateDepartmentApiKeys'), 'DepartmentService should invalidate API keys after department changes.');

assert_true(str_contains($crud, 'assertExistingScope'), 'CrudService should enforce target-row scope before updates/disables.');
assert_true(str_contains($crud, 'invalidateUserSessions'), 'CrudService should invalidate sessions after user disable.');
assert_true(str_contains($crud, 'invalidateUserApiKeys'), 'CrudService should invalidate user API key cache after user disable.');

assert_true(str_contains($apiKey, 'invalidateByUser'), 'ApiKeyService should invalidate cache by user.');
assert_true(str_contains($apiKey, 'invalidateByDepartment'), 'ApiKeyService should invalidate cache by department.');

assert_true(str_contains($auth, 'role IN ("admin", "super_admin")'), 'Admin login should only allow administrator roles.');
