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
