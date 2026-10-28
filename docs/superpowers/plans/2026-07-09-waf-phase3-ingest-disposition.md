# WAF Phase 3 Ingest Disposition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the WAF runtime loop by letting Caddy security log imports immediately apply configured automatic disposition and report the result.

**Architecture:** Keep `WafService` as the WAF orchestration boundary. Extend security-log ingestion with an options array, reusable threshold resolution from `alert_thresholds`, and optional auto-disposition after import. Keep `WorkerService` as a thin dispatcher that forwards WAF import options.

**Tech Stack:** Native PHP services, MySQL-backed settings/events/bans, existing Docker PHP verification.

---

### Task 1: RED Tests For Import-To-Disposition

**Files:**
- Modify: `tests/Services/WafServiceTest.php`
- Modify: `tests/Services/WorkerServiceTest.php`
- Modify: `tests/Services/AlertServiceTest.php`

- [x] Add a service test where three imported Caddy security events from one IP use `alert_thresholds` and become an auto-banned IP.
- [x] Add a worker test where `waf.import` forwards file and options so the same automatic disposition can run from the job path.
- [x] Add an alert test where WAF spike evaluation uses configured alert thresholds.
- [x] Run focused tests and confirm the new expectations fail before implementation.

### Task 2: WAF Service Import Options

**Files:**
- Modify: `app/Services/WafService.php`
- Modify: `app/Services/AlertService.php`

- [x] Change `ingestSecurityLog()` to accept an options array.
- [x] Support an `offset_file` option for focused tests and operational flexibility.
- [x] Resolve default auto-disposition threshold/window from `alert_thresholds`.
- [x] Resolve WAF spike alert threshold/window from `alert_thresholds`.
- [x] Return `auto_disposition`, `threshold`, `window_minutes`, and `banned_count` when disposition runs.
- [x] Preserve existing import behavior when auto-disposition is explicitly disabled.

### Task 3: Worker Dispatch

**Files:**
- Modify: `app/Services/WorkerService.php`

- [x] Pass `job.options` into `WafService::ingestSecurityLog()` for `waf.import`.
- [x] Keep `waf.auto_disposition` as a direct standalone job path.

### Task 4: Verification

**Files:**
- Modify only if verification exposes a phase-three defect.

- [x] Run `docker compose exec -T php php tests/Services/WafServiceTest.php`.
- [x] Run `docker compose exec -T php php tests/Services/WorkerServiceTest.php`.
- [x] Run `docker compose exec -T php php tests/Support/AdminSecurityContractTest.php`.
- [x] Run `docker compose exec -T php php -l app/Services/WafService.php`.
- [x] Run `docker compose exec -T php php -l app/Services/WorkerService.php`.
- [x] Run `docker compose exec -T php php bin/run-tests.php` and report unrelated existing failures separately.

**Verification note:** Phase-three focused tests pass, PHP syntax checks pass, and `docker compose exec -T php php bin/run-tests.php` returned exit 0 with the listed suite passing.
