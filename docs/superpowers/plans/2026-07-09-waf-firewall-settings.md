# WAF Firewall Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dedicated admin WAF firewall settings feature that manages common firewall controls without exposing raw Caddyfile editing.

**Architecture:** Add a typed `firewall_settings` section to `SystemSettingsService`, reuse the existing settings-center API for persistence, and have `WafService` consume normalized firewall settings when generating the Caddy WAF fragment. The existing WAF rules CRUD stays available for advanced rules.

**Tech Stack:** Native PHP services, current MySQL-backed `system_settings`, Caddy WAF fragment generation, static admin JavaScript, Docker PHP tests.

---

### Task 1: Tests First

**Files:**
- Modify: `tests/Services/SystemSettingsServiceTest.php`
- Modify: `tests/Services/WafServiceTest.php`
- Modify: `tests/Services/LoginIpBanServiceTest.php`
- Modify: `tests/Frontend/AdminSettingsCenterFrontendTest.php`
- Modify: `tests/Frontend/AdminFrontendSecurityTest.php`

- [x] Add assertions that `firewall_settings` defaults and normalization include enablement, auto-publish, admin/API CIDRs, blocked IPs, allowed methods, blocked paths, sensitive query keys, body limit, and login failure threshold.
- [x] Add assertions that `WafService` emits admin/API CIDR, blocked IP, method, path, query, and body-size firewall controls from saved settings.
- [x] Add assertions that `LoginIpBanService` reads the configured login failure threshold.
- [x] Add frontend contract assertions for a `settings-firewall` sidebar/page with fields and save API.
- [x] Run focused tests and confirm expected failures.

### Task 2: Backend Settings And WAF Integration

**Files:**
- Modify: `app/Services/SystemSettingsService.php`
- Modify: `app/Services/WafService.php`
- Modify: `app/Services/LoginIpBanService.php`

- [x] Add `firewall_settings` defaults and normalization.
- [x] Make login failure threshold read from `firewall_settings.login_failure_threshold`, defaulting to 5.
- [x] Generate WAF fragment controls from `firewall_settings` while preserving `vars waf_rule` and `vars waf_action`.
- [x] Keep existing custom `waf_rules` publishing behavior unchanged.

### Task 3: Admin UI Entry

**Files:**
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`

- [x] Add `settings-firewall` to super-admin access and the system settings navigation section.
- [x] Add a typed settings page definition pointing to `firewall_settings`.
- [x] Add fields for all firewall controls and keep the generic settings save flow.

### Task 4: Verification

**Files:**
- Modify only if verification exposes a focused defect.

- [x] Run `docker compose exec -T php php tests/Services/SystemSettingsServiceTest.php`.
- [x] Run `docker compose exec -T php php tests/Services/WafServiceTest.php`.
- [x] Run `docker compose exec -T php php tests/Services/LoginIpBanServiceTest.php`.
- [x] Run `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`.
- [x] Run `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`.
- [x] Run `docker compose exec -T php php bin/run-tests.php`.
