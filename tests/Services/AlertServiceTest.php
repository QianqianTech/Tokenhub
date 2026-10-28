<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AlertService;
use TokenHub\Support\App;

$events = [];
$service = new AlertService(function (array $event) use (&$events): void {
    $events[] = $event;
});

$service->record('provider_error_rate_high', 'warning', 'Provider error rate is high', [
    'provider_id' => 12,
    'window_minutes' => 5,
    'error_rate' => 0.35,
    'Authorization' => 'Bearer secret',
]);

assert_same(1, count($events), 'AlertService should persist alert events through the writer.');
assert_same('provider_error_rate_high', $events[0]['event_type'], 'Alert event type should be stored.');
assert_same('warning', $events[0]['severity'], 'Alert severity should be stored.');
assert_true(!str_contains(json_encode($events[0], JSON_UNESCAPED_SLASHES), 'Bearer secret'), 'Alert metadata should be sanitized.');

$certs = $service->certificateExpiryAlerts([
    ['name' => 'api.example.com', 'expires_at' => '2026-07-12 00:00:00'],
    ['name' => 'later.example.com', 'expires_at' => '2026-09-30 00:00:00'],
], strtotime('2026-07-08 00:00:00'), 14);
assert_same(1, count($certs), 'Certificate expiry evaluation should only alert for certificates inside threshold.');
assert_same('certificate_expiring', $certs[0]['event_type'], 'Certificate expiry alert should use certificate_expiring event type.');

$events = [];
$service = new AlertService(function (array $event) use (&$events): void {
    $events[] = $event;
});
$service->evaluateCertificateSettings([
    'certificates' => [
        ['name' => 'gateway.example.com', 'expires_at' => '2026-07-10 00:00:00'],
        ['name' => 'safe.example.com', 'expires_at' => '2026-08-31 00:00:00'],
    ],
    'threshold_days' => 14,
], strtotime('2026-07-08 00:00:00'));
assert_same(1, count($events), 'Alert evaluation should include certificate expiry settings.');
assert_same('certificate_expiring', $events[0]['event_type'], 'Certificate settings evaluation should record certificate_expiring.');

$previousAlertThresholds = App::db()->first('SELECT setting_value, description FROM system_settings WHERE setting_key = "alert_thresholds"');
$wafAlertPrefix = 'req_alert_waf_' . bin2hex(random_bytes(4));
for ($i = 1; $i <= 3; $i++) {
    App::db()->execute(
        'INSERT INTO waf_events (rule_code, request_id, client_ip, method, path, action, summary, created_at) VALUES ("alert_waf_rule", ?, "203.0.113.44", "GET", "/.env", "block", ?, NOW())',
        [$wafAlertPrefix . '_' . $i, json_encode(['source' => 'alert-test'], JSON_UNESCAPED_SLASHES)]
    );
}
App::db()->execute(
    'INSERT INTO system_settings (setting_key, setting_value, description) VALUES ("alert_thresholds", ?, "test alert waf thresholds") ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)',
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
    $events = [];
    $service = new AlertService(function (array $event) use (&$events): void {
        $events[] = $event;
    });
    $service->evaluate();
    $wafAlerts = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'waf_hit_spike'));
    assert_same(1, count($wafAlerts), 'WAF spike alert should use configured alert threshold.');
    assert_same(3, $wafAlerts[0]['metadata']['threshold'], 'WAF spike alert should include configured threshold.');
    assert_same(60, $wafAlerts[0]['metadata']['window_minutes'], 'WAF spike alert should include configured window.');
} finally {
    App::db()->execute('DELETE FROM waf_events WHERE request_id LIKE ?', [$wafAlertPrefix . '%']);
    if ($previousAlertThresholds === null) {
        App::db()->execute('DELETE FROM system_settings WHERE setting_key = "alert_thresholds"');
    } else {
        App::db()->execute(
            'INSERT INTO system_settings (setting_key, setting_value, description) VALUES ("alert_thresholds", ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)',
            [$previousAlertThresholds['setting_value'], $previousAlertThresholds['description']]
        );
    }
}
