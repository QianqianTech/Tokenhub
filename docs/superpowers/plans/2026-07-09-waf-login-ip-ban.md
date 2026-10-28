# WAF Login IP Ban Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ban a visitor IP at system level after 5 failed web admin password attempts.

**Architecture:** Admin login failures are recorded in a dedicated system IP ban table. A global middleware rejects banned IPs before business route handling, and `WafService` includes active banned IPs in the generated Caddy WAF fragment so the edge layer can also block them.

**Tech Stack:** Native PHP, MySQL migrations, current TokenHub router/middleware, Caddy WAF fragment generation, Docker PHP verification.

---

### Task 1: Regression Tests

**Files:**
- Create: `tests/Services/LoginIpBanServiceTest.php`
- Modify: `tests/Services/WafServiceTest.php`

- [x] Write a failing service test that records 5 admin-login failures for one IP, asserts the IP is banned, and asserts reset clears it.
- [x] Extend the WAF service test to assert generated fragments contain a `system_login_password_fail_ban` `remote_ip` block for active banned IPs.
- [x] Run the focused tests and confirm they fail because implementation is missing.

### Task 2: Ban Storage And Service

**Files:**
- Create: `database/migrations/2026_07_09_000001_foundation_system_ip_bans.sql`
- Create: `app/Services/LoginIpBanService.php`

- [x] Add `system_ip_bans` with unique `client_ip`, failure count, reason, banned timestamp, and audit timestamps.
- [x] Implement `recordFailure()`, `reset()`, `isBanned()`, and `activeBannedIps()` with a threshold of 5.
- [x] Run the focused service test and confirm it passes.

### Task 3: System-Level Rejection And WAF Integration

**Files:**
- Create: `app/Middleware/SystemIpBanMiddleware.php`
- Modify: `app/Bootstrap/Kernel.php`
- Modify: `app/Services/AuthService.php`
- Modify: `app/Services/WafService.php`

- [x] Wire the middleware to every route via the router so banned IPs get HTTP 403 before business logic.
- [x] Update admin login so failed password attempts increment the counter and successful login resets that IP.
- [x] Update `WafService::publish()` and fragment generation so active banned IPs emit a Caddy `remote_ip` block.
- [x] Run focused WAF and service tests.

### Task 4: Verification

**Files:**
- Modify only if verification exposes a focused defect.

- [x] Run `docker compose exec -T php php bin/migrate.php`.
- [x] Run `docker compose exec -T php php tests/Services/LoginIpBanServiceTest.php`.
- [x] Run `docker compose exec -T php php tests/Services/WafServiceTest.php`.
- [x] Run `docker compose exec -T php php tests/Support/SystemIpBanMiddlewareTest.php`.
- [x] Run `docker compose exec -T php php tests/Support/RouterTest.php`.
- [x] Run `docker compose exec -T php php bin/run-tests.php`.
