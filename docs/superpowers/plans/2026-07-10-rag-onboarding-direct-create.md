# RAG Onboarding Direct Create Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the RAG onboarding action open the first knowledge-base or dataset creation dialog on its first click.

**Architecture:** The onboarding card continues to navigate through the existing admin navigation event, but adds an `openCreate` navigation intent. The generic resource renderer consumes that one-shot intent after rendering the destination list, opens the existing create modal, and keeps the intent out of list API filters.

**Tech Stack:** Vanilla ES modules, existing TokenHub admin UI helpers, PHP frontend source assertions, Docker PHP test runner.

---

### Task 1: Lock the first-click workflow with a regression test

**Files:**
- Modify: `tests/Frontend/AdminRagWorkflowFrontendTest.php`
- Verify: `docker compose exec -T php php tests/Frontend/AdminRagWorkflowFrontendTest.php`

- [x] **Step 1: Add a failing source-level regression assertion**

Append these assertions after the current RAG onboarding assertions:

```php
assert_true(
    str_contains($tableSource, 'ragNavigate(nextPage, { openCreate: true })'),
    'RAG onboarding action should request the destination create dialog on its first click.'
);
assert_true(
    str_contains($tableSource, 'const { openCreate = false, ...filterValues } = initialFilters;'),
    'Generic resource pages should keep one-shot create navigation intent out of list filters.'
);
```

- [x] **Step 2: Run the focused test and confirm it fails**

Run:

```sh
docker compose exec -T php php tests/Frontend/AdminRagWorkflowFrontendTest.php
```

Expected: failure stating that the RAG onboarding action does not request its destination creation dialog.

### Task 2: Consume the navigation intent with the existing create modal

**Files:**
- Modify: `public/admin/assets/js/table.js:811-840`
- Modify: `public/admin/assets/js/table.js:3175-3192`
- Test: `tests/Frontend/AdminRagWorkflowFrontendTest.php`

- [x] **Step 1: Forward the explicit first-click intent from the onboarding card**

Change the onboarding action to:

```js
button(nextLabel, () => ragNavigate(nextPage, { openCreate: true }), "primary")
```

- [x] **Step 2: Separate the UI-only intent from list filters and reuse the modal callback**

At the start of `renderGenericResourcePage`, replace the state setup with:

```js
const { openCreate = false, ...filterValues } = initialFilters;
const state = { filters: { limit: 50, ...filterValues } };
const refreshRows = async () => renderRows(tablePanel, resourceKey, config, state);
```

Use `refreshRows` for both the `新增` button and the one-shot direct-create action after the initial `await renderRows(...)`:

```js
if (config.form) {
  actions.append(button("新增", () => showCreateModal(resourceKey, config, refreshRows)));
}
// existing toolbar/content rendering
await refreshRows();
if (openCreate && config.form) {
  await showCreateModal(resourceKey, config, refreshRows);
}
```

- [x] **Step 3: Run the focused frontend regression test**

Run:

```sh
docker compose exec -T php php tests/Frontend/AdminRagWorkflowFrontendTest.php
```

Expected: pass.

- [x] **Step 4: Check JavaScript syntax and the adjacent navigation test**

Run:

```sh
node --check public/admin/assets/js/table.js
docker compose exec -T php php tests/Frontend/AdminDomainNavigationFrontendTest.php
```

Expected: both commands exit successfully.
