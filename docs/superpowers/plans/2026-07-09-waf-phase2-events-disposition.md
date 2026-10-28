# WAF Phase 2 Events And Disposition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add WAF event review and automatic disposition so repeated WAF hits can be reviewed, marked as false positive, and escalated into system IP bans.

**Architecture:** Extend `waf_events` with review/disposition fields and keep `system_ip_bans` as the single edge-block source. Add `WafService` methods for event handling and auto-disposition, wire Admin API routes, and expose actions on the WAF attack records page.

**Tech Stack:** Native PHP services/controllers/routes, MySQL migrations, current Admin ES modules, Docker PHP verification.

---

### Task 1: Backend RED Tests

**Files:**
- Modify: `tests/Services/WafServiceTest.php`
- Modify: `tests/Support/AdminSecurityContractTest.php`

- [x] Add assertions that WAF events can be marked reviewed and false positive.
- [x] Add assertions that repeated blocked WAF events auto-ban a source IP through `system_ip_bans`.
- [x] Add assertions that Admin exposes WAF event handling and auto-disposition routes.
- [x] Run the focused tests and confirm they fail because methods/routes are missing.

### Task 2: Schema And Backend Implementation

**Files:**
- Create: `database/migrations/2026_07_09_000009_foundation_waf_event_disposition.sql`
- Modify: `app/Services/WafService.php`
- Modify: `app/Http/Controllers/AdminController.php`
- Modify: `app/Bootstrap/Kernel.php`
- Modify: `app/Services/WorkerService.php`

- [x] Add nullable event review columns: `disposition_status`, `false_positive`, `handled_by`, `handled_at`, `disposition_note`, and `risk_score`.
- [x] Include disposition fields in WAF event list output.
- [x] Add `handleEvent()` for `review`, `false_positive`, `ban_ip`, and `unban_ip`.
- [x] Add `autoDisposition()` to ban IPs with repeated block events in a window.
- [x] Add Admin API routes for event handling and auto-disposition.
- [x] Add `waf.auto_disposition` worker job support.

### Task 3: Frontend Contract And UI

**Files:**
- Modify: `tests/Frontend/AdminFrontendSecurityTest.php`
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`

- [x] Add frontend contract assertions for WAF event disposition actions.
- [x] Add WAF event page buttons for auto-disposition and per-event review/false-positive/IP ban actions.
- [x] Keep rendering through DOM helpers and avoid sensitive storage/logging.

### Task 4: Verification

**Files:**
- Modify only if focused verification exposes a defect.

- [x] Run `docker compose exec -T php php bin/migrate.php`.
- [x] Run `docker compose exec -T php php tests/Services/WafServiceTest.php`.
- [x] Run `docker compose exec -T php php tests/Support/AdminSecurityContractTest.php`.
- [x] Run `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`.
- [x] Run `node --check public/admin/assets/js/table.js`.
- [x] Run `node --check public/admin/assets/js/resources.js`.
- [x] Run `docker compose exec -T php php bin/run-tests.php` and report any unrelated existing failures separately.

**Verification note:** WAF focused backend tests and JS syntax checks pass. The project-wide suite was re-run after phase three and `docker compose exec -T php php bin/run-tests.php` returned exit 0.
