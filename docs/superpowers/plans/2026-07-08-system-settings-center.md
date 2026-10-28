# System Settings Center Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a typed system settings center for certificates, entry security, retention/export, backups, logs, and alert thresholds.

**Architecture:** Add a focused backend service that owns section schemas and writes to `system_settings`, then expose dedicated admin routes. Add a dedicated admin frontend renderer that uses those endpoints and keeps generic `system-settings` as an advanced fallback.

**Tech Stack:** PHP 8.2, native Router/Controller/Service classes, MySQL `system_settings`, static ES modules, existing PHP test runner.

---

### Task 1: Backend Settings Service

**Files:**
- Create: `app/Services/SystemSettingsService.php`
- Test: `tests/Services/SystemSettingsServiceTest.php`

- [ ] **Step 1: Write service tests**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\SystemSettingsService;

$events = [];
$service = new SystemSettingsService(static function (array $event) use (&$events): void {
    $events[] = $event;
});

$defaults = $service->defaults();
assert_same(14, $defaults['tls_certificates']['threshold_days'], 'TLS certificate threshold should default to 14 days.');
assert_same([], $defaults['entry_security']['admin_allowed_cidrs'], 'Admin CIDR defaults should be empty.');
assert_same(300, $defaults['retention_export']['export_download_ttl_seconds'], 'Export download TTL should default to 300 seconds.');
assert_same(1000, $defaults['alert_thresholds']['queue_backlog_count'], 'Queue backlog threshold should default to 1000.');

$normalizedTls = $service->normalizeSection('tls_certificates', [
    'threshold_days' => -1,
    'certificates' => [
        ['name' => 'main', 'domains' => ['example.com'], 'issuer' => 'internal-ca', 'expires_at' => '2026-07-09 00:00:00', 'status' => 'active'],
        ['name' => '', 'expires_at' => ''],
    ],
]);
assert_same(14, $normalizedTls['threshold_days'], 'Invalid TLS threshold should fall back to default.');
assert_same(1, count($normalizedTls['certificates']), 'Invalid certificates should be dropped.');

$normalizedEntry = $service->normalizeSection('entry_security', [
    'admin_allowed_cidrs' => "10.0.0.0/8\n192.168.0.0/16",
    'api_key_cidr_enforced' => 'true',
    'masked_header_fields' => ['Authorization', '', 'Cookie'],
]);
assert_same(['10.0.0.0/8', '192.168.0.0/16'], $normalizedEntry['admin_allowed_cidrs'], 'CIDR text should normalize to a list.');
assert_same(true, $normalizedEntry['api_key_cidr_enforced'], 'Boolean-like values should normalize.');
assert_same(['Authorization', 'Cookie'], $normalizedEntry['masked_header_fields'], 'Empty mask fields should be removed.');

$normalizedRetention = $service->normalizeSection('retention_export', [
    'export_download_ttl_seconds' => 1,
    'export_file_retention_days' => 0,
    'backup_retention_days' => 9999,
    'cleanup_batch_size' => 0,
]);
assert_same(60, $normalizedRetention['export_download_ttl_seconds'], 'Download TTL should be clamped to minimum.');
assert_same(7, $normalizedRetention['export_file_retention_days'], 'Export retention should fall back when invalid.');
assert_same(3650, $normalizedRetention['backup_retention_days'], 'Backup retention should be clamped to maximum.');
assert_same(100, $normalizedRetention['cleanup_batch_size'], 'Cleanup batch size should fall back when invalid.');

$normalizedAlerts = $service->normalizeSection('alert_thresholds', [
    'waf_spike_count' => 0,
    'provider_error_rate' => 2,
    'provider_min_requests' => 0,
]);
assert_same(10, $normalizedAlerts['waf_spike_count'], 'WAF threshold should fall back when invalid.');
assert_same(1.0, $normalizedAlerts['provider_error_rate'], 'Provider error rate should clamp to 1.0.');
assert_same(5, $normalizedAlerts['provider_min_requests'], 'Provider minimum requests should fall back when invalid.');

assert_true(!$service->isKnownSection('unknown'), 'Unknown settings sections should be rejected.');

$checkEvents = $service->checkCertificateAlerts([
    'threshold_days' => 30,
    'certificates' => [
        ['name' => 'soon', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)],
    ],
]);
assert_same(1, count($checkEvents), 'Certificate check should return expiring certificate events.');
```

- [ ] **Step 2: Run failing test**

Run: `php tests/Services/SystemSettingsServiceTest.php`

Expected: fail because `SystemSettingsService` does not exist.

- [ ] **Step 3: Implement service**

Create `SystemSettingsService` with defaults, normalization, section loading/saving, summary, and certificate-alert checking.

- [ ] **Step 4: Run passing test**

Run: `php tests/Services/SystemSettingsServiceTest.php`

Expected: pass.

### Task 2: Admin Routes And Controller

**Files:**
- Modify: `app/Http/Controllers/AdminController.php`
- Modify: `app/Bootstrap/Kernel.php`
- Test: `tests\Support\AdminSecurityContractTest.php`

- [ ] **Step 1: Extend source contract test**

Assert that controller and router sources contain `settingsCenter`, `updateSettingsCenterSection`, `checkCertificateAlerts`, `/admin-api/system/settings-center`, and `/admin-api/system/settings-center/certificate-alert-check`.

- [ ] **Step 2: Run failing contract test**

Run: `php tests\Support\AdminSecurityContractTest.php`

Expected: fail because settings-center routes do not exist.

- [ ] **Step 3: Add controller methods and routes**

Add three methods that delegate to `SystemSettingsService`, and register GET/PUT/POST routes with the same auth/CSRF shape as other system settings routes.

- [ ] **Step 4: Run passing contract test**

Run: `php tests\Support\AdminSecurityContractTest.php`

Expected: pass.

### Task 3: Admin Frontend Page

**Files:**
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`
- Modify: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] **Step 1: Extend frontend contract test**

Assert that `settings-center` is exposed to super admins, the dedicated renderer exists, and the three settings-center endpoints appear in `table.js`.

- [ ] **Step 2: Run failing frontend test**

Run: `php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: fail because the frontend does not expose the settings center.

- [ ] **Step 3: Add resource and renderer**

Add `settings-center` to the System group before `system-settings`. Add `renderSettingsCenterPage` with forms for certificate/entry settings, retention/export settings, alert thresholds, runtime summary, and certificate alert check.

- [ ] **Step 4: Run passing frontend test**

Run: `php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: pass.

### Task 4: Full Verification

**Files:**
- No code changes expected.

- [ ] **Step 1: Run focused service and contract tests**

Run:

```sh
php tests/Services/SystemSettingsServiceTest.php
php tests\Support\AdminSecurityContractTest.php
php tests\Frontend\AdminFrontendSecurityTest.php
```

Expected: all pass.

- [ ] **Step 2: Run project test suite**

Run: `php bin/run-tests.php`

Expected: all available tests pass, or any unrelated environmental failure is reported with the first failing test.
