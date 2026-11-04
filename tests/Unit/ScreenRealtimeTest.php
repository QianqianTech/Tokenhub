<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * Operations screen (SEC-001..003, SCR-001..004) against an SQLite stand-in and
 * the local Redis:
 * - login throttling per IP and per share code, custom share-code strength;
 * - sessions end with the link's expires_at and after the sliding idle window;
 *   "refresh" access-log rows are written once per interval, not per poll;
 * - "today / this month" follow BUSINESS_TIMEZONE (hourly table for the partial
 *   first UTC day, daily table afterwards);
 * - the security panel is limited to today and to the link's scope;
 * - screen metrics are cached per (metric, scope);
 * - the live endpoint reads the minute table, circuits and open alerts.
 */

use TokenHub\Services\DashboardService;
use TokenHub\Services\ScreenService;
use TokenHub\Support\App;
use TokenHub\Support\BusinessTime;
use TokenHub\Support\Crypto;
use TokenHub\Support\Database;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}
putenv('SCREEN_LOGIN_PER_IP_PER_MINUTE=3');
putenv('SCREEN_LOGIN_PER_CODE_PER_MINUTE=2');
putenv('SCREEN_METRIC_CACHE_SECONDS=5');
putenv('BUSINESS_TIMEZONE=Asia/Shanghai');
try {
    App::redis()->connection()->ping();
} catch (Throwable) {
    echo "SKIP redis not reachable\n";
    return;
}

final class ScreenSqlitePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = preg_replace('/TIMESTAMPDIFF\(SECOND, (\w+(?:\.\w+)?), NOW\(\)\)/', "(strftime('%s','now') - strftime('%s', \$1))", $query) ?? $query;
        $query = preg_replace('/\s+LIMIT 5000$/', '', $query) ?? $query;

        return parent::prepare($query, $options);
    }
}

$pdo = new ScreenSqlitePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'));
$aggregateColumns = 'bucket_at TEXT, department_id INT, user_id INT, api_key_id INT, provider_id INT, model_id INT, capability TEXT DEFAULT "text_generation",
    request_count INT DEFAULT 0, success_count INT DEFAULT 0, failed_count INT DEFAULT 0, input_tokens INT DEFAULT 0, output_tokens INT DEFAULT 0,
    cache_read_tokens INT DEFAULT 0, cache_write_tokens INT DEFAULT 0, total_cost REAL, latency_ms_sum INT DEFAULT 0,
    latency_bucket_100 INT DEFAULT 0, latency_bucket_300 INT DEFAULT 0, latency_bucket_1000 INT DEFAULT 0, latency_bucket_3000 INT DEFAULT 0, latency_bucket_over_3000 INT DEFAULT 0';
foreach ([
    'CREATE TABLE dashboard_share_links (id INTEGER PRIMARY KEY, share_code TEXT UNIQUE, name TEXT, username TEXT, password_hash TEXT, scope_type TEXT DEFAULT "global", scope_ids TEXT, allowed_metrics TEXT, allow_drilldown INT DEFAULT 0, allowed_ip_cidrs TEXT, expires_at TEXT, status TEXT DEFAULT "active", session_version INT DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE dashboard_share_sessions (id INTEGER PRIMARY KEY, share_link_id INT, session_hash TEXT UNIQUE, session_version INT, client_ip TEXT, user_agent TEXT, revoked_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE dashboard_share_access_logs (id INTEGER PRIMARY KEY, share_link_id INT, event_type TEXT, client_ip TEXT, user_agent TEXT, reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    "CREATE TABLE usage_aggregates_minute ({$aggregateColumns})",
    "CREATE TABLE usage_aggregates_hour ({$aggregateColumns})",
    "CREATE TABLE usage_aggregates_day ({$aggregateColumns})",
    'CREATE TABLE usage_records (id INTEGER PRIMARY KEY, request_id TEXT, department_id INT, user_id INT, api_key_id INT, created_at TEXT)',
    'CREATE TABLE desensitization_events (id INTEGER PRIMARY KEY, policy_id INT, request_id TEXT, target_type TEXT, action TEXT, match_count INT, summary TEXT, created_at TEXT)',
    'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, action TEXT, created_at TEXT)',
    'CREATE TABLE alert_events (id INTEGER PRIMARY KEY, event_type TEXT, status TEXT, created_at TEXT)',
    'CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, path TEXT)',
    'CREATE TABLE providers (id INTEGER PRIMARY KEY, name TEXT)',
    'CREATE TABLE models (id INTEGER PRIMARY KEY, name TEXT)',
] as $ddl) {
    $pdo->exec($ddl);
}
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
(new ReflectionProperty(App::class, 'database'))->setValue(null, $database);
(new ReflectionProperty(App::class, 'context'))->setValue(null, RequestContext::fromHeaders([]));
BusinessTime::reset();
assert_same('Asia/Shanghai', BusinessTime::name(), 'Test runs in a non-UTC business time zone.');

$redis = App::redis()->connection();
$tag = bin2hex(random_bytes(4));
$service = new ScreenService();
$events = static fn (int $shareId): array => array_map(
    static fn (array $row): string => $row['event_type'] . ':' . ($row['reason'] ?? ''),
    App::db()->select('SELECT event_type, reason FROM dashboard_share_access_logs WHERE share_link_id = ? ORDER BY id', [$shareId])
);
$count = static fn (array $list, string $prefix): int => count(array_filter($list, static fn (string $event): bool => str_starts_with($event, $prefix)));

// ---- SEC-001: custom share codes ----
foreach (['short', 'aaaaaaaaaaaaaaaaaaaa', 'abcdefghijklmnopqrst', '1234567890123456', 'screen-2026-lobby', 'Lobby_Screen_2026!x'] as $weak) {
    assert_true(!ScreenService::customShareCodeAcceptable($weak), "Weak share code {$weak} should be rejected.");
}
foreach (['Xk9mPq2Lr7Tz4Wn8', '7f3a9c1e5b2d8f4a6c0e', 'r4N_d0m-C0dE_x7Qp'] as $strong) {
    assert_true(ScreenService::customShareCodeAcceptable($strong), "Random-looking share code {$strong} should be accepted.");
}

// ---- SEC-001: throttling per IP and per code ----
if (time() % 60 > 50) {
    sleep(61 - time() % 60); // stay inside one limiter window
}
$ip = '198.51.100.' . random_int(1, 254) . '-' . $tag;
for ($i = 1; $i <= 3; $i++) {
    assert_same(null, $service->loginThrottle($ip, "code:ip-{$tag}-{$i}"), "Attempt {$i} from one IP is allowed.");
}
$limited = $service->loginThrottle($ip, "code:ip-{$tag}-4");
assert_true(is_array($limited) && $limited['retry_after'] >= 1, 'The 4th attempt from one IP within a minute is throttled.');
assert_same(null, $service->loginThrottle("203.0.113.1-{$tag}", "code:shared-{$tag}"), 'Code attempt 1 is allowed.');
assert_same(null, $service->loginThrottle("203.0.113.2-{$tag}", "code:shared-{$tag}"), 'Code attempt 2 (other IP) is allowed.');
assert_true(is_array($service->loginThrottle("203.0.113.3-{$tag}", "code:shared-{$tag}")), 'The 3rd attempt on one code is throttled even from a new IP.');

// ---- SEC-002 / SCR-002: session lifetime and refresh logging ----
$pdo->exec("INSERT INTO dashboard_share_links (id, share_code, name, username, password_hash, scope_type) VALUES (1, 'Xk9mPq2Lr7Tz4Wn8', 'Lobby', 'lobby', 'x', 'global')");
$login = $service->loginByShareCode('Xk9mPq2Lr7Tz4Wn8', '10.0.0.1', 'ScreenTest');
assert_true(($login['ok'] ?? false) === true, 'Share-code login works.');
$session = (string) $login['session'];
$sessionHash = Crypto::hashKey($session);
for ($i = 0; $i < 5; $i++) {
    assert_true($service->session($session, '10.0.0.1') !== null, 'A fresh session authenticates.');
}
assert_same(0, $count($events(1), 'refresh:'), 'Polling a fresh session writes no refresh rows.');
$pdo->prepare('UPDATE dashboard_share_sessions SET updated_at = ? WHERE session_hash = ?')->execute([gmdate('Y-m-d H:i:s', time() - 600), $sessionHash]);
for ($i = 0; $i < 5; $i++) {
    assert_true($service->session($session, '10.0.0.1') !== null, 'A session idle for 10 minutes still authenticates.');
}
assert_same(1, $count($events(1), 'refresh:'), 'After the interval exactly one refresh row is written.');
$seen = $pdo->query("SELECT updated_at FROM dashboard_share_sessions WHERE session_hash = '{$sessionHash}'")->fetchColumn();
assert_true(strtotime($seen . ' UTC') >= time() - 5, 'The refresh moves the session activity forward (sliding lifetime).');

$pdo->prepare('UPDATE dashboard_share_sessions SET updated_at = ? WHERE session_hash = ?')->execute([gmdate('Y-m-d H:i:s', time() - 2 * 86400), $sessionHash]);
assert_same(null, $service->session($session, '10.0.0.1'), 'A session idle beyond SCREEN_SESSION_IDLE_SECONDS ends.');
assert_same(null, $service->session($session, '10.0.0.1'), 'An ended session stays ended.');
assert_same(1, $count($events(1), 'session_expired:idle_timeout'), 'Idle expiry is logged once.');

$second = (string) $service->loginByShareCode('Xk9mPq2Lr7Tz4Wn8', '10.0.0.1', 'ScreenTest')['session'];
assert_true($service->session($second, '10.0.0.1') !== null, 'A new session works.');
$pdo->exec("UPDATE dashboard_share_links SET expires_at = '" . gmdate('Y-m-d H:i:s', time() - 60) . "' WHERE id = 1");
assert_same(null, $service->session($second, '10.0.0.1'), 'The link deadline ends existing sessions (expires_at).');
assert_same(1, $count($events(1), 'session_expired:share_expired'), 'Link expiry is logged.');
$pdo->exec('UPDATE dashboard_share_links SET expires_at = NULL WHERE id = 1');

$pdo->exec("INSERT INTO dashboard_share_access_logs (share_link_id, event_type, created_at) VALUES (1, 'refresh', '2000-01-01 00:00:00')");
assert_same(1, $service->pruneAccessLogs(30), 'Old access-log rows are pruned.');

// ---- SCR-003: business-time periods ----
$dashboard = new DashboardService();
$ranges = $dashboard->periodRanges('2026-10-30 16:00:00');
assert_same([
    ['usage_aggregates_hour', '2026-10-30 16:00:00', '2026-10-31 00:00:00'],
    ['usage_aggregates_day', '2026-10-31 00:00:00', null],
], $ranges, 'A local day starting at 16:00 UTC reads 8 hourly buckets, then daily buckets.');
assert_same([['usage_aggregates_day', '2026-10-31 00:00:00', null]], $dashboard->periodRanges('2026-10-31 00:00:00'), 'A UTC-midnight start reads only daily buckets.');

$todayStart = BusinessTime::periodStartUtc('day');
$startTs = strtotime($todayStart . ' UTC');
$nextUtcDay = gmdate('Y-m-d 00:00:00', $startTs + 86400);
$insert = $pdo->prepare('INSERT INTO usage_aggregates_hour (bucket_at, department_id, model_id, provider_id, request_count, input_tokens, output_tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insert->execute([gmdate('Y-m-d H:00:00', $startTs), 5, 1, 1, 7, 100, 50]);          // local 00:00 today: counted
$insert->execute([gmdate('Y-m-d H:00:00', $startTs - 3600), 5, 1, 1, 1000, 1, 1]);  // local 23:00 yesterday: not counted
$insert = $pdo->prepare('INSERT INTO usage_aggregates_day (bucket_at, department_id, model_id, provider_id, request_count, input_tokens, output_tokens) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insert->execute([$nextUtcDay, 5, 1, 1, 3, 10, 5]);                                     // later UTC day: counted
$insert->execute([gmdate('Y-m-d 00:00:00', $startTs), 6, 2, 2, 500, 1, 1]);         // UTC day that began yesterday locally: not counted
$realtime = $dashboard->realtime([]);
assert_same(10, (int) $realtime['today']['request_count'], 'Today = hourly buckets of the partial UTC day + daily buckets after it.');
assert_same('Asia/Shanghai', $realtime['business_timezone'], 'The payload names the business time zone.');
$rankings = $dashboard->rankings(['dimension' => 'model']);
assert_same(1, count($rankings), 'Rankings merge both ranges per group.');
assert_same(10, (int) $rankings[0]['request_count'], 'Merged ranking sums both ranges.');

// ---- SEC-003 + SCR-001: scoped security panel, cached per scope ----
$pdo->exec("INSERT INTO departments (id, name, path) VALUES (5, 'Five', '/5/'), (6, 'Six', '/6/')");
$now = gmdate('Y-m-d H:i:s');
$pdo->exec("INSERT INTO usage_records (request_id, department_id, created_at) VALUES ('req-5-{$tag}', 5, '{$now}'), ('req-6-{$tag}', 6, '{$now}')");
$pdo->exec("INSERT INTO desensitization_events (request_id, action, match_count, created_at) VALUES ('req-5-{$tag}', 'replace', 2, '{$now}'), ('req-6-{$tag}', 'block', 1, '{$now}'), ('req-old', 'block', 9, '2000-01-01 00:00:00')");
$pdo->exec("INSERT INTO audit_logs (action, created_at) VALUES ('user.update', '{$now}')");
$global = ['scope_type' => 'global', 'scope_ids' => null, 'allowed_metrics' => null];
$dept5 = ['scope_type' => 'department', 'scope_ids' => '[5]', 'allowed_metrics' => null];
$sum = static fn (array $rows): int => array_sum(array_map(static fn (array $row): int => (int) $row['event_count'], $rows));
foreach (['security', 'live', 'overview'] as $metric) {
    $redis->del($dashboard->screenCacheKey($metric, $global), $dashboard->screenCacheKey($metric, $dept5));
}
$globalSecurity = $dashboard->screenMetric('security', $global)['data'];
assert_same(2, $sum($globalSecurity['desensitization']), 'A global screen sees all of today\'s events, not older ones.');
assert_same(1, $sum($globalSecurity['audit']), 'A global screen sees today\'s audit actions.');
$scopedSecurity = $dashboard->screenMetric('security', $dept5)['data'];
assert_same(1, $sum($scopedSecurity['desensitization']), 'A department screen sees only events of its own requests.');
assert_same([], $scopedSecurity['audit'], 'A department screen sees no system audit actions.');
$noIds = $dashboard->screenSecurity(['scope_type' => 'department', 'scope_ids' => '[]']);
assert_same([], $noIds['desensitization'], 'A scoped link without ids sees nothing.');

$pdo->exec("INSERT INTO desensitization_events (request_id, action, match_count, created_at) VALUES ('req-5-{$tag}', 'replace', 1, '{$now}')");
assert_same(1, $sum($dashboard->screenMetric('security', $dept5)['data']['desensitization']), 'Within the cache window the same (metric, scope) is served from Redis.');
assert_same(3, $sum($dashboard->screenSecurity($global)['desensitization']), 'The uncached read sees the new event.');
assert_true($dashboard->screenCacheKey('security', $dept5) !== $dashboard->screenCacheKey('security', $global), 'Cache keys differ per scope.');

// ---- SCR-004: live endpoint ----
$minute = gmdate('Y-m-d H:i:00');
$pdo->exec("INSERT INTO providers (id, name) VALUES (1, 'Provider One'), (2, 'Provider Two')");
$pdo->exec("INSERT INTO models (id, name) VALUES (990001, 'open-model')");
$liveInsert = $pdo->prepare('INSERT INTO usage_aggregates_minute (bucket_at, department_id, provider_id, model_id, request_count, success_count, failed_count, input_tokens, output_tokens, latency_ms_sum, latency_bucket_300, latency_bucket_3000) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$liveInsert->execute([$minute, 5, 1, 990001, 60, 54, 6, 6000, 3000, 12000, 50, 10]);
$liveInsert->execute([$minute, 6, 2, 2, 40, 40, 0, 1000, 1000, 4000, 40, 0]);
$liveInsert->execute([gmdate('Y-m-d H:i:00', time() - 3600), 5, 1, 1, 999, 0, 999, 0, 0, 0, 0, 0]); // outside the window
$pdo->exec("INSERT INTO alert_events (event_type, status) VALUES ('x', 'open'), ('y', 'resolved')");
$redis->set('route_health:990001', json_encode(['failures' => 5, 'open_until' => time() + 60]), ['ex' => 120]);
$redis->set('route_health:990002', json_encode(['failures' => 0, 'open_until' => 0]), ['ex' => 120]);
try {
    $live = $dashboard->screenLive($global);
    assert_same(100, $live['request_count'], 'Live counts the last minutes only.');
    assert_true($live['qps'] > 0 && $live['tps'] > 0, 'Live reports QPS and TPS.');
    assert_same(6.0, (float) $live['error_rate'], 'Live error rate = failed / requests.');
    assert_same(3000, $live['p95_latency_ms'], 'Live P95 comes from the minute latency buckets.');
    assert_same(false, $live['ttft']['available'], 'TTFT is reported as pending until the gateway records it.');
    assert_same('Provider One', $live['providers'][0]['name'], 'Live has a provider dimension with names.');
    assert_same(1, $live['open_alerts'], 'A global screen shows open alerts.');
    assert_true($live['circuits']['available'] && $live['circuits']['open'] >= 1, 'Open circuits come from route_health:* keys.');
    assert_true(in_array('open-model', array_column($live['circuits']['open_channels'], 'name'), true), 'Open channels are named on a global screen.');
    assert_true(in_array('circuit_open', $live['health']['reasons'], true), 'An open circuit lowers the health light.');

    $scopedLive = $dashboard->screenLive($dept5);
    assert_same(60, $scopedLive['request_count'], 'A department screen sees only its own traffic.');
    assert_same(null, $scopedLive['open_alerts'], 'System alerts are not shown on scoped screens.');
    assert_same(1, $scopedLive['circuits']['open'], 'A scoped screen sees circuits of the models it uses.');
    assert_same([], $scopedLive['circuits']['open_channels'], 'A scoped screen does not list channel names.');

    assert_true(!($dashboard->screenMetric('live', ['allowed_metrics' => '["overview"]', 'scope_type' => 'global'])['blocked'] ?? false), 'Live is allowed with the overview metric.');
    assert_true($dashboard->screenMetric('live', ['allowed_metrics' => '["security"]', 'scope_type' => 'global'])['blocked'] ?? false, 'Live is blocked when only security is allowed.');
    $security = $dashboard->screenMetric('security', $dept5);
    assert_same('Asia/Shanghai', $security['business_timezone'], 'Screen payloads name the business time zone.');
    assert_same(gmdate('c', $startTs), $security['data']['period_start'], 'The security panel starts at the local-day start.');
    assert_same(gmdate('c', $startTs), $realtime['period_start'], 'Realtime reports the local-day start.');
} finally {
    $redis->del('route_health:990001', 'route_health:990002');
    foreach (['security', 'live', 'overview'] as $metric) {
        $redis->del($dashboard->screenCacheKey($metric, $global), $dashboard->screenCacheKey($metric, $dept5));
    }
}

echo "OK screen realtime\n";
