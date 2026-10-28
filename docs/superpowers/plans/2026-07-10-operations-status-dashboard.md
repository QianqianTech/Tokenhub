# Operations Status Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the unreadable raw operations-status payload with a concise operational dashboard without changing its API.

**Architecture:** Keep `AdminOperationsService::status()` and `/admin-api/operations/status` unchanged. Replace the generic detail renderer in the admin frontend with a page-specific composition: service health cards, queue metric cards, and a structured recent-worker-log list. Add CSS hooks only for this page and protect the composition with a frontend contract test.

**Tech Stack:** Static ES modules, existing `dom.el()` helpers, CSS custom properties, PHP contract tests in Docker.

---

### Task 1: Lock the operations-status presentation contract

**Files:**
- Create: `tests/Frontend/AdminOperationsFrontendTest.php`
- Modify: `public/admin/assets/js/table.js:1307-1311`
- Modify: `public/admin/assets/css/app.css`

- [x] **Step 1: Write the failing test**

```php
$operationsRenderer = substr($tableSource, strpos($tableSource, 'async function renderOperationsStatusPage'), strpos($tableSource, 'async function renderWafRulesPage') - strpos($tableSource, 'async function renderOperationsStatusPage'));
assert_true(!str_contains($operationsRenderer, 'renderDataDetails(result.data || {})'), 'Operations status must not render raw nested response data.');
foreach (['operations-status-overview', 'operations-health-grid', 'operations-queue-grid', 'operations-worker-log-list'] as $hook) {
    assert_true(str_contains($operationsRenderer, $hook), 'Operations status should expose ' . $hook . '.');
}
```

- [x] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T php php tests/Frontend/AdminOperationsFrontendTest.php`

Expected: FAIL because the current renderer delegates the whole response to `renderDataDetails()`.

- [x] **Step 3: Implement the focused renderer and styles**

Replace the raw renderer with semantic cards and log rows. Render the existing `health`, `queues`, and `recent_worker_logs` payload fields only; do not change API calls or service classes.

- [x] **Step 4: Run the focused test and JavaScript syntax verification**

Run: `node --check public/admin/assets/js/table.js; docker compose exec -T php php tests/Frontend/AdminOperationsFrontendTest.php`

Expected: syntax check exits 0 and the contract test passes.

- [x] **Step 5: Run adjacent frontend and service contract tests**

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php; docker compose exec -T php php tests/Services/AdminWebCapabilitiesTest.php`

Expected: both tests pass because settings and operations API contracts remain unchanged.
