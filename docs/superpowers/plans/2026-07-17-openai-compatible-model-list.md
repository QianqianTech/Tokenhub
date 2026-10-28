# OpenAI Compatible Model List Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `GET /v1/models` readable by OpenAI-compatible clients that expect a top-level `data` array with model ids.

**Architecture:** Keep the existing API-key authorization query and filtering in `ExternalModelService`. Change only the list response shape so it returns raw JSON with `data` as a list and each model `id` mapped from TokenHub `models.name`.

**Tech Stack:** PHP service/controller code, existing lightweight PHP test runner, JSON OpenAPI documentation.

---

### Task 1: Contract Test

**Files:**
- Modify: `tests/Services/ExternalApiGapServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add assertions after `$modelService = new ExternalModelService();`:

```php
$authorizedModels = $modelService->authorized($apiKey);
$firstModel = $authorizedModels['data'][0] ?? null;
assert_true(is_array($authorizedModels['data'] ?? null), 'Authorized model list should expose an OpenAI-compatible data array.');
assert_same('gap-chat-' . $suffix, $firstModel['id'] ?? null, 'OpenAI-compatible model id should be the TokenHub model name.');
assert_true(!isset($authorizedModels['models']), 'Authorized model list should not require clients to read data.models.');
```

- [ ] **Step 2: Run the focused service test and verify it fails**

Run: `php tests/Services/ExternalApiGapServiceTest.php`

Expected before implementation: failure because `authorized()` currently returns `models`, not `data`.

### Task 2: Minimal Implementation

**Files:**
- Modify: `app/Services/ExternalModelService.php`
- Modify: `app/Http/Controllers/ExternalController.php`

- [ ] **Step 1: Change the service list shape**

Return:

```php
return [
    'data' => array_map(fn (array $row): array => [
        'id' => (string) $row['name'],
    ], $rows),
];
```

- [ ] **Step 2: Return the list without the TokenHub envelope**

Change `ExternalController::models()` to `return Response::json((new ExternalModelService())->authorized($this->apiKey()));`.

- [ ] **Step 3: Run focused tests**

Run: `php tests/Services/ExternalApiGapServiceTest.php`

Expected: pass.

### Task 3: Documentation Contract

**Files:**
- Modify: `public/openapi.json`
- Modify: `docs/external-api-complete.md`
- Modify: `docs/external-api-sync.md`

- [ ] **Step 1: Document `GET /v1/models` as OpenAI-compatible**

Document that the response has top-level `data` and each `id` is the TokenHub model name.

- [ ] **Step 2: Run support contract tests**

Run: `php tests/Support/ExternalApiSurfaceContractTest.php`

Expected: pass.

### Task 4: Final Verification

- [ ] **Step 1: Run targeted tests**

Run:

```powershell
php tests/Services/ExternalApiGapServiceTest.php
php tests/Support/ExternalApiSurfaceContractTest.php
php tests/Support/ExternalApiExamplesContractTest.php
```

Expected: all exit 0.
