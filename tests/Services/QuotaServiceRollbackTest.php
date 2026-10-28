<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\QuotaService;

$service = new QuotaService();

$keys = [
    ['key' => 'quota:1:api_key:7:requests:202607081234', 'amount' => 1],
    ['key' => 'quota:1:api_key:7:concurrency:active', 'amount' => 1],
];

assert_same($keys, $service->rollbackKeys($keys), 'Rollback keys should preserve tracked preflight increments.');

$exceeded = $service->firstExceededLimit([
    'request_limit' => 1,
], [
    'request_count' => 2,
]);
assert_same('QUOTA_EXCEEDED', $exceeded['code'], 'Request limit breach should return QUOTA_EXCEEDED.');
assert_same('request_limit', $exceeded['limit'], 'Exceeded request limit should be reported.');

