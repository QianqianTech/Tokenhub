<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\DashboardService;

$service = new DashboardService();

$allowed = $service->screenMetricAllowed([
    'allowed_metrics' => '["realtime","trends"]',
], 'trends');
assert_true($allowed, 'Screen metric should be allowed when listed.');

$blocked = $service->screenMetricAllowed([
    'allowed_metrics' => '["realtime"]',
], 'security');
assert_true(!$blocked, 'Screen metric should be blocked when not listed.');

$open = $service->screenMetricAllowed([
    'allowed_metrics' => null,
], 'security');
assert_true($open, 'Empty allowed_metrics should allow all metrics.');

