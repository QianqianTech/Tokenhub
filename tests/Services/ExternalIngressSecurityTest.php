<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ExternalRateLimitService;
use TokenHub\Services\TrustedProxyService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

$context = new RequestContext('req_ingress_security', 'trc_ingress_security');
$resolver = new TrustedProxyService(['172.18.0.0/16']);

$direct = new Request('GET', '/v1/me', [
    'REMOTE_ADDR' => '198.51.100.10',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 172.18.0.2',
], [], [], '', $context);
assert_same('198.51.100.10', $resolver->clientIp($direct), 'Direct callers must not control their client IP through X-Forwarded-For.');

$proxied = new Request('GET', '/v1/me', [
    'REMOTE_ADDR' => '172.18.0.2',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 172.18.0.2',
], [], [], '', $context);
assert_same('203.0.113.77', $resolver->clientIp($proxied), 'Configured trusted proxies may forward the original client IP.');

$limiter = new ExternalRateLimitService();
$identity = '198.51.100.' . random_int(20, 220);
$key = $limiter->counterKey('invalid-auth', $identity, 60, 2);
App::redis()->delete($key);

$first = $limiter->attempt('invalid-auth', $identity, 2, 60);
$second = $limiter->attempt('invalid-auth', $identity, 2, 60);
$third = $limiter->attempt('invalid-auth', $identity, 2, 60);

assert_same(true, $first['allowed'], 'The first request inside a rate window should be allowed.');
assert_same(true, $second['allowed'], 'The request at the configured limit should be allowed.');
assert_same(false, $third['allowed'], 'The request above the configured limit must be rejected atomically.');
assert_true((int) $third['retry_after'] > 0 && (int) $third['retry_after'] <= 60, 'A rejected request must expose a bounded retry delay.');

App::redis()->delete($key);
