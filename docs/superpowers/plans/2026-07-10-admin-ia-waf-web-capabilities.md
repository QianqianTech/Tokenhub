# Admin Information Architecture and WAF Web Capabilities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the resource-heavy Admin sidebar with business-domain pages and tabs, make WAF a first-class control plane, and expose all approved management capabilities through secure Web flows.

**Architecture:** The frontend owns a domain-and-tab navigation map and delegates existing resource views to the current renderer. New focused Admin services expose WAF/IP-ban state, operational health, alert disposition, and scoped downloads; the controller requires `super_admin` for every new control-plane endpoint. Short-lived download tokens stay behind `/download/{token}` and are re-authorized when consumed.

**Tech Stack:** Native PHP 8.2, MySQL, Redis, Caddy, vanilla ES modules, Docker Compose PHP test runner.

**Repository note:** `D:\mysoft\tokenhub\.git` is not a valid Git repository in the current checkout. Do not run commit commands until repository metadata is restored; verify each task with the commands below instead.

---

## File structure

- Create: `app/Services/AdminOperationsService.php` — aggregate safe, read-only health and queue status.
- Create: `app/Services/AdminDownloadService.php` — issue and validate scoped admin download tokens for media and log archives.
- Create: `public/admin/assets/js/domains.js` — business-domain navigation map, hash parsing, role filtering, and tab shell.
- Create: `tests/Services/AdminOperationsServiceTest.php` — operations-status service contract.
- Create: `tests/Services/AdminDownloadServiceTest.php` — scoped download-token contract.
- Create: `tests/Frontend/AdminDomainNavigationFrontendTest.php` — domain menu, tabs, deep-link, and WAF placement contract.
- Create: `tests/Frontend/AdminWafControlPlaneFrontendTest.php` — WAF overview, IP-ban, template, and alert-tab contract.
- Modify: `app/Bootstrap/Kernel.php` — protected Admin routes.
- Modify: `app/Http/Controllers/AdminController.php` — controller actions and shared super-admin gate.
- Modify: `app/Http/Controllers/DownloadController.php` — consume scoped admin media/archive download tokens.
- Modify: `app/Services/WafService.php` — WAF overview, IP-ban management, and explicit edge-publication status.
- Modify: `app/Services/LoginIpBanService.php` — validated manual ban and paginated ban listing.
- Modify: `app/Services/MediaAssetService.php` — expose scope-safe asset lookup for the admin download service.
- Modify: `public/admin/assets/js/app.js` — hash-driven domain navigation and tab routing.
- Modify: `public/admin/assets/js/resources.js` — retain resource metadata but replace resource-by-resource sidebar sections.
- Modify: `public/admin/assets/js/table.js` — domain-specific WAF, alert, operations, and download actions.
- Modify: `public/admin/assets/css/app.css` — domain-tab and operational-panel layout.
- Modify: `tests/Services/WafServiceTest.php`, `tests/Services/LoginIpBanServiceTest.php`, `tests/Frontend/AdminFrontendSecurityTest.php` — regression coverage for service-side role enforcement and existing behavior.

## Task 1: Add control-plane authorization and IP-ban service contracts

**Files:**
- Modify: `app/Services/LoginIpBanService.php`
- Modify: `app/Services/WafService.php`
- Modify: `app/Http/Controllers/AdminController.php`
- Test: `tests/Services/LoginIpBanServiceTest.php`
- Test: `tests/Services/WafServiceTest.php`

- [ ] **Step 1: Write failing service tests for manual ban lifecycle and WAF overview.**

```php
$manualIp = '198.51.100.' . random_int(1, 200);
$ban = $loginIpBans->ban($manualIp, 'admin_manual_ban');
assert_same(true, $ban['banned']);
assert_true(in_array($manualIp, array_column($loginIpBans->listBans()['records'], 'client_ip'), true));
$loginIpBans->reset($manualIp);
assert_same(false, $loginIpBans->isBanned($manualIp));

$overview = $waf->overview();
assert_true(isset($overview['publication']['status']));
assert_true(isset($overview['summary']['active_ban_count']));
assert_true(isset($overview['recent_events']));
```

- [ ] **Step 2: Run the two focused tests and record the expected failure.**

Run: `docker compose exec -T php php tests/Services/LoginIpBanServiceTest.php`  
Expected: failure because `ban()` and `listBans()` do not exist.

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`  
Expected: failure because `overview()` does not exist.

- [ ] **Step 3: Implement validated manual bans and WAF overview.**

```php
public function ban(string $clientIp, string $reason): array
{
    $clientIp = $this->normalizeIp($clientIp);
    if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
        abort_json('WAF_IP_INVALID', 'A valid IP address is required', 422);
    }
    App::db()->execute(
        'INSERT INTO system_ip_bans (client_ip, failure_count, reason, banned_at, last_failed_at)
         VALUES (?, 1, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE failure_count = GREATEST(failure_count, 1), reason = VALUES(reason), banned_at = NOW(), last_failed_at = NOW()',
        [$clientIp, substr(trim($reason), 0, 120)]
    );
    App::redis()->set($this->cacheKey($clientIp), '1', self::CACHE_TTL_SECONDS);
    return $this->result(App::db()->first('SELECT * FROM system_ip_bans WHERE client_ip = ?', [$clientIp]) ?: []);
}

public function listBans(array $query = []): array
{
    $limit = min(200, max(1, (int) ($query['limit'] ?? 50)));
    $rows = App::db()->select('SELECT id, client_ip, failure_count, reason, last_failed_at, banned_at FROM system_ip_bans WHERE banned_at IS NOT NULL ORDER BY banned_at DESC LIMIT ' . $limit);
    return ['records' => $rows, 'pagination' => ['limit' => $limit, 'total' => count($rows)]];
}
```

`WafService::overview()` must return `publication`, `summary`, `recent_events`, and `top_rules`; the publication object derives from the latest `waf_rule_versions` row and the summary uses the existing event statistics plus `LoginIpBanService::activeBannedIps()`.

- [ ] **Step 4: Add a controller-only super-admin gate used by all new and existing WAF mutation/read actions.**

```php
private function requireSuperAdmin(): array
{
    $actor = App::context()->adminUser() ?? [];
    if (($actor['role'] ?? '') !== 'super_admin') {
        abort_json('ADMIN_PERMISSION_DENIED', 'Super administrator permission required', 403);
    }
    return $actor;
}
```

Call `requireSuperAdmin()` as the first statement in `publishWaf`, `rollbackWaf`, `wafVersions`, `wafEvents`, `wafStatistics`, `wafTemplates`, `previewWaf`, `handleWafEvent`, `autoDispositionWaf`, `wafVersionDiff`, and each new WAF endpoint. Keep ordinary dashboard and department-scoped data behavior unchanged.

- [ ] **Step 5: Re-run focused tests.**

Run: `docker compose exec -T php php tests/Services/LoginIpBanServiceTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`  
Expected: PASS.

## Task 2: Add Admin WAF, alert, operations, and download APIs

**Files:**
- Create: `app/Services/AdminOperationsService.php`
- Create: `app/Services/AdminDownloadService.php`
- Modify: `app/Http/Controllers/AdminController.php`
- Modify: `app/Http/Controllers/DownloadController.php`
- Modify: `app/Bootstrap/Kernel.php`
- Modify: `app/Services/MediaAssetService.php`
- Test: `tests/Services/AdminOperationsServiceTest.php`
- Test: `tests/Services/AdminDownloadServiceTest.php`

- [ ] **Step 1: Write failing tests for operations status and admin-scoped download tokens.**

```php
$status = (new AdminOperationsService())->status();
assert_true(isset($status['health']['status']));
assert_true(isset($status['queues']['pending']));
assert_true(isset($status['queues']['failed']));

$token = (new AdminDownloadService())->createMediaDownload($assetId);
assert_true(str_starts_with($token['download_url'], 'http'));
assert_same(300, $token['expires_in']);
assert_true((bool) App::redis()->get('download:' . basename($token['download_url'])));
```

- [ ] **Step 2: Run the focused tests and verify they fail because the services are absent.**

Run: `docker compose exec -T php php tests/Services/AdminOperationsServiceTest.php`  
Expected: failure because `AdminOperationsService` is undefined.

Run: `docker compose exec -T php php tests/Services/AdminDownloadServiceTest.php`  
Expected: failure because `AdminDownloadService` is undefined.

- [ ] **Step 3: Implement the operations-status and alert-disposition services.**

```php
public function status(): array
{
    $queue = (string) App::config()->get('queue.name', 'tokenhub:jobs');
    return [
        'health' => (new HealthCheckService(App::db(), App::redis()))->check(),
        'queues' => [
            'pending' => (int) App::redis()->connection()->lLen($queue),
            'processing' => (int) App::redis()->connection()->lLen($queue . ':processing'),
            'failed' => (int) App::redis()->connection()->lLen((string) App::config()->get('queue.failed_name', 'tokenhub:failed')),
        ],
        'recent_worker_logs' => (new LogService())->query(['log_type' => 'worker', 'limit' => 20]),
    ];
}
```

Add `AdminController::updateAlertStatus()` to accept only `acknowledged` or `resolved`, require a nonempty `note` no longer than 500 characters, update the event status, and write `alert.status.update` through `AuditService`.

- [ ] **Step 4: Implement scoped download-token generation and consumption.**

`AdminDownloadService` must create Redis payloads with `kind` set to `admin_media` or `admin_log_archive`, `admin_user_id`, `department_id`, object identifier, and 300-second TTL. `DownloadController::show()` must branch on `kind`, authenticate the `tokenhub_admin_session` cookie through `AuthService::userFromSession()`, require the current user ID to equal `admin_user_id`, load the requested record, call `PermissionService::canManage()` for department-scoped media, require `super_admin` for log archives, and then stream the file. Do not return `storage_path`, `archive_path`, or raw token payload in a response.

- [ ] **Step 5: Register explicit protected routes.**

```php
$router->get('/admin-api/waf/overview', [$admin, 'wafOverview'], [new AdminAuthMiddleware()]);
$router->get('/admin-api/waf/ip-bans', [$admin, 'wafIpBans'], [new AdminAuthMiddleware()]);
$router->post('/admin-api/waf/ip-bans', [$admin, 'banWafIp'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
$router->delete('/admin-api/waf/ip-bans/{ip}', [$admin, 'unbanWafIp'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
$router->get('/admin-api/operations/status', [$admin, 'operationsStatus'], [new AdminAuthMiddleware()]);
$router->post('/admin-api/alerts/{id}/status', [$admin, 'updateAlertStatus'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
$router->post('/admin-api/media-assets/{asset_id}/download-url', [$admin, 'mediaDownloadUrl'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
$router->post('/admin-api/log-archive-files/{id}/download-url', [$admin, 'archiveDownloadUrl'], [new AdminAuthMiddleware(), new CsrfMiddleware()]);
```

- [ ] **Step 6: Run API/service regression tests.**

Run: `docker compose exec -T php php tests/Services/AdminOperationsServiceTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Services/AdminDownloadServiceTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`  
Expected: PASS.

## Task 3: Build business-domain navigation and deep-linked tabs

**Files:**
- Create: `public/admin/assets/js/domains.js`
- Modify: `public/admin/assets/js/app.js`
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/css/app.css`
- Test: `tests/Frontend/AdminDomainNavigationFrontendTest.php`
- Test: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] **Step 1: Write the failing frontend contract test.**

```php
assert_true(str_contains($domains, 'key: "waf"') && str_contains($domains, 'label: "WAF 防火墙"'));
assert_true(str_contains($domains, '"waf-overview"') && str_contains($domains, '"waf-ip-bans"'));
assert_true(str_contains($app, 'hashchange') && str_contains($app, 'location.hash'));
assert_true(!str_contains($resources, 'key: "security", label: "安全", items: ["waf-rules"'));
```

- [ ] **Step 2: Run the new frontend test and verify failure.**

Run: `docker compose exec -T php php tests/Frontend/AdminDomainNavigationFrontendTest.php`  
Expected: failure because `domains.js` and hash navigation do not exist.

- [ ] **Step 3: Implement the domain definition and hash parser.**

```js
export const adminDomains = [
  { key: "operations", label: "运营中心", tabs: ["dashboard", "admin-chat-sessions", "dashboard-share-links", "dashboard-share-access-logs"] },
  { key: "organization", label: "组织与权限", tabs: ["departments", "users", "admin-users"] },
  { key: "models", label: "模型服务", tabs: ["providers", "models", "model-prices"] },
  { key: "access", label: "API 接入", tabs: ["api-keys", "api-key-model-quotas", "quota-policies", "quota-bindings"] },
  { key: "usage", label: "用量中心", tabs: ["usage-records", "multimodal-tasks", "media-assets", "usage-contents", "content-archive", "export-tasks"] },
  { key: "rag", label: "RAG 知识库", tabs: ["rag-dashboard", "rag-knowledge-management", "rag-import-review", "rag-image-vector", "rag-feedback-correction", "rag-retrieval-evaluation", "rag-advanced-data", "rag-settings"] },
  { key: "waf", label: "WAF 防火墙", superAdmin: true, tabs: ["waf-overview", "settings-firewall", "waf-rules", "waf-rule-versions", "waf-events", "waf-ip-bans", "waf-alerts"] },
  { key: "security", label: "安全审计", tabs: ["desensitization-policies", "desensitization-events", "alert-events", "logs", "audit-logs"] },
  { key: "system", label: "系统运维", superAdmin: true, tabs: ["operations-status", "settings-tls-certificates", "settings-brand-identity", "content-retention-policy", "log-retention-policies", "log-archive-files", "settings-alert-thresholds", "system-settings"] }
];
```

`parseAdminHash()` must default to the first permitted domain and tab, while `writeAdminHash(domainKey, tabKey)` writes `#/${domainKey}/${tabKey}`. Listen for `hashchange` before rendering and preserve the previous resource renderer for individual tabs.

- [ ] **Step 4: Add shared tab styling and responsive overflow.**

```css
.domain-page { display: grid; gap: 1rem; }
.domain-tabs { display: flex; gap: .5rem; overflow-x: auto; padding-bottom: .25rem; }
.domain-tab { white-space: nowrap; border: 1px solid var(--border); border-radius: .5rem; padding: .5rem .75rem; }
.domain-tab.active { background: var(--accent); border-color: var(--accent); color: #fff; }
```

- [ ] **Step 5: Run frontend navigation regressions.**

Run: `docker compose exec -T php php tests/Frontend/AdminDomainNavigationFrontendTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`  
Expected: PASS after assertions are updated from the old resource-sidebar contract to the approved domain contract.

## Task 4: Render the WAF control plane and alert lifecycle

**Files:**
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/css/app.css`
- Test: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`
- Test: `tests/Frontend/AdminLayoutOverflowTest.php`

- [ ] **Step 1: Write failing frontend contract tests.**

```php
assert_true(str_contains($table, '/admin-api/waf/overview'));
assert_true(str_contains($table, '/admin-api/waf/ip-bans'));
assert_true(str_contains($table, '/admin-api/alerts/${row.id}/status'));
assert_true(str_contains($table, '/admin-api/waf/templates'));
assert_true(str_contains($table, '预填规则'));
```

- [ ] **Step 2: Run the focused test and confirm failure.**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`  
Expected: failure because no WAF overview or IP-ban renderer exists.

- [ ] **Step 3: Implement WAF-only pages and template prefill.**

`renderWafOverviewPage()` loads `/admin-api/waf/overview` and displays publication status, active rules, active bans, recent events, and a link to the publish/version tab. `renderWafIpBansPage()` loads the ban list, offers a validated IP-and-reason form, and uses the explicit unban endpoint. `showWafTemplates()` must render a `预填规则` button for each template and call `showCreateModal("waf-rules", resources["waf-rules"], ..., templateRule)` with matcher, scope, action, priority, and description prefilled.

`renderAlertEventsPage()` must render immutable event details plus `确认` and `关闭` controls that submit a required note, then refresh the list. It must not use generic CRUD for `alert_events`.

- [ ] **Step 4: Make edge publication state explicit after IP actions.**

Each IP-ban success result must show `应用层封禁已生效` and either `边缘 WAF 已发布` or `边缘 WAF 等待发布`; derive the latter from the WAF overview publication payload. Do not label an unpublished ban as Caddy-effective.

- [ ] **Step 5: Run WAF frontend and layout tests.**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Frontend/AdminLayoutOverflowTest.php`  
Expected: PASS.

## Task 5: Add operations, alert, export, media, and archive Web actions

**Files:**
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/js/resources.js`
- Modify: `public/admin/assets/css/app.css`
- Test: `tests/Frontend/AdminOperationsFrontendTest.php`

- [ ] **Step 1: Write failing UI contract tests.**

```php
assert_true(str_contains($table, '/admin-api/operations/status'));
assert_true(str_contains($table, '/admin-api/exports/${row.export_id}/download-url'));
assert_true(str_contains($table, '/admin-api/media-assets/${row.asset_id}/download-url'));
assert_true(str_contains($table, '/admin-api/log-archive-files/${row.id}/download-url'));
```

- [ ] **Step 2: Run the focused test and verify failure.**

Run: `docker compose exec -T php php tests/Frontend/AdminOperationsFrontendTest.php`  
Expected: failure because operational panels and download actions do not exist.

- [ ] **Step 3: Implement read-only operations status and secure download buttons.**

`renderOperationsStatusPage()` loads `/admin-api/operations/status` and displays health, pending/processing/failed queue counts, and the latest worker records without start/stop/retry controls. Successful export, media, and archive rows render `下载` actions; each action posts to its corresponding download-url endpoint and opens only the returned `download_url` in a new tab.

- [ ] **Step 4: Run operations frontend tests.**

Run: `docker compose exec -T php php tests/Frontend/AdminOperationsFrontendTest.php`  
Expected: PASS.

## Task 6: Run complete regression and verify non-regression

**Files:**
- Modify: `docs/superpowers/plans/2026-07-10-admin-ia-waf-web-capabilities.md` — mark verified tasks complete only after evidence is collected.

- [ ] **Step 1: Run all changed focused tests in Docker.**

Run: `docker compose exec -T php php tests/Services/LoginIpBanServiceTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Services/AdminOperationsServiceTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Services/AdminDownloadServiceTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Frontend/AdminDomainNavigationFrontendTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`  
Expected: PASS.

Run: `docker compose exec -T php php tests/Frontend/AdminOperationsFrontendTest.php`  
Expected: PASS.

- [ ] **Step 2: Run the full suite.**

Run: `docker compose exec -T php php bin/run-tests.php`  
Expected: every test file passes.

- [ ] **Step 3: Verify the working-tree state without committing.**

Run: `git -C D:\mysoft\tokenhub status --short`  
Expected: this currently reports an invalid repository; record that limitation in the final handoff and do not run destructive repository repair commands.
