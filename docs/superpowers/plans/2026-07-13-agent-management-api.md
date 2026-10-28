# Agent Management API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide scoped API-Key authenticated management endpoints for ordinary users, API Keys, and RAG resources, with a complete Chinese agent integration guide.

**Architecture:** Store explicit management scopes per API Key. A dedicated middleware authenticates the existing Key then verifies the minimal required scope before forwarding to management controllers and services. Services reuse existing user, API Key, CRUD and RAG persistence paths while enforcing the caller department boundary and rejecting administrator targets.

**Tech Stack:** PHP 8, MySQL migrations, Redis-backed API Key auth cache, Docker Compose PHP test runner, OpenAPI 3.1, Markdown.

---

### Task 1: Scope persistence and authentication

**Files:**
- Create: `database/migrations/2026_07_13_000001_api_key_management_scopes.sql`
- Create: `app/Services/ApiKeyManagementScopeService.php`
- Create: `app/Middleware/AgentManagementAuthMiddleware.php`
- Modify: `app/Services/ApiKeyService.php`
- Test: `tests/Services/ApiKeyManagementScopeServiceTest.php`
- Test: `tests/Support/AgentManagementAuthMiddlewareContractTest.php`

- [ ] **Step 1: Write failing tests for scope implication and authorization**

Assert `users:write` authorizes both `users:write` and `users:read`, a normal API Key has no management scope, and unknown scopes fail with `403 MANAGEMENT_SCOPE_REQUIRED`.

- [ ] **Step 2: Run the two tests in Docker and confirm the expected missing-class failure**

Run: `docker compose exec -T php php tests/Services/ApiKeyManagementScopeServiceTest.php`

- [ ] **Step 3: Add the scope table, service and middleware**

The migration creates `api_key_management_scopes(api_key_id, scope_code, created_at)` with a composite primary key and a foreign key to `api_keys`. The service validates the six documented scopes and implements `write => read`. The middleware calls `ApiKeyService::authenticate()`, stores the Key in request context, then returns `403 MANAGEMENT_SCOPE_REQUIRED` when the required scope is absent.

- [ ] **Step 4: Run focused tests and migration verification**

Run: `docker compose exec -T php php bin/migrate.php` and the two focused tests. Expected: migration applies once and both tests pass.

### Task 2: User and API Key management routes

**Files:**
- Create: `app/Http/Controllers/AgentManagementController.php`
- Create: `app/Services/AgentManagementUserService.php`
- Create: `app/Services/AgentManagementApiKeyService.php`
- Modify: `app/Bootstrap/Kernel.php`
- Test: `tests/Services/AgentManagementUserServiceTest.php`
- Test: `tests/Services/AgentManagementApiKeyServiceTest.php`
- Test: `tests/Support/AgentManagementApiSurfaceContractTest.php`

- [ ] **Step 1: Write failing user-management tests**

Test `POST /v1/management/users` creates only ordinary users, `PATCH /v1/management/users/{id}` updates permitted fields, and `DELETE /v1/management/users/{id}` disables an ordinary same-department user while administrator targets and other-department IDs return 403/404.

- [ ] **Step 2: Run the user test and confirm it fails because the routes and services do not exist**

Run: `docker compose exec -T php php tests/Services/AgentManagementUserServiceTest.php`

- [ ] **Step 3: Implement users routes and service**

Register `GET/POST /v1/management/users` and `PATCH/DELETE /v1/management/users/{id}` with read/write middleware instances. The service uses the authenticated Key department, only permits role `user`, hashes creation passwords, invalidates user Keys after disable, and returns sanitized user records.

- [ ] **Step 4: Write failing API Key lifecycle tests**

Test create lists the plain Key exactly once, list and model replacement stay in the caller department, and delete invokes the existing soft-delete/cache invalidation behavior.

- [ ] **Step 5: Implement API Key lifecycle routes and service**

Register `GET/POST /v1/management/api-keys`, `PUT /v1/management/api-keys/{id}/models`, and `DELETE /v1/management/api-keys/{id}`. Reuse `ApiKeyService` only after validating the user and department ownership; allow management scopes only when the caller already has those scopes.

- [ ] **Step 6: Run all Task 2 focused tests**

Run: `docker compose exec -T php php tests/Services/AgentManagementUserServiceTest.php`, `docker compose exec -T php php tests/Services/AgentManagementApiKeyServiceTest.php`, and `docker compose exec -T php php tests/Support/AgentManagementApiSurfaceContractTest.php`.

### Task 3: RAG management routes

**Files:**
- Modify: `app/Http/Controllers/AgentManagementController.php`
- Create: `app/Services/AgentManagementRagService.php`
- Modify: `app/Bootstrap/Kernel.php`
- Test: `tests/Services/AgentManagementRagServiceTest.php`
- Test: `tests/Support/AgentManagementApiSurfaceContractTest.php`

- [ ] **Step 1: Write failing RAG isolation tests**

Test list/create/update/disable for `rag_knowledge_bases` and `rag_vector_datasets`, and assert that a caller cannot read or modify another department's resource.

- [ ] **Step 2: Run the RAG test and confirm failure because management methods are absent**

Run: `docker compose exec -T php php tests/Services/AgentManagementRagServiceTest.php`

- [ ] **Step 3: Implement RAG management service and routes**

Register `GET/POST /v1/management/rag/knowledge-bases`, `PATCH/DELETE /v1/management/rag/knowledge-bases/{id}`, `GET/POST /v1/management/rag/datasets`, and `PATCH/DELETE /v1/management/rag/datasets/{id}`. Reuse `CrudService` only after injecting the authenticated department ID and checking ownership.

- [ ] **Step 4: Run Task 3 focused tests**

Run: `docker compose exec -T php php tests/Services/AgentManagementRagServiceTest.php` and the API-surface contract test.

### Task 4: Public contract and agent examples

**Files:**
- Modify: `public/openapi.json`
- Create: `docs/agent-management-api.md`
- Modify: `docs/external-api-complete.md`
- Modify: `docs/external-api-sync.md`
- Modify: `docs/development-tasks/13-external-api-sync-agent-calls.md`
- Test: `tests/Support/AgentManagementDocumentationContractTest.php`

- [ ] **Step 1: Write failing documentation-contract test**

Assert every management route is in OpenAPI and `docs/agent-management-api.md`, and that the guide contains `curl`, `requests`, and `fetch` examples for user creation, API Key creation/deletion, and RAG knowledge-base creation.

- [ ] **Step 2: Run the documentation-contract test and confirm failure**

Run: `docker compose exec -T php php tests/Support/AgentManagementDocumentationContractTest.php`

- [ ] **Step 3: Update OpenAPI and write the Chinese guide**

Document bearer management Key authentication, scopes, pagination, idempotency, errors, and one-time Key secret handling. Include copyable curl, Python `requests`, and Node.js `fetch` examples. Link it from both existing external API docs and mark unimplemented historical endpoints as non-current.

- [ ] **Step 4: Run the documentation-contract test**

Run: `docker compose exec -T php php tests/Support/AgentManagementDocumentationContractTest.php`

### Task 5: Regression verification

**Files:**
- Test: `tests/Services/ApiKeyManagementScopeServiceTest.php`
- Test: `tests/Services/AgentManagementUserServiceTest.php`
- Test: `tests/Services/AgentManagementApiKeyServiceTest.php`
- Test: `tests/Services/AgentManagementRagServiceTest.php`
- Test: `tests/Support/AgentManagementApiSurfaceContractTest.php`
- Test: `tests/Support/AgentManagementDocumentationContractTest.php`

- [ ] **Step 1: Run migrations and all new focused tests in Docker**

Run `docker compose exec -T php php bin/migrate.php`, then execute every new test file. Expected: all pass.

- [ ] **Step 2: Run the complete PHP suite**

Run: `docker compose exec -T php php bin/run-tests.php`

- [ ] **Step 3: Validate OpenAPI JSON and document coverage**

Run PowerShell `Get-Content -Raw public/openapi.json | ConvertFrom-Json` and the documentation contract test. Expected: JSON parses and every management operation appears in both contracts.
