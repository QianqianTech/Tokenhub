# RAG P2 Production Safety and Quality Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bound public retrieval work and complete reliable evaluation/quality operations without schema or environment changes.

**Architecture:** Normalize `top_k` at the shared retrieval seam, keep optional fallbacks available when observability fails, and expose bounded quality samples honestly. Extend the existing evaluation service, CLI conventions, and admin RAG controller rather than adding parallel paths.

**Tech Stack:** PHP 8.2, MySQL JSON fields, existing TokenHub test harness, Docker PHP verification.

---

### Task 1: Retrieval request safety and fallback contract

**Files:**
- Modify: `app/Services/RagRetrievalService.php`
- Modify: `tests/Support/RagRequiredNoContextContractTest.php`
- Modify: `tests/Services/RagRetrievalQualityServiceTest.php`

- [ ] **Step 1: Write failing top-K and log-failure tests**

Add a real retrieval assertion that `top_k=PHP_INT_MAX` yields at most 20 returned sources and records `top_k=20`. Add a controllable `record()` failure seam (constructor callback or protected override) and assert optional retrieval returns `{fallback_used:true,retrieval_id:null,sources:[]}` without throwing.

- [ ] **Step 2: Verify RED**

Run:

```powershell
docker compose exec -T php php tests/Support/RagRequiredNoContextContractTest.php
```

Expected: the oversized request is not bounded or the controlled record failure throws.

- [ ] **Step 3: Implement normalized top-K and fallback behavior**

Use:

```php
$topK = max(1, min(20, (int) ($rag['top_k'] ?? 8)));
```

Use the normalized value for all candidate, response, and log paths. On optional fallback-record failure, return the normal fallback shape with `retrieval_id => null`; required no-context remains the existing 424 response.

- [ ] **Step 4: Verify GREEN**

Run the contract test plus `tests/Services/RagChineseRetrievalServiceTest.php` and `tests/Services/RagHybridRetrievalServiceTest.php`.

### Task 2: Evaluation gate and CLI

**Files:**
- Modify: `app/Services/RagRetrievalEvaluationService.php`
- Create: `bin/rag-eval-gate.php`
- Create: `tests/Services/RagRetrievalQualityGateTest.php`
- Create: `tests/Console/RagEvaluationGateCliTest.php`

- [ ] **Step 1: Write failing gate tests**

Seed passing and failing evaluation cases. Assert `run()` returns/persists `quality_gate_passed`, `quality_gate_thresholds`, and violations with metric/actual/required. Add a CLI source/child-process contract for JSON output and exit 0/pass, 2/fail, 1/runtime error.

- [ ] **Step 2: Verify RED**

Run the two new tests; expected failure because gate fields and CLI do not exist.

- [ ] **Step 3: Implement gate calculation and CLI**

Add `qualityGate(array $metrics,array $options): array` with default Recall@5=95 and MRR=.80. Merge its result into run metadata and return fields. The CLI supports repeatable `--dataset`, repeatable `--case`, `--min-recall-at-5`, and `--min-mrr`; prints one `JSON_UNESCAPED_UNICODE` JSON object and uses required exit codes.

- [ ] **Step 4: Verify GREEN**

Run new tests plus `RagRetrievalEvaluationServiceTest.php` and `RagEvaluationHybridRetrievalTest.php`.

### Task 3: Authenticated retrieval-quality API and final verification

**Files:**
- Modify: `app/Http/Controllers/RagController.php`
- Modify: `app/Bootstrap/Kernel.php`
- Modify: `tests/Support/RagApiSurfaceContractTest.php`
- Create: `tests/Services/RagRetrievalQualityApiTest.php`

- [ ] **Step 1: Write failing API contracts**

Assert the route `/admin-api/rag/retrieval-quality`, controller method `retrievalQuality`, and `RagRetrievalQualityService` exist. Verify anonymous access is rejected and authenticated admin access returns `metrics_scope`, `capacity`, `sampled`, `truncated`, and problem queries with retrieval/dataset IDs.

- [ ] **Step 2: Verify RED**

Run the route and API tests; expected failure because the route is absent.

- [ ] **Step 3: Implement read-only endpoint**

Register authenticated GET route. The controller calls:

```php
return Response::ok((new RagRetrievalQualityService())->summary(
    (int) ($request->query['window_hours'] ?? 24),
    (float) ($request->query['low_confidence'] ?? 0.35),
));
```

- [ ] **Step 4: Final verification**

Run all focused RAG tests, PHP syntax checks for changed production files and CLI, then:

```powershell
docker compose exec -T php php tests/run.php
docker compose exec -T php php bin/migrate.php
docker compose ps php mysql redis qdrant rag-worker
```

Expected: all tests pass, migrations apply 0, services are healthy, and no environment files changed.
