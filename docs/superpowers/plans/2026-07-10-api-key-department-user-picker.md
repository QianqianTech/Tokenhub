# API Key Department-First User Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Require a department selection before choosing a directly assigned employee for a new API Key.

**Architecture:** `resources.js` will supply department and user reference data to the existing API Key form.  `table.js` will replace the independent search field plus user `<select>` with a single combobox-like picker driven by the selected department.  `ApiKeyService` will enforce the same direct department relationship at the create boundary.

**Tech Stack:** Native PHP 8 service tests and vanilla JavaScript admin UI.

---

### Task 1: Specify the user-facing dependency in a frontend regression test

**Files:**

- Modify: `tests/Frontend/AdminApiKeyReferenceFrontendTest.php`
- Modify: `public/admin/assets/css/app.css`
- Modify: `public/admin/index.html`
- Modify: `public/admin/assets/js/app.js`
- Modify: `public/admin/assets/js/domains.js`

- [x] **Step 1: Write the failing test**

  Add assertions that the API Key form lists `department_id` before `user_id`, `bindApiKeyUserSearch` reads `select[name="department_id"]`, and the old `api-key-user-search` standalone field is absent.

- [x] **Step 2: Run test to verify it fails**

  Run: `docker compose exec -T php php tests/Frontend/AdminApiKeyReferenceFrontendTest.php`

  Expected: `FAIL` because the current form lists the user before department and creates the standalone search field.

- [x] **Step 3: Implement the minimal frontend change**

  In `public/admin/assets/js/resources.js`, make `department_id` a department reference and place it before `user_id`.  In `public/admin/assets/js/table.js`, make `bindApiKeyUserSearch()` disable the user picker before a department is chosen, filter the users by exact `department_id`, clear selection on department change, and make the picker query only that filtered set.

- [x] **Step 4: Run test to verify it passes**

  Run: `docker compose exec -T php php tests/Frontend/AdminApiKeyReferenceFrontendTest.php`

  Expected: exit code `0`.

### Task 2: Enforce the department-user relation at the service boundary

**Files:**

- Create: `tests/Services/ApiKeyDepartmentValidationServiceTest.php`
- Modify: `app/Services/ApiKeyService.php`

- [x] **Step 1: Write the failing test**

  Add a service contract assertion requiring a user lookup by both the submitted user ID and department ID, followed by `ADMIN_INVALID_PAYLOAD` rejection when no row matches.

- [x] **Step 2: Run test to verify it fails**

  Run: `docker compose exec -T php php tests/Services/ApiKeyDepartmentValidationServiceTest.php`

  Expected: `FAIL` because `create()` currently inserts the submitted IDs without validating their relationship.

- [x] **Step 3: Implement the minimal service validation**

  At the start of `ApiKeyService::create()`, load the user by ID and compare its `department_id` with the submitted department.  Use `abort_json('ADMIN_INVALID_PAYLOAD', ...)` for a missing user or a mismatch, then continue with the existing key generation and insert.

- [x] **Step 4: Run test to verify it passes**

  Run: `docker compose exec -T php php tests/Services/ApiKeyDepartmentValidationServiceTest.php`

  Expected: exit code `0`.

### Task 3: Run the focused regression suite

**Files:**

- Verify: `tests/Frontend/AdminApiKeyReferenceFrontendTest.php`
- Verify: `tests/Frontend/AdminApiKeyUsageFrontendTest.php`
- Verify: `tests/Services/ApiKeyDepartmentValidationServiceTest.php`
- Verify: `tests/Services/ApiKeyServiceManagementTest.php`

- [x] **Step 1: Run the focused checks together**

  Run: `docker compose exec -T php php tests/Frontend/AdminApiKeyReferenceFrontendTest.php; docker compose exec -T php php tests/Frontend/AdminApiKeyUsageFrontendTest.php; docker compose exec -T php php tests/Services/ApiKeyServiceManagementTest.php`

  Expected: all three commands exit with `0`.

- [x] **Step 2: Run the complete suite**

  Run: `docker compose exec -T php php tests/run.php`

  Expected: `All tests passed`.
