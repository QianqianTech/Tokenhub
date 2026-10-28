# WAF Core Attack Defense Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add normalized, scored, observe-first application-layer detection for SQL injection, XSS, command injection, SSRF, and path traversal without changing TokenHub deployment configuration.

**Architecture:** Keep Caddy-generated rules as the fast edge layer. Add a focused inspector service and global middleware for PHP-routed traffic; persist only a sanitized event summary, and use typed system settings plus an `attack` WAF matcher to control behavior.

**Tech Stack:** PHP 8.2, TokenHub Router/Middleware, MySQL migrations, Caddy fragment generation, vanilla admin JavaScript.

---

## File Structure

- `app/Services/WafRequestInspector.php`: bounded extraction, canonicalization, signature matching, score calculation, and sanitized evidence.
- `app/Middleware/WafInspectionMiddleware.php`: global observe/block gate and event recording.
- `app/Services/WafService.php`: `attack` matcher validation, templates, enabled attack-rule loading, and event persistence helpers.
- `app/Services/SystemSettingsService.php`: typed detection defaults and normalization.
- `app/Bootstrap/Kernel.php`: place inspection after system IP bans and before routing.
- `database/migrations/2026_07_10_000017_waf_core_attack_defense.sql`: additive WAF event columns and indexes.
- `public/admin/assets/js/resources.js` / `public/admin/assets/js/table.js`: detection settings and attack-rule editor/event display.
- Tests: focused inspector, middleware, WAF service/settings/frontend contracts.

### Task 1: Lock the inspector contract with failing tests

**Files:**
- Create: `tests/Services/WafRequestInspectorTest.php`
- Create: `app/Services/WafRequestInspector.php`

- [ ] **Step 1: Write failing attack-classification tests**

```php
$inspector = new WafRequestInspector();
$settings = ['enabled' => true, 'mode' => 'observe', 'block_threshold' => 100, 'max_inspect_bytes' => 65536, 'max_field_count' => 128, 'max_field_bytes' => 4096, 'excluded_paths' => ['/health']];
$rules = [['rule_code' => 'attack_sql_injection', 'matcher' => ['type' => 'attack', 'categories' => ['sqli'], 'targets' => ['query'], 'weight' => 100, 'critical' => false], 'status' => 'active']];
$request = new Request('GET', '/v1/models', [], ['q' => '1%20UNION%20SELECT%201'], [], '', new RequestContext('req_waf_attack'));
$result = $inspector->inspect($request, $rules, $settings);
assert_same(100, $result['risk_score']);
assert_same(['sqli'], $result['categories']);
assert_true($result['matched']);
```

Add cases for encoded XSS in JSON, command injection in form data, metadata-address SSRF, double-encoded traversal, `/health` exclusion, bounded fields, and a normal prompt that produces no match.

- [ ] **Step 2: Run the new test and confirm it fails**

Run: `docker compose exec -T php php tests/Services/WafRequestInspectorTest.php`

Expected: failure because `TokenHub\Services\WafRequestInspector` does not exist.

- [ ] **Step 3: Implement the minimal inspector**

```php
public function inspect(Request $request, array $rules, array $settings): array
{
    if (!$settings['enabled'] || in_array($request->path, $settings['excluded_paths'], true)) {
        return $this->emptyResult();
    }
    $signals = $this->signals($request, $settings);
    $hits = $this->matchRules($signals, $rules);
    return $this->resultFromHits($hits, (int) $settings['block_threshold']);
}
```

Implement `signals()` with a single bounded decode pass (`rawurldecode` then `html_entity_decode`), slash and whitespace normalization, recursive scalar extraction, target labels, and SHA-256 evidence fingerprints. Implement built-in category predicates without retaining raw values.

- [ ] **Step 4: Run the inspector test**

Run: `docker compose exec -T php php tests/Services/WafRequestInspectorTest.php`

Expected: exit code 0.

### Task 2: Add typed settings and additive schema support

**Files:**
- Modify: `app/Services/SystemSettingsService.php`
- Create: `database/migrations/2026_07_10_000017_waf_core_attack_defense.sql`
- Modify: `tests/Services/SystemSettingsServiceTest.php`

- [ ] **Step 1: Add failing settings normalization assertions**

```php
$detection = $service->normalizeSection('waf_detection_settings', [
    'enabled' => 'true', 'mode' => 'block', 'block_threshold' => 80,
    'max_inspect_bytes' => 9999999, 'max_field_count' => 0,
    'max_field_bytes' => -1, 'excluded_paths' => ['/health', 'bad'],
]);
assert_same('block', $detection['mode']);
assert_same(80, $detection['block_threshold']);
assert_same(65536, $detection['max_inspect_bytes']);
assert_same(['/health'], $detection['excluded_paths']);
```

- [ ] **Step 2: Run the settings test and confirm it fails**

Run: `docker compose exec -T php php tests/Services/SystemSettingsServiceTest.php`

Expected: unknown settings section failure.

- [ ] **Step 3: Implement defaults, normalization, and migration**

```sql
ALTER TABLE waf_events
  ADD COLUMN IF NOT EXISTS detection_mode VARCHAR(16) NULL AFTER action,
  ADD COLUMN IF NOT EXISTS risk_score INT UNSIGNED NULL AFTER detection_mode,
  ADD COLUMN IF NOT EXISTS attack_categories JSON NULL AFTER risk_score,
  ADD INDEX idx_waf_events_detection (detection_mode, risk_score, created_at);
```

Add `waf_detection_settings` to `SECTION_KEYS`, `defaults()`, `normalizeSection()`, and `description()`. Enforce `mode` in `observe|block`, threshold `1..1000`, inspection size `1024..65536`, field count `1..128`, field bytes `128..4096`, and absolute `/`-prefixed excluded paths.

- [ ] **Step 4: Run migration and settings test**

Run: `docker compose exec -T php php bin/migrate.php; docker compose exec -T php php tests/Services/SystemSettingsServiceTest.php`

Expected: migration is applied (or already recorded) and the test exits 0.

### Task 3: Extend WAF rule contracts and sanitize detection event persistence

**Files:**
- Modify: `app/Services/WafService.php`
- Modify: `tests/Services/WafServiceTest.php`

- [ ] **Step 1: Add failing `attack` matcher tests**

```php
$preview = $service->preview(['rules' => [[
    'rule_code' => 'attack_sql_injection', 'scope' => 'global',
    'matcher' => ['type' => 'attack', 'categories' => ['sqli'], 'targets' => ['query', 'body'], 'weight' => 100, 'critical' => false],
    'action' => 'block', 'priority' => 20, 'status' => 'active',
]]]);
assert_same('valid', $preview['status']);
assert_true(!str_contains($preview['fragment'], 'attack_sql_injection'));
```

Also assert invalid categories, targets, weights and rule modes fail preview; assert templates include all five categories; assert persisted event summary contains a fingerprint but not `Authorization`, API key, Cookie, or raw payload text.

- [ ] **Step 2: Run focused WAF service tests and confirm failure**

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`

Expected: `attack` matcher validation fails until support is added.

- [ ] **Step 3: Implement attack matcher support**

```php
private const ALLOWED_MATCHER_TYPES = ['ip', 'path', 'method', 'query', 'header', 'body_size', 'security_headers', 'attack'];
```

Validate the allowed category set (`sqli`, `xss`, `command_injection`, `ssrf`, `path_traversal`), target set (`path`, `query`, `header`, `body`), weight and optional rule mode. Return the five attack templates. Exclude `attack` matchers from `fragment()` while retaining them in previews and version snapshots. Add one WafService method that inserts the sanitized detection event columns plus the existing fields.

- [ ] **Step 4: Run focused WAF service tests**

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`

Expected: exit code 0.

### Task 4: Gate dynamic requests with observe/block middleware

**Files:**
- Create: `app/Middleware/WafInspectionMiddleware.php`
- Modify: `app/Bootstrap/Kernel.php`
- Create: `tests/Support/WafInspectionMiddlewareTest.php`

- [ ] **Step 1: Write failing middleware tests**

```php
$request = new Request('GET', '/v1/models', [], ['q' => '1 UNION SELECT 1'], [], '', new RequestContext('req_waf_block'));
$response = (new WafInspectionMiddleware($inspector, $wafService, ['mode' => 'block', 'block_threshold' => 100]))($request, fn () => Response::ok(['reachable' => true]));
assert_same(403, $response->status);
assert_true(str_contains($response->body, 'WAF_BLOCKED'));
```

Add observe-mode continuation, disabled-mode continuation, `/health` skip, failed inspector fail-open, and source contract assertions that the global order is `SystemIpBanMiddleware` then `WafInspectionMiddleware`.

- [ ] **Step 2: Run middleware test and confirm failure**

Run: `docker compose exec -T php php tests/Support/WafInspectionMiddlewareTest.php`

Expected: class-not-found or missing global registration failure.

- [ ] **Step 3: Implement the middleware and registration**

```php
public function __invoke(Request $request, callable $next): Response
{
    try { $result = $this->inspector->inspect($request, $this->waf->attackRules(), $this->settings()); }
    catch (\Throwable $error) { App::logger()->warning('security', 'waf inspection failed', ['request_id' => $request->context->requestId()]); return $next($request); }
    if ($result['matched']) { $this->waf->recordInspection($request, $result); }
    return $result['blocked'] ? Response::error('WAF_BLOCKED', 'Request blocked by web application firewall', 403) : $next($request);
}
```

Register it immediately after `new SystemIpBanMiddleware()` in `Kernel::router()`.

- [ ] **Step 4: Run middleware test**

Run: `docker compose exec -T php php tests/Support/WafInspectionMiddlewareTest.php`

Expected: exit code 0.

### Task 5: Expose detection configuration and evidence safely in the admin UI

**Files:**
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`
- Modify: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`
- Modify: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: Write failing frontend contract assertions**

```php
assert_true(str_contains($resources, 'settings-waf-detection'), 'Admin resources should expose WAF detection settings.');
assert_true(str_contains($table, 'waf_detection_settings'), 'Settings UI should bind WAF detection settings.');
assert_true(str_contains($table, 'risk_score'), 'WAF event renderer should show the risk score.');
assert_true(str_contains($table, 'attack_categories'), 'WAF event renderer should show attack categories.');
assert_true(str_contains($table, 'block'), 'Detection settings should allow block mode confirmation.');
```

- [ ] **Step 2: Run the frontend tests and confirm failure**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php; docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: missing detection settings and evidence rendering assertions.

- [ ] **Step 3: Implement the focused UI controls**

Add a read-only settings resource for `waf_detection_settings` with enabled, mode, threshold and bounded limits. Extend the guided WAF editor with `attack` type, category/target multiselects, weight, critical toggle, exclusions and mode. In the event renderer show score, category badges, mode and fingerprint only; never render `summary` as raw request data. Require a confirmation dialog before saving global `block` mode.

- [ ] **Step 4: Run syntax and frontend tests**

Run: `node --check public/admin/assets/js/resources.js; node --check public/admin/assets/js/table.js; docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php; docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: all commands exit 0.

### Task 6: Perform integrated security regression verification

**Files:**
- Modify: `docs/superpowers/plans/2026-07-10-waf-core-attack-defense.md`

- [ ] **Step 1: Run focused detection and existing WAF suites**

Run: `docker compose exec -T php php tests/Services/WafRequestInspectorTest.php; docker compose exec -T php php tests/Support/WafInspectionMiddlewareTest.php; docker compose exec -T php php tests/Services/WafServiceTest.php; docker compose exec -T php php tests/Services/SystemSettingsServiceTest.php`

Expected: all focused suites exit 0.

- [ ] **Step 2: Run the complete PHP suite**

Run: `docker compose exec -T php php bin/run-tests.php`

Expected: every test file passes.

- [ ] **Step 3: Review the final change set**

Run: `git diff --check; git diff -- app/Services/WafRequestInspector.php app/Middleware/WafInspectionMiddleware.php app/Services/WafService.php app/Services/SystemSettingsService.php app/Bootstrap/Kernel.php database/migrations/2026_07_10_000017_waf_core_attack_defense.sql public/admin/assets/js/resources.js public/admin/assets/js/table.js tests`

Expected: no whitespace errors; changes are limited to the designed defense path.
