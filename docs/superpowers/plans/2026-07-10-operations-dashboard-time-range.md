# Operations Dashboard Time Range Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let administrators select a reporting period and see a status-first operations dashboard whose six data areas use that same period.

**Architecture:** `public/admin/assets/js/dashboard.js` will own period presets, custom-date validation, and the shared `from`/`to` request parameters. It will render an operations status strip between the summary cards and analytical panels. `app.css` will add only dashboard-scoped responsive layout rules; the backend API contract remains unchanged.

**Tech Stack:** Vanilla ES modules, existing TokenHub DOM helpers, CSS, PHP source-contract frontend tests.

---

### Task 1: Lock the intended dashboard contract

**Files:**
- Modify: `tests/Frontend/AdminDashboardChartsFrontendTest.php`
- Test: `tests/Frontend/AdminDashboardChartsFrontendTest.php`

- [ ] **Step 1: Write the failing source-contract assertions**

```php
assert_true(str_contains($dashboardSource, 'select("period"'), 'Dashboard should expose a reporting-period selector.');
assert_true(str_contains($dashboardSource, 'datetime-local'), 'Dashboard should support custom start and end times.');
assert_true(str_contains($dashboardSource, 'periodRange('), 'Dashboard should derive shared from/to parameters from the selected period.');
assert_true(str_contains($dashboardSource, 'operations-status-strip'), 'Dashboard should render a status-first operations strip.');
assert_true(str_contains($adminCss, '.operations-status-strip'), 'Dashboard CSS should style the operations status strip.');
```

- [ ] **Step 2: Run the focused test to verify RED**

Run: `docker compose exec -T php php tests/Frontend/AdminDashboardChartsFrontendTest.php`

Expected: failure explaining that the period selector or status strip is missing.

### Task 2: Add shared reporting-period parameters and status-first rendering

**Files:**
- Modify: `public/admin/assets/js/dashboard.js`
- Test: `tests/Frontend/AdminDashboardChartsFrontendTest.php`

- [ ] **Step 1: Implement the period helpers and controls**

Add a `periodRange(period, customFrom, customTo)` helper that returns UTC SQL timestamps and a human label. Add `period` (`today`, `7d`, `30d`, `custom`) plus two `datetime-local` controls to the existing filter form. On submit, reject custom ranges with missing values or `from >= to` through `statusMessage`.

```js
const range = periodRange(period.value, customFrom.value, customTo.value);
if (!range) {
  host.append(statusMessage("请选择有效的自定义时间区间", "error"));
  return;
}
const params = { bucket: bucket.value, dimension: dimension.value, sort: sort.value, from: range.from, to: range.to };
```

- [ ] **Step 2: Render the status strip from existing responses**

After the six responses resolve, prepend the summary grid and `operationsStatusStrip(realtime.data.today, performance.data, security.data, range.label)`. The strip must identify its period in text, show error-request count, latest P99 latency, and total security events, while retaining all chart panels and navigation callbacks.

```js
host.append(metricGrid(realtime.data.today || {}, range.label));
host.append(operationsStatusStrip(realtime.data.today || {}, performance.data || {}, security.data || {}, range.label));
```

- [ ] **Step 3: Run the focused test to verify GREEN**

Run: `docker compose exec -T php php tests/Frontend/AdminDashboardChartsFrontendTest.php`

Expected: `AdminDashboardChartsFrontendTest.php` completes without assertion output.

### Task 3: Make the operations hierarchy responsive and visually distinct

**Files:**
- Modify: `public/admin/assets/css/app.css`
- Test: `tests/Frontend/AdminDashboardChartsFrontendTest.php`

- [ ] **Step 1: Add dashboard-scoped layout rules**

Add `.operations-status-strip` and `.operations-status-item` rules beneath the existing `.dashboard-screen-page` block. Use a responsive `repeat(auto-fit, minmax(min(100%, 180px), 1fr))` grid; make the attention state readable with text plus the existing dashboard colors; stack filter fields and status items on narrow screens.

```css
.dashboard-screen-page .operations-status-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr));
  gap: 12px;
}
```

- [ ] **Step 2: Run the focused test to verify layout contract**

Run: `docker compose exec -T php php tests/Frontend/AdminDashboardChartsFrontendTest.php`

Expected: `AdminDashboardChartsFrontendTest.php` completes without assertion output.

### Task 4: Verify the complete change

**Files:**
- Verify: `public/admin/assets/js/dashboard.js`
- Verify: `public/admin/assets/css/app.css`
- Verify: `tests/Frontend/AdminDashboardChartsFrontendTest.php`

- [ ] **Step 1: Run focused frontend regression tests**

Run: `docker compose exec -T php php tests/Frontend/AdminDashboardChartsFrontendTest.php && docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: both scripts exit with code 0.

- [ ] **Step 2: Inspect the final diff**

Run: `git diff --check && git diff -- public/admin/assets/js/dashboard.js public/admin/assets/css/app.css tests/Frontend/AdminDashboardChartsFrontendTest.php`

Expected: no whitespace errors; all changes remain dashboard-scoped.

- [ ] **Step 3: Perform browser verification when an admin session is available**

Navigate to `http://localhost:8080/admin/#/operations/dashboard`, select `近 7 天`, refresh, then select a valid custom period and refresh. Verify the status strip and all panels use the selected label and that the page has no horizontal overflow.

