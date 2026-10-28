<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$middleware = file_get_contents(__DIR__ . '/../../app/Middleware/ApiKeyAuthMiddleware.php') ?: '';
$gateway = file_get_contents(__DIR__ . '/../../app/Middleware/GatewayAuthMiddleware.php') ?: '';
$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';

assert_true(str_contains($middleware, 'final class ApiKeyAuthMiddleware'), 'Generic API key middleware should exist.');
assert_true(str_contains($middleware, 'authenticate($token, $clientIp, null)'), 'Generic API key middleware should authenticate without requiring a model.');
assert_true(str_contains($middleware, 'TrustedProxyService'), 'Generic API key middleware should resolve client IP through the trusted-proxy boundary.');
assert_true(str_contains($middleware, 'ExternalRateLimitService'), 'Generic API key middleware should enforce external API rate limits.');
assert_true(str_contains($gateway, 'new ApiKeyAuthMiddleware()'), 'Gateway middleware should reuse generic API key middleware.');
assert_true(str_contains($gateway, 'assertModelAllowed'), 'Gateway middleware should keep model permission enforcement.');

foreach ([
    "post('/v1/sync/devices'",
    "get('/v1/sync/manifest'",
    "get('/v1/sync/records'",
    "post('/v1/sync/records:batch'",
    "put('/v1/sync/records/{namespace}/{client_record_id}'",
    "delete('/v1/sync/records/{namespace}/{client_record_id}'",
] as $needle) {
    assert_true(str_contains($kernel, $needle), 'Kernel should register sync route containing ' . $needle);
}
