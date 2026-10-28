# Demo Data Seed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build and run a repeatable demo seed command that writes realistic data into the TokenHub database for the Web admin console.

**Architecture:** Add a standalone `scripts/seed-demo.php` that uses the existing bootstrap container and `TokenHub\Support\Database`. The script performs deterministic upserts by stable demo identifiers and writes fresh relative timestamps so dashboards show current activity after every run.

**Tech Stack:** PHP 8.2, PDO through `TokenHub\Support\Database`, MySQL, existing test runner `php bin/run-tests.php`.

---

### Task 1: Seed Script Contract Test

**Files:**
- Create: `tests/Console/DemoSeedScriptTest.php`
- Create later: `scripts/seed-demo.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Console/DemoSeedScriptTest.php` with assertions that the seed script exists, requires the app container, has no destructive deletes, and references all core admin source tables.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/run-tests.php`

Expected before implementation: FAIL because `scripts/seed-demo.php` does not exist.

- [ ] **Step 3: Implement the seed script**

Create `scripts/seed-demo.php`. It must include helpers for JSON encoding, timestamps, deterministic hashes, upserts, and summary output.

- [ ] **Step 4: Run tests to verify the contract passes**

Run: `php bin/run-tests.php`

Expected after implementation: `DemoSeedScriptTest.php` passes.

### Task 2: Database Seed Execution

**Files:**
- Modify: `scripts/seed-demo.php`

- [ ] **Step 1: Ensure migrations have run**

Run: `php bin/migrate.php`

Expected: migration command exits successfully or reports zero pending migrations.

- [ ] **Step 2: Execute the seed**

Run: `php scripts/seed-demo.php`

Expected: script prints counts for organization, model, access, usage, dashboard, security, operations, and screen-share data.

- [ ] **Step 3: Verify data with database queries**

Run focused queries through the app database connection for these tables: `departments`, `users`, `providers`, `models`, `model_prices`, `api_keys`, `quota_policies`, `usage_records`, `usage_aggregates_hour`, `usage_aggregates_day`, `multimodal_tasks`, `media_assets`, `usage_contents`, `waf_events`, `desensitization_events`, `audit_logs`, `alert_events`, and `dashboard_share_links`.

Expected: each table has at least one seeded demo row.

### Task 3: Final Verification

**Files:**
- Read: `docs/superpowers/specs/2026-07-08-demo-data-design.md`
- Read: `docs/superpowers/plans/2026-07-08-demo-data-seed.md`

- [ ] **Step 1: Re-run focused tests**

Run: `php bin/run-tests.php`

Expected: no failures related to the demo seed script.

- [ ] **Step 2: Re-run seed for idempotency**

Run: `php scripts/seed-demo.php`

Expected: script exits successfully again without duplicate-key errors.

- [ ] **Step 3: Re-query dashboard source tables**

Confirm the dashboard source tables still contain seeded rows after the second run.
