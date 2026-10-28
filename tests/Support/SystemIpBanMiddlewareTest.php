<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Middleware\SystemIpBanMiddleware;
use TokenHub\Services\LoginIpBanService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;
use TokenHub\Support\Response;

$service = new LoginIpBanService();
$clientIp = '203.0.113.' . random_int(1, 200);

try {
    App::db()->execute(
        'INSERT INTO system_ip_bans (client_ip, failure_count, reason, banned_at, last_failed_at) VALUES (?, 5, "admin_login_bad_password", NOW(), NOW())',
        [$clientIp]
    );

    $request = new Request('GET', '/health', ['REMOTE_ADDR' => $clientIp], [], [], '', RequestContext::fromHeaders([]));
    $response = (new SystemIpBanMiddleware())($request, fn () => Response::ok(['reachable' => true]));

    assert_same(403, $response->status, 'System IP ban middleware should reject banned IPs before route handlers.');
    assert_true(str_contains($response->body, 'SYSTEM_IP_BANNED'), 'System IP ban middleware should return a stable error code.');

    $service->reset($clientIp);
    $allowed = (new SystemIpBanMiddleware())($request, fn () => Response::ok(['reachable' => true]));
    assert_same(200, $allowed->status, 'System IP ban middleware should allow IPs after reset.');
} finally {
    App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$clientIp]);
}
