# RAG Workspace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the RAG admin area from a collection of raw resource tables into a task-oriented workspace that supports real knowledge construction, retrieval verification, quality evaluation, and correction.

**Architecture:** Keep the existing PHP services and RAG data model as the source of truth. Add narrowly-scoped admin endpoints for trial retrieval and evaluation-case lifecycle actions, then render four task-oriented workspaces in the current vanilla ES-module admin UI. Keep raw tables only as drill-down details, and make all action availability derive from loaded task state.

**Tech Stack:** PHP 8.5, MySQL, existing Router/Controller/Service layer, vanilla ES modules, Docker PHP test runner.

---

### Task 1: Establish regression coverage for the RAG workspace

**Files:**
- Modify: `tests/Frontend/AdminRagWorkflowFrontendTest.php`
- Create: `tests/Services/RagAdminTrialServiceTest.php`
- Modify: `tests/Support/RagApiSurfaceContractTest.php`

- [ ] **Step 1: Add failing frontend assertions for the new four-workspace navigation and guarded actions.**

```php
foreach ([
    'rag-build',
    'rag-retrieval-lab',
    'rag-quality',
    'rag-operations',
    'renderRagOnboardingPage',
    'renderRagRetrievalLabPage',
    'renderRagImportTaskPage',
] as $token) {
    assert_true(str_contains($tableSource, $token), "RAG workspace should expose {$token}.");
}
assert_true(str_contains($tableSource, 'disabled: !state.uploadLoaded'), 'Upload actions must be disabled before task lookup.');
```

- [ ] **Step 2: Run the focused frontend test and confirm it fails because the workspace tokens do not exist.**

Run: `docker compose exec -T php php tests/Frontend/AdminRagWorkflowFrontendTest.php`

- [ ] **Step 3: Add failing service/route contracts for admin trial retrieval and evaluation-case lifecycle.**

```php
assert_true(str_contains($kernel, "post('/admin-api/rag/trial-retrieval'"), 'Admin trial retrieval route is required.');
assert_true(str_contains($kernel, "post('/admin-api/rag/eval-cases/import'"), 'Evaluation case import route is required.');
```

- [ ] **Step 4: Run the focused service and route tests and confirm the missing endpoints fail.**

Run: `docker compose exec -T php php tests/Services/RagAdminTrialServiceTest.php && docker compose exec -T php php tests/Support/RagApiSurfaceContractTest.php`

### Task 2: Add an admin-safe trial retrieval and evaluation-case lifecycle API

**Files:**
- Create: `app/Services/RagAdminTrialService.php`
- Modify: `app/Http/Controllers/RagController.php`
- Modify: `app/Bootstrap/Kernel.php`
- Modify: `app/Services/RagRetrievalEvaluationService.php`
- Test: `tests/Services/RagAdminTrialServiceTest.php`

- [ ] **Step 1: Implement `RagAdminTrialService::retrieve()` with a managed-dataset lookup, real `searchSourcesForDatasets()` call, and source-level ranking signals.**

```php
public function retrieve(array $admin, array $input): array
{
    $dataset = $this->managedDataset($admin, (string) ($input['dataset_id'] ?? ''));
    $query = trim((string) ($input['query'] ?? ''));
    if ($query === '') {
        abort_json('RAG_QUERY_REQUIRED', 'query is required', 422);
    }
    $sources = (new RagRetrievalService())->searchSourcesForDatasets([$dataset], $query, max(1, min(20, (int) ($input['top_k'] ?? 5))));
    return ['query' => $query, 'dataset' => $dataset['dataset_id'], 'sources' => $sources, 'fallback_used' => false];
}
```

- [ ] **Step 2: Add controller methods that require the existing admin session and CSRF middleware for all writes.**

```php
$router->post('/admin-api/rag/trial-retrieval', [$rag, 'adminTrialRetrieval'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
$router->post('/admin-api/rag/eval-cases/import', [$rag, 'importEvaluationCases'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
```

- [ ] **Step 3: Add evaluation-case create, status update, and CSV/JSON row import methods that validate KB/dataset scope and expected chunk IDs.**

```php
public function activateCase(array $admin, string $caseId): array
{
    $case = $this->managedCase($admin, $caseId);
    App::db()->execute('UPDATE rag_eval_cases SET status = "active" WHERE id = ?', [$case['id']]);
    return ['case_id' => $case['case_id'], 'status' => 'active'];
}
```

- [ ] **Step 4: Re-run the focused tests until they pass, then extend them with an out-of-scope dataset assertion.**

Run: `docker compose exec -T php php tests/Services/RagAdminTrialServiceTest.php && docker compose exec -T php php tests/Support/RagApiSurfaceContractTest.php`

### Task 3: Build the P0 task-oriented RAG home, onboarding, retrieval lab, and truthful status semantics

**Files:**
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/css/app.css`
- Test: `tests/Frontend/AdminRagWorkflowFrontendTest.php`

- [ ] **Step 1: Replace six RAG category entries with four task workspaces: `构建知识`, `检索试验台`, `质量评估`, and `运行维护`; retain raw records only inside workspace detail actions.**

```js
const RAG_WORKSPACES = [
  ["rag-build", "构建知识"],
  ["rag-retrieval-lab", "检索试验台"],
  ["rag-quality", "质量评估"],
  ["rag-operations", "运行维护"]
];
```

- [ ] **Step 2: Render onboarding when no active knowledge base/dataset exists, with only the next valid action enabled.**

```js
if (Number(summary.totals?.active_knowledge_bases || 0) === 0) {
  panel.replaceChildren(renderRagOnboardingPage({ step: "knowledge-base" }));
  return;
}
```

- [ ] **Step 3: Render the trial retrieval form with KB/dataset selection, query, Top K, source excerpt, retrieval strategy, lexical/vector/RRF/rerank signals, latency, and relevant/irrelevant actions.**

```js
const result = await api("/admin-api/rag/trial-retrieval", {
  method: "POST",
  body: JSON.stringify({ dataset_id: datasetId, query, top_k: Number(topK) })
});
```

- [ ] **Step 4: Replace mathematically empty health metrics with `暂无可计算任务`; label a completed all-failure evaluation as `运行完成，质量未达标`.**

```js
const successText = totalJobs === 0 ? "暂无可计算任务" : `${successRate.toFixed(1)}%`;
const qualityStatus = failedCases === caseCount && caseCount > 0 ? "运行完成，质量未达标" : "运行完成";
```

- [ ] **Step 5: Run the focused frontend test; verify the real admin page with an empty-data account and a seeded-data account.**

Run: `docker compose exec -T php php tests/Frontend/AdminRagWorkflowFrontendTest.php`

### Task 4: Merge import pages into a stateful import task and guard mutating actions

**Files:**
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/css/app.css`
- Test: `tests/Frontend/AdminRagWorkflowFrontendTest.php`

- [ ] **Step 1: Change post-upload navigation to the import task detail with the created upload ID carried in local in-memory page state.**

```js
state.uploadId = String(result.data?.upload_id || "");
await refreshRagImportTask(state);
```

- [ ] **Step 2: Render task progress, preview errors, review summary, and the next valid action in one panel.**

```js
const canReview = state.uploadLoaded && upload.review_status === "waiting_review";
const canVectorize = state.uploadLoaded && upload.review_status === "approved" && upload.vectorize_status !== "queued";
button("确认向量化", confirm, "primary", { disabled: !canVectorize });
```

- [ ] **Step 3: Remove the standalone manual Upload ID page from primary navigation and retain direct deep-link support for existing URLs.**

- [ ] **Step 4: Run the frontend regression test and manually verify upload actions are disabled before a task is loaded.**

### Task 5: Make quality evaluation and correction actionable

**Files:**
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/css/app.css`
- Test: `tests/Frontend/AdminRagWorkflowFrontendTest.php`

- [ ] **Step 1: Replace the raw evaluation-case table with a management page that creates a case from a trial result, activates drafts, imports rows, and opens failed cases.**

- [ ] **Step 2: Add evaluation run filters for dataset and selected cases; disable `运行评估` and show a direct `创建评估样本` action when active-case count is zero.**

- [ ] **Step 3: Turn each failed evaluation case into an actionable correction entry with source evidence and a link back to the trial retrieval form.**

- [ ] **Step 4: Run focused frontend and service tests, then execute an actual evaluation using seeded RAG data.**

### Task 6: Finish P2 presentation cleanup and real-browser verification

**Files:**
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/css/app.css`
- Test: `tests/Frontend/AdminRagWorkflowFrontendTest.php`

- [ ] **Step 1: Translate remaining RAG runtime labels, present technical IDs only in a copyable detail view, and use resource-specific state filters.**

- [ ] **Step 2: Apply consistent hierarchy for workspace health, task status, empty states, source evidence, and destructive actions.**

- [ ] **Step 3: Run frontend contracts, focused RAG service tests, and the full Docker PHP suite.**

Run: `docker compose exec -T php php tests/run.php`

