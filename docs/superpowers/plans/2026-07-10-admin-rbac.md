# Administrator RBAC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add preset administrator types, per-administrator selectable capabilities, and global-or-department data scope while protecting super-administrator accounts.

**Architecture:** Keep the existing `super_admin` / `admin` / `user` identity column for login compatibility. Add metadata and a normalized capability binding table for administrators. `PermissionService` becomes the single source of effective capabilities and is consulted by a route middleware after session authentication; existing department scoping remains a second mandatory gate.

**Tech Stack:** PHP 8, MySQL migrations, existing Router middleware, vanilla JavaScript admin UI, Docker Compose PHP tests.

---

### Task 1: Persist administrator types, scope, and custom capabilities

**Files:**
- Create: `database/migrations/2026_07_10_000016_admin_rbac_auth.sql`
- Test: `tests/Services/AdminPermissionServiceTest.php`

- [ ] **Step 1: Write a failing permission-service test**

Create test cases for the five presets (`full_admin`, `user_admin`, `rag_admin`, `api_admin`, `custom`), custom capability replacement, and global versus department scope.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T php php tests/Services/AdminPermissionServiceTest.php`

Expected: FAIL because no capability registry or bindings exist.

- [ ] **Step 3: Create the migration**

Add nullable administrator-only metadata to `users`: `admin_type`, `admin_scope`, and `admin_permission_mode`. Backfill super administrators as `super_admin/global/preset` and current administrators as `full_admin/global/preset`. Create `admin_user_permissions(user_id, permission_code)` with a unique key, foreign key, and index by capability.

- [ ] **Step 4: Run the migration and test**

Run: `docker compose exec -T php php bin/migrate.php` and `docker compose exec -T php php tests/Services/AdminPermissionServiceTest.php`

Expected: migration applied and test progresses to the next missing behavior.

### Task 2: Centralize effective capability and scope decisions

**Files:**
- Modify: `app/Services/PermissionService.php`
- Create: `app/Middleware/AdminPermissionMiddleware.php`
- Test: `tests/Services/AdminPermissionServiceTest.php`

- [ ] **Step 1: Extend the failing test**

Assert that super administrators have every declared capability, full administrators have every business capability except `admins.manage`, special presets are constrained to their resource families, and a custom administrator without a capability is denied.

- [ ] **Step 2: Implement capability registry and checks**

Define stable resource-action capability codes, preset maps, effective permission loading, `requireCapability()`, and scope logic. `admin_scope=global` bypasses department filtering only after capability authorization. Reject all non-super operations targeting a `super_admin` account.

- [ ] **Step 3: Implement middleware**

Require a declared capability after `AdminAuthMiddleware`; return `ADMIN_PERMISSION_DENIED` with HTTP 403 when the current session lacks it.

- [ ] **Step 4: Run the focused test**

Run: `docker compose exec -T php php tests/Services/AdminPermissionServiceTest.php`

Expected: PASS.

### Task 3: Enforce capabilities on management APIs

**Files:**
- Modify: `app/Bootstrap/Kernel.php`
- Modify: `app/Services/CrudService.php`
- Modify: `app/Services/AdminUserService.php`
- Test: `tests/Support/AdminPermissionRouteContractTest.php`
- Test: `tests/Services/AdminUserServiceTest.php`

- [ ] **Step 1: Write failing route and account-protection tests**

Assert generic resource routes carry capability middleware, administrator management requires `admins.manage`, and non-super actors cannot update status, password, sessions, role, type, scope, or permissions of a super administrator.

- [ ] **Step 2: Add route capability declarations**

Map generic resources and dedicated API, RAG, system, WAF, dashboard, export, and audit endpoints to their capability family. Read and write operations receive distinct permissions.

- [ ] **Step 3: Harden service-level checks**

Make `CrudService` require resource capabilities in addition to the existing department scope. Make `AdminUserService` synchronize explicit permissions, expose effective permissions, revoke sessions after authorization changes, and guard every super-admin target mutation.

- [ ] **Step 4: Run focused regression tests**

Run: `docker compose exec -T php php tests/Services/AdminPermissionServiceTest.php`, `docker compose exec -T php php tests/Services/AdminUserServiceTest.php`, and `docker compose exec -T php php tests/Support/AdminPermissionRouteContractTest.php`

Expected: PASS.

### Task 4: Expose permissions in login state and administrator management UI

**Files:**
- Modify: `app/Services/AuthService.php`
- Modify: `app/Http/Controllers/AdminController.php`
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`
- Test: `tests/Frontend/AdminUsersManagementFrontendTest.php`

- [ ] **Step 1: Write failing API/UI contract tests**

Assert `/admin-api/me` returns `permissions`, `admin_type`, and `admin_scope`; assert the administrator form exposes preset type, scope, and grouped capability selection.

- [ ] **Step 2: Implement session/UI data flow**

Return effective permissions from the authenticated-user payload. Filter navigation and resource actions from capabilities rather than only the legacy role. Add administrator form controls for type, global/department scope, and multi-select capability overrides.

- [ ] **Step 3: Run frontend contract checks**

Run: `docker compose exec -T php php tests/Frontend/AdminUsersManagementFrontendTest.php`

Expected: PASS.

### Task 5: Verify compatibility and document operation

**Files:**
- Modify: `docs/tokenhub-usage-management-guide.md`
- Test: `tests/Support/AdminUsersRouteContractTest.php`

- [ ] **Step 1: Update administrator operation guide**

Document the five presets, capability override behavior, data scope, super-administrator immutability, and session revocation after a permission change.

- [ ] **Step 2: Run full relevant verification**

Run: `docker compose exec -T php php bin/run-tests.php` and `node --check public/admin/assets/js/resources.js` and `node --check public/admin/assets/js/table.js`

Expected: all PHP test files pass and both JavaScript syntax checks exit 0.
