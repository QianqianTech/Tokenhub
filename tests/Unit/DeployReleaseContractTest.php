<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Console\Install\Doctor;
use TokenHub\Support\Config;
use TokenHub\Support\RedisClient;

// Release / deployment hardening: doctor checks (SEC-111, SEC-114, DEP-108),
// release images (SEC-008, DEP-101, APP-106), Caddy (APP-101, SEC-113),
// compose (DEP-105, DEP-109), scripts (DEP-104, DEP-106, DEP-107, SEC-007).
$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

// ------------------------------------------------------------ doctor: config
$good = [
    'env' => 'production', 'debug' => false, 'key' => str_repeat('k', 48), 'url' => 'https://tokenhub.example.com',
    'csrf_secret' => str_repeat('c', 32), 'session_secure' => true, 'db_password' => 'Xy7pQ2rT9wZk', 'redis_password' => 'Rr5tY8uI0oPl',
    'vector_store' => 'qdrant', 'qdrant_url' => 'http://qdrant:6333', 'qdrant_api_key' => '', 'paths' => [],
    'initial_admin_password' => '', 'realtime_token' => str_repeat('r', 48), 'trusted_proxy_cidrs' => ['127.0.0.1/32'],
];
assert_true(!Doctor::hasFailures(Doctor::configChecks($good)), 'A complete production configuration passes.');
foreach ([
    'realtime_token' => 'change-me',
    'qdrant_api_key' => 'change-me',
    'initial_admin_password' => 'change-me',
    'db_password' => 'tokenhub_secret',
    'redis_password' => 'root_secret',
] as $field => $value) {
    assert_true(Doctor::hasFailures(Doctor::configChecks([...$good, $field => $value])), 'Production refuses the public default for ' . $field . '.');
    assert_true(!Doctor::hasFailures(Doctor::configChecks([...$good, 'env' => 'local', $field => $value])), 'Development only warns about ' . $field . '.');
}
assert_true(Doctor::hasFailures(Doctor::configChecks([...$good, 'session_secure' => false])), 'Production with an https APP_URL requires SESSION_SECURE=true.');
assert_true(!Doctor::hasFailures(Doctor::configChecks([...$good, 'url' => 'http://10.0.0.8', 'session_secure' => false])), 'Plain HTTP intranet access still works.');

$levels = static fn (array $results): array => array_column($results, 'level');
assert_same(['warn'], $levels(Doctor::trustedProxyChecks(['127.0.0.1/32', '0.0.0.0/0'])), 'A catch-all trusted proxy range is flagged.');
assert_same(['warn'], $levels(Doctor::trustedProxyChecks(['10.0.0.0/33'])), 'An invalid CIDR is flagged.');
assert_same(['ok'], $levels(Doctor::trustedProxyChecks(['127.0.0.1/32', '::1/128', '172.18.0.0/16'])), 'A normal list passes.');
assert_true(Doctor::cidrContains('172.18.0.0/16', '172.18.3.4') && !Doctor::cidrContains('172.18.0.0/16', '172.19.0.1'), 'CIDR matching.');
assert_true(Doctor::cidrContains('fd00::/8', 'fd12::1') && !Doctor::cidrContains('fd00::/8', '10.0.0.1'), 'CIDR matching (IPv6, mixed family).');

assert_same(null, Doctor::clientAddressResult(array_fill(0, 10, '172.18.0.1'), []), 'Too little traffic: no verdict.');
assert_same('warn', Doctor::clientAddressResult(array_fill(0, 60, '172.18.0.1'), [])['level'] ?? null, 'All traffic from one private address is suspicious.');
assert_same('ok', Doctor::clientAddressResult([...array_fill(0, 59, '203.0.113.9'), '198.51.100.7'], [])['level'] ?? null, 'Several client addresses are fine.');
assert_same('ok', Doctor::clientAddressResult(array_fill(0, 60, '203.0.113.9'), [])['level'] ?? null, 'One public client (a single integration) is fine.');

// ---------------------------------------------------------- doctor: services
$redis = new RedisClient(new Config($root . '/config'));
$calls = [];
$fakeHttp = static function (string $url, int $timeout) use (&$calls): array {
    $calls[] = $url;
    return str_contains($url, 'gateway') ? [503, ''] : [0, 'Could not resolve host'];
};
$tmpKey = tempnam(sys_get_temp_dir(), 'apns');
$results = Doctor::serviceChecks([
    'vector_store' => '',
    'realtime_health_url' => 'http://realtime-gateway:8090/healthz',
    'apns_key_path' => $tmpKey,
    'fcm_service_account_path' => '/nonexistent/fcm.json',
    'outbound_check_url' => 'https://api.example.com/v1/chat',
], null, $redis, $fakeHttp);
unlink($tmpKey);
$byCheck = array_column($results, 'level', 'check');
assert_same('warn', $byCheck['Realtime gateway'] ?? null, 'An unhealthy realtime gateway is a warning.');
assert_same('ok', $byCheck['APNs key (APNS_PRIVATE_KEY_PATH)'] ?? null, 'A readable APNs key passes.');
assert_same('warn', $byCheck['FCM account (FCM_SERVICE_ACCOUNT_PATH)'] ?? null, 'A missing FCM file is a warning.');
assert_same('warn', $byCheck['Outbound HTTPS'] ?? null, 'Unreachable outbound HTTPS is a warning.');
assert_true(in_array('https://api.example.com/', $calls, true), 'The outbound probe targets the origin only.');
assert_true(!Doctor::hasFailures($results), 'Service checks never fail the self-check.');

// ------------------------------------------------------------ release images
$php = $read('docker/release/php/Dockerfile');
$runtime = substr($php, (int) strpos($php, 'AS runtime'));
assert_true(str_contains($php, 'FROM php-base AS dependencies') && str_contains($php, 'FROM php-base AS runtime'), 'The PHP image is multi-stage.');
assert_true(!preg_match('/composer-bin|apt-get|\bgit\b|\bunzip\b/', $runtime), 'The runtime stage installs no composer, git or unzip.');
$base = substr($php, (int) strpos($php, 'AS php-base'), (int) strpos($php, 'AS dependencies') - (int) strpos($php, 'AS php-base'));
assert_true(!preg_match('/\b(git|unzip)\b/', $base) && !str_contains($base, '/usr/bin/composer'), 'The shared base stage has no git, unzip or composer.');
assert_true(str_contains($php, 'ARG PHP_BASE_IMAGE=') && str_contains($php, 'ARG PHPREDIS_VERSION='), 'Base image and phpredis are pinned through build arguments.');
foreach (['docker/release/caddy/Dockerfile' => 'CADDY_BASE_IMAGE', 'docker/release/rag-worker/Dockerfile' => 'PYTHON_BASE_IMAGE', 'docker/release/worker-autoscaler/Dockerfile' => 'DOCKER_CLI_BASE_IMAGE', 'docker/release/realtime-gateway/Dockerfile' => 'GO_IMAGE'] as $file => $arg) {
    assert_true(str_contains($read($file), 'ARG ' . $arg . '='), $file . ' takes its base image as ' . $arg . '.');
}
$build = $read('scripts/release/build-images.sh');
foreach (['PHP_BASE_IMAGE', 'COMPOSER_IMAGE', 'CADDY_BASE_IMAGE', 'PYTHON_BASE_IMAGE', 'DOCKER_CLI_BASE_IMAGE', 'GO_IMAGE', 'RUNTIME_IMAGE', 'TOKENHUB_APP_BUILD', 'base-images.lock'] as $needle) {
    assert_true(str_contains($build, $needle), 'build-images.sh passes ' . $needle . '.');
}
$lock = $read('docker/release/base-images.lock');
foreach (['php:8.4-fpm-bookworm', 'composer:2', 'caddy:2.10', 'python:3.12-slim', 'docker:27-cli', 'golang:1.24-alpine', 'alpine:3.20'] as $ref) {
    assert_true(preg_match('/^' . preg_quote($ref, '/') . '(\s|$)/m', $lock) === 1, 'base-images.lock lists ' . $ref . '.');
}
assert_true(str_contains($php, 'stamp-app-version.sh') && str_contains($read('docker/release/caddy/Dockerfile'), 'stamp-app-version.sh'), 'Both images that serve /app/ stamp the service worker.');
assert_true(str_contains($read('public/app/sw.js'), '"__TOKENHUB_APP_BUILD__"'), 'sw.js carries the build placeholder.');

// Stamp a copy of the APP and check the cache name.
$tmp = sys_get_temp_dir() . '/th-stamp-' . bin2hex(random_bytes(4));
mkdir($tmp . '/public/app', 0777, true);
copy($root . '/public/app/sw.js', $tmp . '/public/app/sw.js');
file_put_contents($tmp . '/VERSION', "9.9.9\n");
exec('sh ' . escapeshellarg($root . '/scripts/release/stamp-app-version.sh') . ' ' . escapeshellarg($tmp) . ' "9.9.9-abc/def" 2>&1', $out, $code);
$stamped = (string) file_get_contents($tmp . '/public/app/sw.js');
assert_same(0, $code, 'stamp-app-version.sh succeeds: ' . implode(' ', $out));
assert_true(str_contains($stamped, 'const BUILD = "9.9.9-abc-def";'), 'The build id is stamped with unsafe characters replaced.');
array_map('unlink', [$tmp . '/public/app/sw.js', $tmp . '/VERSION']);
rmdir($tmp . '/public/app');
rmdir($tmp . '/public');
rmdir($tmp);

// ------------------------------------------------------------------ Caddy
foreach (['docker/caddy/Caddyfile', 'docker/release/caddy/Caddyfile', 'Caddyfile.example'] as $file) {
    $caddy = $read($file);
    assert_true(str_contains($caddy, 'camera=()') && str_contains($caddy, 'header Permissions-Policy "geolocation=(), microphone=(), camera=(self)"'), $file . ' allows the camera on /app/ only.');
    assert_true(str_contains($caddy, 'Strict-Transport-Security'), $file . ' sends HSTS over TLS.');
}

// ---------------------------------------------------------------- compose
$compose = $read('deploy/docker/compose.yml');
foreach (['x-proxy-env: &proxy-env', 'HTTPS_PROXY: ${HTTPS_PROXY:-}', 'http_proxy: ${HTTP_PROXY:-}', 'NO_PROXY: "localhost,127.0.0.1,::1,mysql,redis,qdrant,caddy', 'MYSQL_MEMORY_LIMIT', 'QDRANT_MEMORY_LIMIT', 'RAG_WORKER_MEMORY_LIMIT', 'PHP_MEMORY_LIMIT', 'REALTIME_GATEWAY_HEALTH_URL'] as $needle) {
    assert_true(str_contains($compose, $needle), 'compose.yml should contain ' . $needle . '.');
}
assert_true(substr_count($compose, '<<: *proxy-env') >= 5, 'Every service with outbound traffic gets the proxy variables.');

// ---------------------------------------------------------------- scripts
$upgrade = $read('deploy/docker/upgrade.sh');
foreach (['trap rollback EXIT', 'env_set TOKENHUB_IMAGE_TAG "$PREVIOUS_TAG"', 'restore.sh', 'show_changelog', '--no-rollback'] as $needle) {
    assert_true(str_contains($upgrade, $needle), 'upgrade.sh should contain ' . $needle . '.');
}
assert_true(str_contains($read('deploy/docker/upgrade.ps1'), 'Rolling back the image tag'), 'upgrade.ps1 rolls back too.');
assert_true(str_contains($read('deploy/docker/install.sh'), 'tokenhub-rag-worker tokenhub-realtime-gateway; do'), 'install.sh checks the realtime gateway image.');
assert_true(str_contains($read('deploy/docker/install.sh'), 'TRUSTED_PROXY_CIDRS'), 'install.sh writes the Docker network into TRUSTED_PROXY_CIDRS.');
assert_true(str_contains($read('scripts/package-offline-release.sh'), 'shasum -a 256'), 'Offline packaging works without sha256sum (macOS).');
$lib = $read('deploy/docker/lib.sh');
assert_true(str_contains($lib, 'backup_root()') && str_contains($lib, '/var/backups/tokenhub') && str_contains($read('deploy/docker/backup.sh'), 'BACKUP_ROOT="$(backup_root)"'), 'Backups default outside the source tree.');
$ignore = $read('.dockerignore');
foreach (['Claude outputs', 'native', 'dist', 'deploy/docker/backups', '**/*.pptx', '**/*.docx'] as $needle) {
    assert_true(preg_match('/^' . preg_quote($needle, '/') . '$/m', $ignore) === 1, '.dockerignore excludes ' . $needle . '.');
}
foreach (['deploy/docker/upgrade.sh', 'scripts/release/pin-base-images.sh', 'scripts/release/stamp-app-version.sh', 'scripts/release/build-images.sh', 'scripts/package-offline-release.sh', 'deploy/docker/install.sh', 'deploy/docker/backup.sh', 'deploy/linux/install.sh'] as $script) {
    exec('bash -n ' . escapeshellarg($root . '/' . $script) . ' 2>&1', $syntax, $status);
    assert_same(0, $status, $script . ' has valid shell syntax.');
}

// --------------------------------------------------------------------- CI
$release = $read('.github/workflows/release.yml');
foreach (['tags: ["v*"]', 'build-images.sh', '--push', 'package-offline-release.sh', 'SHA256SUMS', 'linux/arm64', 'setup-qemu-action', 'gh release create'] as $needle) {
    assert_true(str_contains($release, $needle), 'release.yml should contain ' . $needle . '.');
}
$tests = $read('.github/workflows/tests.yml');
foreach (['php: ["8.2", "8.3", "8.4"]', 'tests/Services/App*Test.php', 'python -m pytest', 'composer audit', 'pip-audit', 'continue-on-error: false'] as $needle) {
    assert_true(str_contains($tests, $needle), 'tests.yml should contain ' . $needle . '.');
}
assert_true(!str_contains($tests, "if: github.event_name == 'workflow_dispatch'"), 'The integration job is no longer manual-only.');
assert_true(preg_match('/^## \[' . preg_quote(trim($read('VERSION')), '/') . '\] - \d{4}-\d{2}-\d{2}$/m', $read('CHANGELOG.md')) === 1, 'CHANGELOG.md has a section for VERSION.');

echo "OK\n";
