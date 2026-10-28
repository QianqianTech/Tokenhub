<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\WafService;

$service = new WafService();
$wafServiceSource = file_get_contents(__DIR__ . '/../../app/Services/WafService.php') ?: '';
assert_true(str_contains($wafServiceSource, 'localAccessIps'), 'WAF allowlist synthesis should use local access IP discovery, including the Docker host gateway.');
$overview = $service->overview();
assert_true(isset($overview['publication']['status']), 'WAF overview should expose the latest edge publication status.');
assert_true(isset($overview['summary']['active_ban_count']), 'WAF overview should include the active system IP ban count.');
assert_true(isset($overview['recent_events']), 'WAF overview should include recent security events for the WAF dashboard.');

$templates = $service->templates();
$templateKeys = array_column($templates, 'key');
assert_true(in_array('attack_sql_injection', $templateKeys, true), 'WAF templates should include SQL injection detection.');
assert_true(in_array('high_risk_paths', $templateKeys, true), 'WAF templates should include high-risk path blocking.');
assert_true(in_array('sensitive_query_keys', $templateKeys, true), 'WAF templates should include sensitive query key blocking.');
assert_true(in_array('scanner_user_agents', $templateKeys, true), 'WAF templates should include scanner user-agent blocking.');
assert_true(in_array('admin_cidr_allow', $templateKeys, true), 'WAF templates should include admin CIDR allow-listing.');
assert_true(in_array('api_cidr_allow', $templateKeys, true), 'WAF templates should include API CIDR allow-listing.');
foreach ($templates as $template) {
    assert_true(isset($template['matcher']) && is_array($template['matcher']), 'Each WAF template should expose a structured matcher.');
    assert_true(isset($template['action']) && is_string($template['action']), 'Each WAF template should expose an action.');
}

$attackPreview = $service->preview(['rules' => [[
    'rule_code' => 'attack_sql_injection',
    'description' => 'application layer SQL injection detection',
    'matcher' => ['type' => 'attack', 'categories' => ['sqli'], 'targets' => ['query', 'body'], 'weight' => 100, 'critical' => false],
    'action' => 'block',
    'priority' => 20,
    'status' => 'active',
]]]);
assert_same('valid', $attackPreview['status'], 'Attack matcher should pass WAF preview.');
assert_true(!str_contains($attackPreview['fragment'], 'attack_sql_injection'), 'Attack matcher should not emit a Caddy fragment.');

$preview = $service->preview([
    'rules' => [
        [
            'rule_code' => 'preview_admin_cidr',
            'description' => 'preview admin allow list',
            'matcher' => ['type' => 'ip', 'paths' => ['/admin*', '/admin-api/*'], 'allow' => ['10.0.0.0/8']],
            'action' => 'block',
            'priority' => 10,
            'status' => 'active',
        ],
        [
            'rule_code' => 'preview_env_path',
            'description' => 'preview env path block',
            'matcher' => ['type' => 'path', 'values' => ['/.env', '/.git/*']],
            'action' => 'block',
            'priority' => 20,
            'status' => 'active',
        ],
    ],
]);
assert_same('valid', $preview['status'], 'WAF preview should mark valid rule sets as valid.');
assert_true(str_contains($preview['fragment'], 'preview_admin_cidr'), 'WAF preview should include generated Caddy fragment text.');
assert_true(str_contains($preview['fragment'], 'not remote_ip 10.0.0.0/8'), 'WAF preview should generate CIDR allow-list matchers.');
assert_same(0, count($preview['findings']['errors']), 'WAF preview should not report errors for valid rules.');
assert_true(count($preview['high_risk']) >= 1, 'WAF preview should classify admin CIDR and sensitive path rules as high risk.');

$invalidPreview = $service->preview([
    'rules' => [
        [
            'rule_code' => 'invalid_query_rule',
            'description' => 'invalid query rule',
            'matcher' => ['type' => 'query'],
            'action' => 'block',
            'priority' => 30,
            'status' => 'active',
        ],
        [
            'rule_code' => 'invalid_action_rule',
            'description' => 'invalid action rule',
            'matcher' => ['type' => 'method', 'deny' => ['TRACE']],
            'action' => 'drop',
            'priority' => 40,
            'status' => 'active',
        ],
    ],
]);
assert_same('invalid', $invalidPreview['status'], 'WAF preview should mark invalid rule sets as invalid.');
assert_true(count($invalidPreview['findings']['errors']) >= 2, 'WAF preview should return actionable validation errors.');
assert_true($invalidPreview['fragment'] === '', 'WAF preview should not generate a fragment for invalid rules.');

$publishRuleCode = 'test_admin_cidr_' . bin2hex(random_bytes(4));
$previousActiveRuleIds = array_map(
    static fn (array $row): int => (int) $row['id'],
    \TokenHub\Support\App::db()->select('SELECT id FROM waf_rules WHERE status = "active"')
);
\TokenHub\Support\App::db()->execute('UPDATE waf_rules SET status = "disabled" WHERE status = "active"');
\TokenHub\Support\App::db()->execute(
    'INSERT INTO waf_rules (rule_code, scope, matcher, action, priority, status, description) VALUES (?, "global", ?, "block", 5, "active", "test high risk publish guard")',
    [$publishRuleCode, json_encode(['type' => 'ip', 'paths' => ['/admin*', '/admin-api/*'], 'allow' => ['10.20.0.0/16']], JSON_UNESCAPED_SLASHES)]
);
try {
    $dryRun = $service->publish(['dry_run' => true, 'reload' => false]);
    assert_same('dry_run', $dryRun['status'], 'WAF dry-run publish should validate without installing the fragment.');
    assert_true(str_contains($dryRun['fragment'], $publishRuleCode), 'WAF dry-run publish should return the generated fragment.');
    $guarded = $service->publish(['reload' => false]);
    assert_same('requires_confirmation', $guarded['status'], 'WAF publish should require confirmation for high-risk rules.');
    assert_true(count($guarded['high_risk']) >= 1, 'WAF publish confirmation response should include high-risk details.');
} finally {
    \TokenHub\Support\App::db()->execute('DELETE FROM waf_rules WHERE rule_code = ?', [$publishRuleCode]);
    if ($previousActiveRuleIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($previousActiveRuleIds), '?'));
        \TokenHub\Support\App::db()->execute("UPDATE waf_rules SET status = \"active\" WHERE id IN ({$placeholders})", $previousActiveRuleIds);
    }
}

$fragment = $service->generateFragment([
    [
        'rule_code' => 'admin_ip_allow',
        'description' => 'admin CIDR allow list',
        'matcher' => json_encode(['type' => 'ip', 'paths' => ['/admin*', '/admin-api/*'], 'allow' => ['10.0.0.0/8']]),
        'action' => 'block',
        'priority' => 10,
    ],
    [
        'rule_code' => 'blocked_ip',
        'description' => 'blocked CIDR list',
        'matcher' => json_encode(['type' => 'ip', 'deny' => ['192.168.0.0/16']]),
        'action' => 'block',
        'priority' => 11,
    ],
    [
        'rule_code' => 'bad_method',
        'description' => 'deny dangerous methods',
        'matcher' => json_encode(['type' => 'method', 'deny' => ['TRACE', 'TRACK']]),
        'action' => 'block',
        'priority' => 20,
    ],
    [
        'rule_code' => 'query_secret',
        'description' => 'deny secret query keys',
        'matcher' => json_encode(['type' => 'query', 'keys' => ['token', 'secret'], 'patterns' => ['(?i)union\\s+select']]),
        'action' => 'block',
        'priority' => 30,
    ],
    [
        'rule_code' => 'drop_internal_header',
        'description' => 'remove internal headers',
        'matcher' => json_encode(['type' => 'header', 'headers' => ['X-Internal-Token']]),
        'action' => 'remove_header',
        'priority' => 40,
    ],
    [
        'rule_code' => 'mask_authorization',
        'description' => 'mask authorization',
        'matcher' => json_encode(['type' => 'header', 'headers' => ['Authorization']]),
        'action' => 'redact',
        'priority' => 41,
    ],
    [
        'rule_code' => 'block_bad_agent',
        'description' => 'block scanner user agents',
        'matcher' => json_encode(['type' => 'header', 'headers' => ['User-Agent' => '*sqlmap*']]),
        'action' => 'block',
        'priority' => 42,
    ],
    [
        'rule_code' => 'body_limit',
        'description' => 'limit body',
        'matcher' => json_encode(['type' => 'body_size', 'max_bytes' => 1048576]),
        'action' => 'block',
        'priority' => 50,
    ],
    [
        'rule_code' => 'security_headers',
        'description' => 'set response headers',
        'matcher' => json_encode(['type' => 'security_headers', 'headers' => ['Strict-Transport-Security' => 'max-age=31536000']]),
        'action' => 'allow',
        'priority' => 60,
    ],
]);

assert_true(str_contains($fragment, '# Generated TokenHub WAF fragment'), 'WAF fragment should include generated header.');
assert_true(str_contains($fragment, '@waf_admin_ip_allow'), 'IP rule should create a named matcher.');
assert_true(str_contains($fragment, 'not remote_ip 10.0.0.0/8'), 'IP allow list should block non-allowed CIDRs.');
assert_true(str_contains($fragment, 'remote_ip 192.168.0.0/16'), 'IP deny list should block denied CIDRs.');
assert_true(str_contains($fragment, 'method TRACE TRACK'), 'Method rule should include denied HTTP methods.');
assert_true(str_contains($fragment, 'query token=*'), 'Query rule should match sensitive token key.');
assert_true(str_contains($fragment, '../') || str_contains($fragment, '..%2f'), 'Query rule should include path traversal detection.');
assert_true(str_contains($fragment, 'request_header -X-Internal-Token'), 'Header remove rule should delete internal headers before upstream.');
assert_true(str_contains($fragment, 'request_header Authorization "****"'), 'Header redact rule should mask sensitive headers before upstream.');
assert_true(str_contains($fragment, 'header User-Agent "*sqlmap*"'), 'Header block rule should match configured header values.');
assert_true(str_contains($fragment, 'request_body'), 'Body size rule should configure a Caddy request body limit.');
assert_true(str_contains($fragment, 'Strict-Transport-Security "max-age=31536000"'), 'Security header rule should set configured response headers.');
assert_true(str_contains($fragment, 'log_name caddy-security'), 'Blocking rules should be routed to caddy-security log.');
assert_true(str_contains($fragment, 'vars waf_rule bad_method'), 'Blocking rules should annotate caddy-security logs with rule code.');
assert_true(str_contains($fragment, 'vars waf_action block'), 'Blocking rules should annotate caddy-security logs with action.');

$firewallSettings = [
    'enabled' => true,
    'auto_publish' => false,
    'auto_allow_local_private_ips' => true,
    'admin_allowed_cidrs' => ['10.10.0.0/16'],
    'api_allowed_cidrs' => ['172.20.0.0/16'],
    'manual_blocked_cidrs' => ['203.0.113.44'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'blocked_paths' => ['/admin/debug/*', '/.env'],
    'sensitive_query_keys' => ['token', 'secret'],
    'max_body_bytes' => 2048,
    'login_failure_threshold' => 5,
];
$previousCertificateIps = getenv('TOKENHUB_CERTIFICATE_IPS');
putenv('TOKENHUB_CERTIFICATE_IPS=192.168.55.7');
\TokenHub\Support\App::db()->execute(
    'INSERT INTO system_settings (setting_key, setting_value, description) VALUES ("firewall_settings", ?, "test firewall settings") ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)',
    [json_encode($firewallSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
);
try {
    $settingsFragment = $service->generateFragment([]);
    assert_true(str_contains($settingsFragment, 'system_admin_allowed_cidrs'), 'Firewall settings should generate an admin CIDR WAF rule.');
    assert_true(str_contains($settingsFragment, 'path /admin* /admin-api/*'), 'Admin CIDR WAF rule should scope to admin paths.');
    assert_true(str_contains($settingsFragment, 'not remote_ip 10.10.0.0/16'), 'Admin CIDR WAF rule should block non-allowed admin source IPs.');
    assert_true(str_contains($settingsFragment, '192.168.55.7/32'), 'Admin and API allowlists should automatically include the detected local private IP as an exact host CIDR.');
    assert_true(str_contains($settingsFragment, 'system_api_allowed_cidrs'), 'Firewall settings should generate an API CIDR WAF rule.');
    assert_true(str_contains($settingsFragment, 'path /v1/*'), 'API CIDR WAF rule should scope to API paths.');
    assert_true(str_contains($settingsFragment, 'not remote_ip 172.20.0.0/16'), 'API CIDR WAF rule should block non-allowed API source IPs.');
    assert_true(str_contains($settingsFragment, 'remote_ip 203.0.113.44'), 'Firewall settings should generate manual blocked CIDR rules.');
    assert_true(str_contains($settingsFragment, 'method DELETE PUT PATCH TRACE TRACK CONNECT'), 'Firewall settings should deny methods outside the allowed method list.');
    assert_true(str_contains($settingsFragment, 'path /admin/debug/* /.env'), 'Firewall settings should generate blocked path rules.');
    assert_true(str_contains($settingsFragment, 'query token=*'), 'Firewall settings should block sensitive query keys.');
    assert_true(str_contains($settingsFragment, 'max_size 2048'), 'Firewall settings should generate body size limit.');
    assert_true(str_contains($settingsFragment, 'vars waf_rule system_manual_blocked_cidrs'), 'Firewall settings blocks should preserve waf_rule vars.');

    $firewallSettings['auto_allow_local_private_ips'] = false;
    \TokenHub\Support\App::db()->execute(
        'UPDATE system_settings SET setting_value = ? WHERE setting_key = "firewall_settings"',
        [json_encode($firewallSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
    );
    $fragmentWithoutLocalIp = $service->generateFragment([]);
    assert_true(!str_contains($fragmentWithoutLocalIp, '192.168.55.7/32'), 'Disabling automatic local private IP access should leave only explicit allowlist CIDRs.');

    $firewallSettings['auto_allow_local_private_ips'] = true;
    $firewallSettings['admin_allowed_cidrs'] = [];
    $firewallSettings['api_allowed_cidrs'] = [];
    \TokenHub\Support\App::db()->execute(
        'UPDATE system_settings SET setting_value = ? WHERE setting_key = "firewall_settings"',
        [json_encode($firewallSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
    );
    $unrestrictedFragment = $service->generateFragment([]);
    assert_true(!str_contains($unrestrictedFragment, 'system_admin_allowed_cidrs'), 'An empty admin allowlist should remain unrestricted even when automatic local access is enabled.');
    assert_true(!str_contains($unrestrictedFragment, 'system_api_allowed_cidrs'), 'An empty API allowlist should remain unrestricted even when automatic local access is enabled.');
} finally {
    $previousCertificateIps === false
        ? putenv('TOKENHUB_CERTIFICATE_IPS')
        : putenv('TOKENHUB_CERTIFICATE_IPS=' . $previousCertificateIps);
    \TokenHub\Support\App::db()->execute('DELETE FROM system_settings WHERE setting_key = "firewall_settings"');
}

$bannedIp = '203.0.113.' . random_int(1, 200);
\TokenHub\Support\App::db()->execute(
    'INSERT INTO system_ip_bans (client_ip, failure_count, reason, banned_at, last_failed_at) VALUES (?, 5, "admin_login_bad_password", NOW(), NOW())',
    [$bannedIp]
);
try {
    $fragmentWithBan = $service->generateFragment([]);
    assert_true(str_contains($fragmentWithBan, 'system_login_password_fail_ban'), 'Generated WAF fragment should include a system login password failure ban rule.');
    assert_true(str_contains($fragmentWithBan, 'remote_ip ' . $bannedIp), 'Generated WAF fragment should block active banned IPs at the edge.');
    assert_true(str_contains($fragmentWithBan, 'vars waf_rule system_login_password_fail_ban'), 'System IP ban WAF block should annotate security logs.');
} finally {
    \TokenHub\Support\App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$bannedIp]);
}

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tokenhub-waf-' . bin2hex(random_bytes(6));
mkdir($tempRoot, 0775, true);
$fragmentFile = $tempRoot . DIRECTORY_SEPARATOR . 'waf-current.caddy';
$configFile = $tempRoot . DIRECTORY_SEPARATOR . 'Caddyfile';
file_put_contents($fragmentFile, "old fragment\n");
file_put_contents($configFile, "import {$fragmentFile}\n");

$commands = [];
$result = $service->installFragment("new fragment\n", [
    'fragment_path' => $fragmentFile,
    'config_path' => $configFile,
    'validate_command' => 'validate {config}',
    'reload_command' => 'reload {config}',
    'reload' => true,
], function (string $command) use (&$commands): array {
    $commands[] = $command;
    return str_starts_with($command, 'reload')
        ? ['ok' => false, 'output' => 'reload failed']
        : ['ok' => true, 'output' => 'valid'];
});

assert_same('failed', $result['status'], 'Reload failure should mark install result failed.');
assert_same("old fragment\n", file_get_contents($fragmentFile), 'Reload failure should restore previous fragment.');
assert_true($commands === ['validate ' . $configFile, 'reload ' . $configFile], 'Install should run validate before reload.');

file_put_contents($fragmentFile, "old fragment\n");
$validateResult = $service->installFragment("INVALID fragment\n", [
    'fragment_path' => $fragmentFile,
    'config_path' => $configFile,
    'validate_command' => 'validate {config}',
    'reload' => false,
], function (string $command) use ($fragmentFile): array {
    return str_contains((string) file_get_contents($fragmentFile), 'INVALID')
        ? ['ok' => false, 'output' => 'invalid candidate']
        : ['ok' => true, 'output' => 'valid'];
});

assert_same('failed', $validateResult['status'], 'Validation failure should mark install result failed.');
assert_same('validate_failed', $validateResult['error'], 'Validation failure should report validate_failed.');
assert_same("old fragment\n", file_get_contents($fragmentFile), 'Validation failure should restore previous fragment.');

$logFile = $tempRoot . DIRECTORY_SEPARATOR . 'caddy-security.jsonl';
$offsetFile = $tempRoot . DIRECTORY_SEPARATOR . 'caddy-security.offset';
file_put_contents($logFile, json_encode([
    'request_id' => 'req_waf_1',
    'request' => ['remote_ip' => '10.0.0.9', 'method' => 'GET', 'uri' => '/.env'],
    'vars' => ['waf_rule' => 'high_risk_path', 'waf_action' => 'block'],
    'status' => 403,
]) . "\n");

$firstImport = $service->securityEventsFromLog($logFile, $offsetFile);
$secondImport = $service->securityEventsFromLog($logFile, $offsetFile);

assert_same(1, count($firstImport), 'First security log import should parse one event.');
assert_same('high_risk_path', $firstImport[0]['rule_code'], 'Security log import should preserve waf_rule.');
assert_same('block', $firstImport[0]['action'], 'Security log import should preserve waf_action.');
assert_same(0, count($secondImport), 'Second security log import should use offset and avoid duplicate events.');

$uniqueRequest = 'req_waf_stats_' . bin2hex(random_bytes(4));
$eventRows = [
    [
        'rule_code' => 'sql_injection',
        'request_id' => $uniqueRequest . '_1',
        'client_ip' => '203.0.113.10',
        'method' => 'GET',
        'path' => '/v1/chat/completions?query=union%20select',
        'action' => 'block',
        'summary' => json_encode(['source' => 'test', 'status' => 403], JSON_UNESCAPED_SLASHES),
        'created_at' => '2026-07-08 01:00:00',
    ],
    [
        'rule_code' => 'path_traversal',
        'request_id' => $uniqueRequest . '_2',
        'client_ip' => '203.0.113.10',
        'method' => 'POST',
        'path' => '/download/../../etc/passwd',
        'action' => 'block',
        'summary' => json_encode(['source' => 'test', 'status' => 403], JSON_UNESCAPED_SLASHES),
        'created_at' => '2026-07-08 01:30:00',
    ],
    [
        'rule_code' => 'secret_query',
        'request_id' => $uniqueRequest . '_3',
        'client_ip' => '198.51.100.20',
        'method' => 'GET',
        'path' => '/admin-api/users?token=leaked',
        'action' => 'log',
        'summary' => json_encode(['source' => 'test', 'status' => 200], JSON_UNESCAPED_SLASHES),
        'created_at' => '2026-07-08 02:00:00',
    ],
    [
        'rule_code' => 'sql_injection',
        'request_id' => $uniqueRequest . '_4',
        'client_ip' => '203.0.113.10',
        'method' => 'GET',
        'path' => '/v1/chat/completions?query=union%20select',
        'action' => 'block',
        'summary' => json_encode(['source' => 'test', 'status' => 403], JSON_UNESCAPED_SLASHES),
        'created_at' => '2026-07-08 01:10:00',
    ],
];

foreach ($eventRows as $row) {
    \TokenHub\Support\App::db()->execute(
        'INSERT INTO waf_events (rule_code, request_id, client_ip, method, path, action, summary, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$row['rule_code'], $row['request_id'], $row['client_ip'], $row['method'], $row['path'], $row['action'], $row['summary'], $row['created_at']]
    );
}

try {
    $records = $service->events([
        'from' => '2026-07-08 00:00:00',
        'to' => '2026-07-08 03:00:00',
        'client_ip' => '203.0.113.10',
        'limit' => 10,
    ]);
    assert_same(3, count($records['records']), 'WAF event records should filter by client IP.');
    assert_same(3, $records['pagination']['total'], 'WAF event records should return a matching total.');
    assert_same('path_traversal', $records['records'][0]['rule_code'], 'WAF event records should sort newest first.');

    $statistics = $service->statistics([
        'from' => '2026-07-08 00:00:00',
        'to' => '2026-07-08 03:00:00',
    ]);
    assert_same(4, $statistics['summary']['total_events'], 'WAF statistics should count all matching events.');
    assert_same(3, $statistics['summary']['blocked_events'], 'WAF statistics should count blocked events.');
    assert_same(2, $statistics['summary']['unique_ips'], 'WAF statistics should count unique client IPs.');
    assert_same('203.0.113.10', $statistics['top_ips'][0]['client_ip'], 'WAF statistics should rank source IPs.');
    assert_same('sql_injection', $statistics['top_rules'][0]['rule_code'], 'WAF statistics should include rule rankings.');
    assert_true(count($statistics['trends']) >= 2, 'WAF statistics should include time buckets.');
    assert_true(isset($statistics['top_paths'][0]['path']), 'WAF statistics should include attacked path rankings.');
} finally {
    \TokenHub\Support\App::db()->execute('DELETE FROM waf_events WHERE request_id LIKE ?', [$uniqueRequest . '%']);
}

$dispositionRequest = 'req_waf_disposition_' . bin2hex(random_bytes(4));
\TokenHub\Support\App::db()->execute(
    'INSERT INTO waf_events (rule_code, request_id, client_ip, method, path, action, summary, created_at) VALUES ("disposition_rule", ?, "203.0.113.77", "GET", "/admin-api/users", "block", ?, NOW())',
    [$dispositionRequest, json_encode(['source' => 'test'], JSON_UNESCAPED_SLASHES)]
);
$dispositionEvent = \TokenHub\Support\App::db()->first('SELECT id FROM waf_events WHERE request_id = ?', [$dispositionRequest]);
try {
    $reviewed = $service->handleEvent((int) $dispositionEvent['id'], ['action' => 'review', 'note' => 'checked by security']);
    assert_same('reviewed', $reviewed['disposition_status'], 'WAF event review should mark the event reviewed.');
    assert_same('checked by security', $reviewed['disposition_note'], 'WAF event review should persist the operator note.');

    $falsePositive = $service->handleEvent((int) $dispositionEvent['id'], ['action' => 'false_positive', 'note' => 'internal scanner']);
    assert_same(1, (int) $falsePositive['false_positive'], 'WAF event false positive action should flag the event.');
    assert_same('false_positive', $falsePositive['disposition_status'], 'WAF event false positive action should set disposition status.');
} finally {
    \TokenHub\Support\App::db()->execute('DELETE FROM waf_events WHERE request_id = ?', [$dispositionRequest]);
}

$autoRequestPrefix = 'req_waf_auto_' . bin2hex(random_bytes(4));
$autoIp = '203.0.113.' . random_int(201, 250);
$autoBanService = new \TokenHub\Services\LoginIpBanService();
$autoBanService->reset($autoIp);
assert_true(!$autoBanService->isBanned($autoIp), 'WAF auto disposition test should prime the negative IP ban cache.');
for ($i = 1; $i <= 3; $i++) {
    \TokenHub\Support\App::db()->execute(
        'INSERT INTO waf_events (rule_code, request_id, client_ip, method, path, action, summary, created_at) VALUES ("auto_block_rule", ?, ?, "GET", "/.env", "block", ?, NOW())',
        [$autoRequestPrefix . '_' . $i, $autoIp, json_encode(['source' => 'test', 'status' => 403], JSON_UNESCAPED_SLASHES)]
    );
}
try {
    $autoDisposition = $service->autoDisposition(['threshold' => 3, 'window_minutes' => 60]);
    assert_true($autoDisposition['banned_count'] >= 1, 'WAF auto disposition should ban IPs above the block threshold.');
    assert_true(in_array($autoIp, array_column($autoDisposition['banned'], 'client_ip'), true), 'WAF auto disposition should include the banned source IP.');
    assert_true((new \TokenHub\Services\LoginIpBanService())->isBanned($autoIp), 'WAF auto disposition should write to system_ip_bans.');

    $rows = \TokenHub\Support\App::db()->select('SELECT disposition_status FROM waf_events WHERE request_id LIKE ? ORDER BY id ASC', [$autoRequestPrefix . '%']);
    assert_true($rows !== [] && count(array_filter($rows, static fn (array $row): bool => $row['disposition_status'] === 'auto_banned')) === 3, 'WAF auto disposition should mark matching events auto_banned.');
} finally {
    \TokenHub\Support\App::db()->execute('DELETE FROM waf_events WHERE request_id LIKE ?', [$autoRequestPrefix . '%']);
    $autoBanService->reset($autoIp);
}

$ingestRequestPrefix = 'req_waf_ingest_loop_' . bin2hex(random_bytes(4));
$ingestIp = '203.0.113.' . random_int(101, 180);
$ingestLogFile = $tempRoot . DIRECTORY_SEPARATOR . 'caddy-security-loop.jsonl';
$ingestOffsetFile = $tempRoot . DIRECTORY_SEPARATOR . 'caddy-security-loop.offset';
$previousAlertThresholds = \TokenHub\Support\App::db()->first('SELECT setting_value, description FROM system_settings WHERE setting_key = "alert_thresholds"');
$logLines = [];
for ($i = 1; $i <= 3; $i++) {
    $logLines[] = json_encode([
        'request_id' => $ingestRequestPrefix . '_' . $i,
        'request' => ['remote_ip' => $ingestIp, 'method' => 'GET', 'uri' => '/.env'],
        'vars' => ['waf_rule' => 'phase3_ingest_rule', 'waf_action' => 'block'],
        'status' => 403,
    ], JSON_UNESCAPED_SLASHES);
}
file_put_contents($ingestLogFile, implode("\n", $logLines) . "\n");
\TokenHub\Support\App::db()->execute(
    'INSERT INTO system_settings (setting_key, setting_value, description) VALUES ("alert_thresholds", ?, "test phase3 waf thresholds") ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)',
    [json_encode([
        'waf_spike_count' => 3,
        'waf_spike_window_minutes' => 60,
        'provider_error_rate' => 0.3,
        'provider_error_window_minutes' => 5,
        'provider_min_requests' => 5,
        'queue_backlog_count' => 1000,
        'certificate_threshold_days' => 14,
    ], JSON_UNESCAPED_SLASHES)]
);
try {
    $ingestResult = $service->ingestSecurityLog($ingestLogFile, ['offset_file' => $ingestOffsetFile]);
    assert_same(3, $ingestResult['imported'], 'WAF log import should import all new security events.');
    assert_true(isset($ingestResult['auto_disposition']), 'WAF log import should return automatic disposition details.');
    assert_same(3, $ingestResult['auto_disposition']['threshold'], 'WAF log import should use configured WAF threshold.');
    assert_same(60, $ingestResult['auto_disposition']['window_minutes'], 'WAF log import should use configured WAF window.');
    assert_true($ingestResult['auto_disposition']['banned_count'] >= 1, 'WAF log import should auto-ban repeated blocked source IPs.');
    assert_true((new \TokenHub\Services\LoginIpBanService())->isBanned($ingestIp), 'WAF log import should write auto-disposed IPs to system_ip_bans.');
} finally {
    \TokenHub\Support\App::db()->execute('DELETE FROM waf_events WHERE request_id LIKE ?', [$ingestRequestPrefix . '%']);
    \TokenHub\Support\App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$ingestIp]);
    \TokenHub\Support\App::db()->execute('DELETE FROM alert_events WHERE event_type IN ("waf_hit_spike", "waf_auto_disposition")');
    if ($previousAlertThresholds === null) {
        \TokenHub\Support\App::db()->execute('DELETE FROM system_settings WHERE setting_key = "alert_thresholds"');
    } else {
        \TokenHub\Support\App::db()->execute(
            'INSERT INTO system_settings (setting_key, setting_value, description) VALUES ("alert_thresholds", ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)',
            [$previousAlertThresholds['setting_value'], $previousAlertThresholds['description']]
        );
    }
}
