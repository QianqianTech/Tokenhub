<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);

$requiredFiles = [
    'docs/deployment/local.md',
    'docs/deployment/production.md',
    'docs/deployment/backup-restore.md',
    'docs/acceptance/final-acceptance-checklist.md',
    'docs/development-tasks/12-testing-deployment-acceptance-summary.md',
    'scripts/backup-tokenhub.sh',
    'scripts/restore-drill-check.sh',
];

foreach ($requiredFiles as $requiredFile) {
    assert_true(is_file($root . '/' . $requiredFile), $requiredFile . ' should exist.');
}

$pressure = file_get_contents($root . '/scripts/pressure-smoke.sh');
foreach (['CONCURRENCY', 'REQUESTS', 'STREAM_REQUESTS', 'TIMEOUT_SECONDS', 'check_async_logs', 'expect_auth_failure', 'ADMIN_PASSWORD', 'DASHBOARD_MAX_SECONDS', 'SSE_FIRST_BYTE_MAX_SECONDS', 'time_starttransfer', 'QUEUE_ALERT_THRESHOLD', 'check_caddy_lb_hint'] as $needle) {
    assert_true(str_contains($pressure, $needle), 'pressure-smoke.sh should include ' . $needle . '.');
}

$security = file_get_contents($root . '/scripts/security-smoke.sh');
foreach (['ADMIN_PASSWORD', 'content-security-policy', 'set-cookie', 'csrf', 'LOG_PATH', 'assert_secret_absent', 'EXPECT_WAF_QUERY_BLOCK', 'EXPECT_ADMIN_IP_BLOCK', 'X-Forwarded-For'] as $needle) {
    assert_true(str_contains($security, $needle), 'security-smoke.sh should include ' . $needle . '.');
}

$acceptance = file_get_contents($root . '/scripts/acceptance-check.sh');
foreach (['/v1/responses', '/v1/embeddings', '/v1/messages', '/screen/', 'stream', 'dashboard/realtime'] as $needle) {
    assert_true(str_contains($acceptance, $needle), 'acceptance-check.sh should cover ' . $needle . '.');
}

$caddyfile = file_get_contents($root . '/Caddyfile.example');
foreach (['TOKENHUB_PHP_FASTCGI', 'TOKENHUB_WAF_FRAGMENT', 'TOKENHUB_LOG_PATH', 'Strict-Transport-Security', 'request>headers>Authorization delete'] as $needle) {
    assert_true(str_contains($caddyfile, $needle), 'Caddyfile.example should include ' . $needle . '.');
}

$compose = file_get_contents($root . '/docker-compose.yml') ?: '';
$developmentCaddy = file_get_contents($root . '/docker/caddy/Caddyfile') ?: '';
$productionEnv = file_get_contents($root . '/deploy/ubuntu/.env.production.example') ?: '';
foreach ([
    '127.0.0.1:${CADDY_HTTP_PORT:-8080}:80',
    '127.0.0.1:${CADDY_HTTPS_PORT:-8443}:443',
    '127.0.0.1:${DB_FORWARD_PORT:-33060}:3306',
    '127.0.0.1:${REDIS_FORWARD_PORT:-63790}:6379',
    '127.0.0.1:${QDRANT_FORWARD_PORT:-63330}:6333',
    '--requirepass',
] as $needle) {
    assert_true(str_contains($compose, $needle), 'Development Compose should include ' . $needle . '.');
}
foreach (['-X-Powered-By', 'request_body', 'request>uri delete'] as $needle) {
    assert_true(str_contains($developmentCaddy, $needle), 'Development Caddy should include ' . $needle . '.');
}
assert_true(str_contains($caddyfile, '-X-Powered-By'), 'Production Caddy should hide PHP version headers.');
foreach (['APP_URL=https://api.example.com', 'SESSION_SECURE=true', 'REDIS_PASSWORD=change-me', 'TRUSTED_PROXY_CIDRS='] as $needle) {
    assert_true(str_contains($productionEnv, $needle), 'Production environment example should include ' . $needle . '.');
}
$productionGuide = file_get_contents($root . '/docs/deployment/production.md') ?: '';
assert_true(str_contains($productionGuide, 'deploy/ubuntu/.env.production.example'), 'Production guide should install the production environment template.');

$backup = file_get_contents($root . '/scripts/backup-tokenhub.sh');
foreach (['DRY_RUN="${DRY_RUN:-1}"', 'CONFIRM_BACKUP', 'DRY-RUN'] as $needle) {
    assert_true(str_contains($backup, $needle), 'backup-tokenhub.sh should include ' . $needle . '.');
}

$task12 = file_get_contents($root . '/docs/development-tasks/12-testing-deployment-acceptance.md');
assert_true(!str_contains($task12, '- [ ]'), '12-testing-deployment-acceptance.md should not contain unfinished checklist boxes after completion.');
assert_true(str_contains($task12, '完成记录'), '12-testing-deployment-acceptance.md should include completion evidence.');
