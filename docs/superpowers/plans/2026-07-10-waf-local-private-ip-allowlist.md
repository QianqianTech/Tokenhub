# WAF Local Private IP Allowlist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the server's detected private IPv4 addresses reachable by default whenever WAF admin or API CIDR restrictions are enabled.

**Architecture:** Add a normalized opt-out setting, then merge dynamically discovered private IPv4 `/32` entries into non-empty explicit allowlists during WAF fragment generation. Keep certificate NIC candidates strict, but expose WAF-specific `local_access_ips` that also include the container default gateway observed by Caddy. Reuse the existing endpoint to show effective automatic entries without persisting transient IPs.

**Tech Stack:** PHP 8.2, Caddy matcher synthesis, native JavaScript admin UI, repository PHP contract tests, Docker Compose verification.

---

### Task 1: Lock the setting contract

**Files:**
- Modify: `tests/Services/SystemSettingsServiceTest.php`
- Modify: `app/Services/SystemSettingsService.php`

- [ ] **Step 1: Write the failing test**

Add assertions that `firewall_settings.auto_allow_local_private_ips` defaults to `true` and normalizes the string `false` to boolean `false`.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T php php tests/Services/SystemSettingsServiceTest.php`

Expected: FAIL because the new setting key does not exist.

- [ ] **Step 3: Write minimal implementation**

Add the default and normalize it with the existing `boolValue` helper:

```php
'auto_allow_local_private_ips' => true,
```

```php
'auto_allow_local_private_ips' => $this->boolValue(
    $data['auto_allow_local_private_ips'] ?? $default['auto_allow_local_private_ips']
),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T php php tests/Services/SystemSettingsServiceTest.php`

Expected: PASS.

### Task 2: Merge local `/32` entries into active allowlists

**Files:**
- Modify: `tests/Services/WafServiceTest.php`
- Modify: `app/Services/WafService.php`

- [ ] **Step 1: Write failing behavior tests**

Use `TOKENHUB_CERTIFICATE_IPS=192.168.55.7` to exercise the real `NetworkInterfaceIpService`. Assert that a non-empty admin/API allowlist includes `192.168.55.7/32`, disabling the setting omits it, and empty explicit allowlists still omit both allowlist matchers.

- [ ] **Step 2: Run tests to verify they fail for the missing merge**

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`

Expected: FAIL because the generated fragment does not contain `192.168.55.7/32`.

- [ ] **Step 3: Implement the smallest safe merge helper**

Add a helper that returns the explicit list unchanged when it is empty or the setting is disabled. Otherwise load candidates, validate IPv4 again, append `/32`, deduplicate, and catch discovery failures.

```php
private function effectiveAllowedCidrs(mixed $cidrs, bool $autoAllowLocal): array
{
    $explicit = array_values(array_unique(array_filter(array_map('strval', is_array($cidrs) ? $cidrs : []))));
    if ($explicit === [] || !$autoAllowLocal) {
        return $explicit;
    }

    try {
        $ips = (new NetworkInterfaceIpService())->localAccessIps();
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $explicit[] = $ip . '/32';
            }
        }
    } catch (\Throwable) {
    }

    return array_values(array_unique($explicit));
}
```

Use the helper for both admin and API allowlist rules.

- [ ] **Step 4: Run focused tests**

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`

Expected: PASS.

### Task 3: Expose the behavior in the WAF settings UI

**Files:**
- Modify: `tests/Frontend/AdminSettingsCenterFrontendTest.php`
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/css/app.css`

- [ ] **Step 1: Write the failing frontend contract test**

Assert that the WAF settings definition includes `auto_allow_local_private_ips`, that its specialized page loads `/admin-api/system/network-interface-ips`, and that the UI presents detected addresses as `/32` automatic entries.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: FAIL because the toggle and WAF local-IP summary do not exist.

- [ ] **Step 3: Implement the settings control and status card**

Add the select field, route firewall settings through `createFirewallSettingsPage`, and render detected `ips` with `${ip}/32`. Explain that automatic entries are active only when the corresponding explicit list is non-empty.

- [ ] **Step 4: Add scoped styling**

Style `.waf-local-access-summary`, `.waf-local-ip-list`, and `.waf-local-ip-chip` using existing panel colors and responsive wrapping.

- [ ] **Step 5: Run syntax and focused frontend tests**

Run: `node --check public/admin/assets/js/table.js`

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: both commands exit 0.

### Task 4: Regression verification

**Files:**
- Verify: `app/Services/SystemSettingsService.php`
- Verify: `app/Services/WafService.php`
- Verify: `public/admin/assets/js/table.js`

- [ ] **Step 1: Run PHP syntax checks**

Run: `docker compose exec -T php php -l app/Services/SystemSettingsService.php`

Run: `docker compose exec -T php php -l app/Services/WafService.php`

- [ ] **Step 2: Run all focused tests together**

Run: `docker compose exec -T php php tests/Services/SystemSettingsServiceTest.php`

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 3: Run the full suite**

Run: `docker compose exec -T php php bin/run-tests.php`

Expected: exit 0 with no failed tests.

The current workspace does not expose valid Git repository metadata, so execution records test checkpoints instead of commit checkpoints.
