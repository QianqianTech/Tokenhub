# RAG Vector Model Configuration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow administrators to safely configure, validate, probe, and bind embedding models for RAG, including knowledge-base defaults.

**Architecture:** Keep `rag_vector_configs` as the source of truth. A focused service validates provider-backed configurations and resolves effective configs with dataset → knowledge base → global precedence. The generic admin CRUD delegates RAG writes to that service; a dedicated RAG controller route probes the configured provider without writing vectors; the admin resource metadata exposes schema-accurate forms and selectors.

**Tech Stack:** PHP 8, TokenHub service/controller architecture, MySQL, existing browser-native admin JavaScript, Docker PHP test runner.

---

### Task 1: Specify and test effective vector-configuration resolution

**Files:**

- Create: `tests/Services/RagVectorConfigResolutionTest.php`
- Modify: `app/Services/RagVectorConfigService.php`

- [ ] **Step 1: Write failing resolution tests**

Create fixture rows for an active text config assigned to a knowledge base, another assigned directly to a dataset, and a third global text config. Assert `textConfig()` returns the dataset config first, then the KB default after the dataset reference is cleared, then the global config after the KB reference is cleared. Add a mismatched-modality fixture and assert it is skipped.

```php
assert_same($datasetConfigPk, (int) $service->textConfig($dataset)['id']);
$db->execute('UPDATE rag_vector_datasets SET text_vector_config_id = NULL WHERE id = ?', [$datasetPk]);
assert_same($kbConfigPk, (int) $service->textConfig($dataset)['id']);
$db->execute('UPDATE rag_knowledge_bases SET default_text_vector_config_id = NULL WHERE id = ?', [$kbPk]);
assert_same($globalConfigPk, (int) $service->textConfig($dataset)['id']);
```

- [ ] **Step 2: Run the test and verify RED**

Run: `docker compose exec -T php php tests/Services/RagVectorConfigResolutionTest.php`

Expected: FAIL because current `RagVectorConfigService::textConfig()` does not load a knowledge-base default.

- [ ] **Step 3: Implement the minimal resolution helper**

Add `configForDataset(array $dataset, string $modality): ?array` and use it from `textConfig()`, `imageConfig()`, and `multimodalConfig()`. The helper must look up the owning `rag_knowledge_bases` row only after a valid dataset reference is absent, choose the correct `default_*_vector_config_id` column, and use a modality-constrained enabled-config query.

```php
$columns = [
    'text' => ['text_vector_config_id', 'default_text_vector_config_id'],
    'image' => ['image_vector_config_id', 'default_image_vector_config_id'],
    'multimodal' => ['multimodal_vector_config_id', 'default_multimodal_vector_config_id'],
];
```

- [ ] **Step 4: Run the focused test and verify GREEN**

Run: `docker compose exec -T php php tests/Services/RagVectorConfigResolutionTest.php`

Expected: exit 0 and the fixture cleanup removes every created row.

- [ ] **Step 5: Commit**

This workspace currently has no Git metadata, so record the changed files and skip this commit step.

### Task 2: Validate provider-backed vector configurations before CRUD persistence

**Files:**

- Create: `tests/Services/RagVectorConfigValidationTest.php`
- Modify: `app/Services/RagVectorConfigService.php`
- Modify: `app/Services/CrudService.php`

- [ ] **Step 1: Write failing validation tests**

Create active/inactive providers and models with `embedding` and `text_generation` capabilities. Assert that a valid provider/model pair normalizes `model_name` to `external_name`; mismatched provider/model, inactive provider, inactive model, non-embedding capability, a single supplied ID, nonpositive dimension, and malformed local fallback are rejected with the documented codes.

```php
$valid = $service->normalizeForWrite([
    'provider_id' => $providerId,
    'model_id' => $embeddingModelId,
    'model_name' => 'ignored',
    'dimension' => 3,
]);
assert_same('embedding-external', $valid['model_name']);
```

- [ ] **Step 2: Run the test and verify RED**

Run: `docker compose exec -T php php tests/Services/RagVectorConfigValidationTest.php`

Expected: FAIL because generic CRUD currently writes raw vector-config fields.

- [ ] **Step 3: Implement server-side normalization and delegation**

Add `normalizeForWrite(array $data, bool $creating, ?int $id): array` to `RagVectorConfigService`. Require provider/model IDs together; resolve the joined `models`/`providers` row; require active statuses and `embedding` capability; replace `model_name` from `external_name` or `name`; validate numeric dimension and allowed enum fields. For no-ID local configurations, parse `config_json` and require `allow_local_fallback=true`.

Call this service from `CrudService::normalizeForWrite()` when `$table === 'rag_vector_configs'`. Before writes to `rag_knowledge_bases` and `rag_vector_datasets`, validate each submitted vector-config ID against its matching modality.

- [ ] **Step 4: Run validation and relevant existing tests**

Run: `docker compose exec -T php php tests/Services/RagVectorConfigValidationTest.php`

Run: `docker compose exec -T php php tests/Services/RagProviderEmbeddingServiceTest.php`

Expected: both commands exit 0.

- [ ] **Step 5: Commit**

This workspace currently has no Git metadata, so record the changed files and skip this commit step.

### Task 3: Add the non-persisting administrator probe endpoint

**Files:**

- Create: `tests/Services/RagVectorConfigProbeTest.php`
- Modify: `app/Services/RagVectorConfigService.php`
- Modify: `app/Http/Controllers/RagController.php`
- Modify: `app/Bootstrap/Kernel.php`
- Modify: `tests/Support/RagApiSurfaceContractTest.php`

- [ ] **Step 1: Write failing service and route contracts**

Test that `probe()` normalizes a valid submitted configuration, calls the injected `RagEmbeddingProviderService` once with fixed text `TokenHub RAG embedding probe`, and returns the resolved model name plus actual dimension. Assert a mismatched returned dimension raises `RAG_EMBEDDING_DIMENSION_MISMATCH` and that no RAG vectors or configuration rows are inserted. Add a source-contract assertion for `POST /admin-api/rag-vector-configs/test` with admin and CSRF middleware.

```php
$result = $service->probe($payload);
assert_same('embedding-external', $result['model_name']);
assert_same(3, $result['dimension']);
assert_same(0, (int) ($db->first('SELECT COUNT(*) AS c FROM rag_vector_records')['c'] ?? 0));
```

- [ ] **Step 2: Run tests and verify RED**

Run: `docker compose exec -T php php tests/Services/RagVectorConfigProbeTest.php`

Run: `docker compose exec -T php php tests/Support/RagApiSurfaceContractTest.php`

Expected: probe test fails because `probe()` and route do not exist; API-surface test fails after its new assertion.

- [ ] **Step 3: Implement the endpoint**

Add `probe(array $data): array` to `RagVectorConfigService`, inject or construct `RagEmbeddingProviderService`, and return only `provider_id`, `model_id`, `model_name`, `modality`, and `dimension`. Add `RagController::testVectorConfig()` and register:

```php
$router->post('/admin-api/rag-vector-configs/test', [$rag, 'testVectorConfig'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
```

- [ ] **Step 4: Run probe contracts and GREEN checks**

Run: `docker compose exec -T php php tests/Services/RagVectorConfigProbeTest.php`

Run: `docker compose exec -T php php tests/Support/RagApiSurfaceContractTest.php`

Expected: both commands exit 0; the probe creates no persistent configuration or vector records.

- [ ] **Step 5: Commit**

This workspace currently has no Git metadata, so record the changed files and skip this commit step.

### Task 4: Complete the administrator configuration and binding forms

**Files:**

- Create: `tests/Frontend/AdminRagVectorConfigurationFrontendTest.php`
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`

- [ ] **Step 1: Write a failing frontend source-contract test**

Assert `rag-vector-configs` exposes schema-accurate columns and a `form` containing `provider_id`, `model_id`, `modality`, `dimension`, `distance_metric`, `vector_store`, `collection_name`, and `enabled`. Assert knowledge-base fields include the three `default_*_vector_config_id` selectors, datasets include the three per-dataset selectors, and the page source issues `POST /admin-api/rag-vector-configs/test`.

```php
assert_true(str_contains($vectorConfigResource, 'name: "model_id"'));
assert_true(str_contains($knowledgeBaseResource, 'default_text_vector_config_id'));
assert_true(str_contains($datasetResource, 'text_vector_config_id'));
assert_true(str_contains($table, '/admin-api/rag-vector-configs/test'));
```

- [ ] **Step 2: Run the frontend test and verify RED**

Run: `docker compose exec -T php php tests/Frontend/AdminRagVectorConfigurationFrontendTest.php`

Expected: FAIL because the resource still uses stale names such as `config_key` and has no form.

- [ ] **Step 3: Implement minimal UI metadata and probe action**

Replace the stale `rag-vector-configs` metadata with its actual schema and `form`. Use existing lookup helpers for Providers, Models, Knowledge Bases, and vector configurations. Add the three default selectors to the knowledge-base form and the three override selectors to the dataset form. Add a button inside the vector-config editor that posts the current unsaved fields to the probe endpoint and displays the result in the modal.

- [ ] **Step 4: Run frontend tests and JavaScript syntax validation**

Run: `docker compose exec -T php php tests/Frontend/AdminRagVectorConfigurationFrontendTest.php`

Run: `node --check public/admin/assets/js/resources.js`

Run: `node --check public/admin/assets/js/table.js`

Expected: all commands exit 0.

- [ ] **Step 5: Commit**

This workspace currently has no Git metadata, so record the changed files and skip this commit step.

### Task 5: Run the combined regression suite

**Files:**

- Verify only.

- [ ] **Step 1: Run focused configuration and RAG regression tests**

Run:

```powershell
docker compose exec -T php php tests/Services/RagVectorConfigResolutionTest.php
docker compose exec -T php php tests/Services/RagVectorConfigValidationTest.php
docker compose exec -T php php tests/Services/RagVectorConfigProbeTest.php
docker compose exec -T php php tests/Services/RagProviderJobRoutingTest.php
docker compose exec -T php php tests/Services/RagProviderEmbeddingServiceTest.php
docker compose exec -T php php tests/Frontend/AdminRagVectorConfigurationFrontendTest.php
docker compose exec -T php php tests/Support/RagApiSurfaceContractTest.php
```

Expected: every command exits 0.

- [ ] **Step 2: Run the full PHP suite**

Run: `docker compose exec -T php php bin/run-tests.php`

Expected: exit 0 and the terminal reports no failed tests.

- [ ] **Step 3: Inspect final changes**

Run: `git diff --check` when Git metadata is available; otherwise list the files changed in this implementation and report the absence of repository metadata.
