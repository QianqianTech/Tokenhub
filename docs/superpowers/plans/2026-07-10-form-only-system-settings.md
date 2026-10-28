# Form-only System Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the raw JSON-based Advanced Settings screen so every supported system configuration is edited through the typed forms in System Settings.

**Architecture:** This is an admin-frontend information-architecture change. The existing `/admin-api/system/settings-center` reads and section-save endpoints remain unchanged; the sidebar and role metadata stop exposing the generic `system-settings` resource, while the already-present typed settings tabs remain the sole configuration UI.

**Tech Stack:** Vanilla ES modules, PHP frontend contract tests, Docker PHP test runner.

---

### Task 1: Lock the no-raw-JSON UI boundary with a focused frontend contract

**Files:**
- Modify: `D:\mysoft\tokenhub\tests\Frontend\AdminSettingsCenterFrontendTest.php:37-45`
- Modify: `D:\mysoft\tokenhub\tests\Frontend\AdminFrontendSecurityTest.php:192-195`

- [ ] **Step 1: Write the failing contract assertions**

Require the system navigation to contain only the typed settings-center entry, and reject a `system-settings` resource definition containing a `setting_value` JSON field.

```php
assert_true(!str_contains($systemSection, '"system-settings"'), 'System navigation should not expose the raw JSON settings table.');
assert_true(!str_contains($resources, '"system-settings": {'), 'Raw system settings metadata should be removed.');
```

- [ ] **Step 2: Run the focused test and verify the expected failure**

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: failure because `system-settings` is still present in the super-admin access list, system navigation, and resource metadata.

### Task 2: Remove the raw settings resource while preserving typed settings forms

**Files:**
- Modify: `D:\mysoft\tokenhub\public\admin\assets\js\resources.js:4,256,420,466`
- Modify: `D:\mysoft\tokenhub\public\admin\assets\js\domains.js:12`

- [ ] **Step 1: Remove raw settings from every admin navigation and access registry**

Keep `settings-center` in the super-admin access list and system domain, but remove `system-settings`. Delete its resource and navigation-label definitions so no generic form can render the `setting_value` JSON editor.

```js
{ key: "system", label: "系统设置", symbol: "设", items: ["content-retention-policy", "settings-center", "admin-users"] }
```

```js
{ key: "system", label: "系统运维", superAdmin: true, tabs: ["operations-status", "settings-tls-certificates", "settings-brand-identity", "content-retention-policy", "log-retention-policies", "log-archive-files", "settings-alert-thresholds"] }
```

- [ ] **Step 2: Run the focused test and verify it passes**

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: exit code 0.

### Task 3: Verify adjacent admin security/navigation contracts

**Files:**
- Verify: `D:\mysoft\tokenhub\public\admin\assets\js\resources.js`
- Verify: `D:\mysoft\tokenhub\public\admin\assets\js\domains.js`
- Verify: `D:\mysoft\tokenhub\tests\Frontend\AdminFrontendSecurityTest.php`

- [ ] **Step 1: Check JavaScript syntax**

Run: `node --check public/admin/assets/js/resources.js; node --check public/admin/assets/js/domains.js`

Expected: exit code 0.

- [ ] **Step 2: Run the settings and adjacent security contract tests**

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php; docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: both tests exit 0.

- [ ] **Step 3: Run the full PHP suite**

Run: `docker compose exec -T php php bin/run-tests.php`

Expected: exit code 0 with no failing test files.

## Plan self-review

- Scope is limited to the admin frontend; settings APIs, database JSON storage, Docker configuration, and Caddy are untouched.
- The typed forms already cover the supported sections, so no replacement backend contract is needed.
- The focused contract fails before the implementation and validates both absence of the raw resource and continued presence of typed settings.
