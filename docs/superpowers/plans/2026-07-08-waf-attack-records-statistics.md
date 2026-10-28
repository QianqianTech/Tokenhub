# WAF Attack Records Statistics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a WAF-focused attack records and statistics surface using existing `waf_events` data.

**Architecture:** Keep `waf_events` as the source of truth. Add dedicated `WafService` methods and admin API routes for records, summary, trends, rule rankings, IP rankings, and path rankings, then render a focused Admin page while preserving the generic table.

**Tech Stack:** PHP services/controllers/routes, existing MySQL schema, vanilla Admin JavaScript, existing PHP test runner.

---

### Task 1: Backend WAF Query API

**Files:**
- Modify: `app/Services/WafService.php`
- Modify: `app/Http/Controllers/AdminController.php`
- Modify: `app/Bootstrap/Kernel.php`
- Test: `tests/Services/WafServiceTest.php`
- Test: `tests/Support/AdminSecurityContractTest.php`

- [ ] Write failing tests for WAF event records and statistics.
- [ ] Run focused tests and confirm they fail because methods/routes are missing.
- [ ] Implement `events()` and `statistics()` in `WafService`.
- [ ] Add `GET /admin-api/waf/events` and `GET /admin-api/waf/statistics`.
- [ ] Re-run focused tests and confirm they pass.

### Task 2: Admin WAF Page

**Files:**
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/js/resources.js`

- [ ] Route `waf-events` to a dedicated renderer.
- [ ] Add filters for time range, rule code, client IP, action, and path.
- [ ] Render summary cards, trend table, rankings, and attack records.
- [ ] Keep the page read-only and use existing `api`, `el`, `button`, `field`, `formatValue`, and `statusMessage` helpers.

### Task 3: Verification

**Files:**
- Test: `tests/Services/WafServiceTest.php`
- Test: `tests/Support/AdminSecurityContractTest.php`
- Test: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] Run focused backend and frontend contract tests.
- [ ] Run the full local PHP test runner if focused tests are stable.
- [ ] Report exact verification commands and any residual limitations.
