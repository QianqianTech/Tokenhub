# API Key Model Quotas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-API-key, per-model quota enforcement and management UI support.

**Architecture:** Add a focused `ApiKeyModelQuotaService` for model-scoped counters and enforcement. Add one database table for model quotas, expose it through existing generic CRUD, and call the service from `GatewayService` after route resolution.

**Tech Stack:** PHP services, MySQL migrations, Redis-backed counters, vanilla admin JavaScript.

---

### Task 1: Service Contract Tests

**Files:**
- Create: `tests/Services/ApiKeyModelQuotaServiceTest.php`

- [ ] **Step 1: Write failing tests**

Create tests that instantiate `TokenHub\Services\ApiKeyModelQuotaService`, assert `NULL` and `0` limits are unlimited, assert a positive token limit blocks overage, and assert counter keys include API key and model ids.

- [ ] **Step 2: Run test to verify failure**

Run: `php tests/Services/ApiKeyModelQuotaServiceTest.php`
Expected: failure because `ApiKeyModelQuotaService` does not exist.

### Task 2: Migration and Resource Static Tests

**Files:**
- Create: `tests/Console/ApiKeyModelQuotaFilesTest.php`

- [ ] **Step 1: Write failing static checks**

Check that the base schema and incremental migration declare `api_key_model_quotas`, and that `public/admin/assets/js/resources.js` exposes `api-key-model-quotas`.

- [ ] **Step 2: Run test to verify failure**

Run: `php tests/Console/ApiKeyModelQuotaFilesTest.php`
Expected: failure because the migration and resource are not present.

### Task 3: Implement Model Quota Service

**Files:**
- Create: `app/Services/ApiKeyModelQuotaService.php`
- Modify: `app/Services/GatewayService.php`

- [ ] **Step 1: Add service**

Implement model quota lookup, preflight request counting with rollback, usage recording, unlimited handling for `NULL` and `0`, and Redis key generation.

- [ ] **Step 2: Wire gateway**

After model route resolution and generic quota preflight, call model quota preflight with the active API key, route, and estimated usage. After successful provider/native responses, record actual usage.

### Task 4: Database and Admin Resource

**Files:**
- Modify: `database/migrations/0001_create_tokenhub_schema.sql`
- Create: `database/migrations/2026_07_08_000012_api_key_model_quotas.sql`
- Modify: `app/Services/CrudService.php`
- Modify: `public/admin/assets/js/resources.js`

- [ ] **Step 1: Add schema**

Create the table with foreign keys, unique key on `(api_key_id, model_id, period)`, and indexes for API key, model, and status.

- [ ] **Step 2: Expose CRUD**

Allow the table in `CrudService`, add filters, and add admin resource config with fields and labels indicating empty or `0` means unlimited.

### Task 5: Verification

**Files:**
- Run tests only.

- [ ] **Step 1: Run focused tests**

Run: `php tests/Services/ApiKeyModelQuotaServiceTest.php`
Run: `php tests/Console/ApiKeyModelQuotaFilesTest.php`

- [ ] **Step 2: Run wider suite**

Run: `php tests/run.php`
