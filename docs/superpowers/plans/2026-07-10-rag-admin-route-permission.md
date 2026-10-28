# RAG Admin Route Permission Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Classify generic RAG administration routes as RAG capabilities so authorized administrators can open knowledge-base management pages.

**Architecture:** `PermissionService::capabilityForAdminRequest()` is the single route-to-capability classifier used by `AdminAuthMiddleware`. Extend its RAG match to include the generic CRUD endpoint family `/admin-api/rag-*`, while preserving the existing `/admin-api/rag/` controller routes and fail-closed handling for unrelated paths.

**Tech Stack:** PHP 8, TokenHub custom contract tests.

---

### Task 1: Guard the generic RAG route classification

**Files:**
- Modify: `tests/Support/AdminPermissionRouteContractTest.php:17-21`
- Modify: `app/Services/PermissionService.php:92-93`

- [ ] **Step 1: Write the failing test**

```php
assert_same('rag.read', $permissions->capabilityForAdminRequest($request('GET', '/admin-api/rag-knowledge-bases')), 'Generic RAG CRUD reads should require RAG read capability.');
assert_same('rag.manage', $permissions->capabilityForAdminRequest($request('POST', '/admin-api/rag-vector-datasets')), 'Generic RAG CRUD writes should require RAG management capability.');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T php php tests/Support/AdminPermissionRouteContractTest.php`

Expected: assertion failure because both generic paths currently resolve to `unknown`.

- [ ] **Step 3: Implement the minimal route-family match**

```php
str_starts_with($path, '/admin-api/rag/'),
str_starts_with($path, '/admin-api/rag-') => 'rag',
```

- [ ] **Step 4: Run the focused regression test**

Run: `docker compose exec -T php php tests/Support/AdminPermissionRouteContractTest.php`

Expected: test process exits successfully.

- [ ] **Step 5: Verify adjacent authorization coverage**

Run: `docker compose exec -T php php tests/Services/AdminPermissionServiceTest.php`

Expected: test process exits successfully, confirming the existing `rag.read` and `rag.manage` grants remain intact.

- [ ] **Step 6: Inspect the final diff**

Run: `git diff --check -- app/Services/PermissionService.php tests/Support/AdminPermissionRouteContractTest.php`

Expected: no whitespace diagnostics. The current workspace may not expose usable Git metadata; in that case, inspect both files directly and report the environment limitation.
