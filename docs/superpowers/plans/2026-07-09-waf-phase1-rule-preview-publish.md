# WAF Phase 1 Rule Preview And Publish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add first-stage WAF productization: rule templates, matcher validation, Caddy fragment preview, dry-run publishing, and high-risk publish confirmation.

**Architecture:** Keep Caddy fragment generation inside `WafService` and add a small preview/validation layer around the existing `waf_rules` table. Reuse existing Admin routes and static JavaScript patterns; do not alter Docker, Caddy, or `.env` configuration.

**Tech Stack:** Native PHP services/controllers/routes, current MySQL-backed WAF tables, static ES module Admin frontend, Docker PHP verification.

---

### Task 1: Backend RED Tests

**Files:**
- Modify: `tests/Services/WafServiceTest.php`
- Modify: `tests/Support/AdminSecurityContractTest.php`

- [ ] Add tests proving `WafService::templates()` returns common WAF rule templates.
- [ ] Add tests proving `WafService::preview()` validates matcher schema, returns generated fragment, and classifies high-risk rules.
- [ ] Add contract assertions for `/admin-api/waf/templates` and `/admin-api/waf/preview`.
- [ ] Run `docker compose exec -T php php tests/Services/WafServiceTest.php` and confirm failure because the new methods do not exist.
- [ ] Run `docker compose exec -T php php tests/Support/AdminSecurityContractTest.php` and confirm failure because the new routes do not exist.

### Task 2: Backend GREEN Implementation

**Files:**
- Modify: `app/Services/WafService.php`
- Modify: `app/Http/Controllers/AdminController.php`
- Modify: `app/Bootstrap/Kernel.php`

- [ ] Add `templates()` returning built-in templates for high-risk paths, sensitive query keys, scanner user agents, method restrictions, body size, admin CIDR, and API CIDR.
- [ ] Add `preview(array $payload = [])` to validate either provided rules or active DB rules, generate the Caddy fragment without installing it, and return `fragment`, `rules`, `findings`, `high_risk`, and `status`.
- [ ] Add matcher validation for supported types: `ip`, `path`, `method`, `query`, `header`, `body_size`, and `security_headers`.
- [ ] Add publish dry-run support with `dry_run=true`.
- [ ] Add high-risk publish guard: publish returns `requires_confirmation` unless `confirm_high_risk=true`.
- [ ] Add `GET /admin-api/waf/templates` and `POST /admin-api/waf/preview`.
- [ ] Rerun focused backend tests and confirm they pass.

### Task 3: Frontend RED Tests

**Files:**
- Modify: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] Add assertions that the Admin frontend exposes WAF preview/template actions and includes a dedicated WAF rules renderer.
- [ ] Run `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php` and confirm failure because the UI hooks are missing.

### Task 4: Frontend GREEN Implementation

**Files:**
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`

- [ ] Add WAF rule action metadata for template loading and fragment preview.
- [ ] Add a dedicated WAF rules page that keeps the existing CRUD table and adds preview, dry-run publish, confirmed publish, and built-in template helpers.
- [ ] Keep all rendered text through existing DOM helpers and avoid `innerHTML`, `localStorage`, `sessionStorage`, and console logging.
- [ ] Rerun focused frontend tests and confirm they pass.

### Task 5: Verification

**Files:**
- Modify only if verification exposes a focused defect.

- [ ] Run `docker compose exec -T php php tests/Services/WafServiceTest.php`.
- [ ] Run `docker compose exec -T php php tests/Support/AdminSecurityContractTest.php`.
- [ ] Run `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`.
- [ ] Run `docker compose exec -T php php bin/run-tests.php`.
