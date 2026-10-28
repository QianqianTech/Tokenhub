<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Middleware\ScreenAuthMiddleware;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;
use TokenHub\Support\Response;

unset($_COOKIE['screen_session']);

$localhostRequest = new Request('GET', '/screen-api/auth/me', [
    'HTTP_HOST' => 'localhost:8080',
    'REMOTE_ADDR' => '172.18.0.1',
], [], [], '', RequestContext::fromHeaders([]));

$localhostResponse = (new ScreenAuthMiddleware())($localhostRequest, static fn () => Response::ok());

assert_same(401, $localhostResponse->status, 'Localhost screen access without a share session should still require login.');

$remoteRequest = new Request('GET', '/screen-api/auth/me', [
    'HTTP_HOST' => 'example.com',
    'REMOTE_ADDR' => '203.0.113.10',
], [], [], '', RequestContext::fromHeaders([]));

$remoteResponse = (new ScreenAuthMiddleware())($remoteRequest, static fn () => Response::ok());

assert_same(401, $remoteResponse->status, 'Non-local screen access without a share session should still require login.');
