<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    assert_true(is_string($content), 'Missing deployment artifact: ' . $relative);

    return $content;
};

// ---------------------------------------------------------------- PHP images
$devDockerfile = $read('docker/php/Dockerfile');
$releaseDockerfile = $read('docker/release/php/Dockerfile');
$extensionLine = static function (string $dockerfile): string {
    preg_match('/docker-php-ext-install ([^\n\\\\]+)/', $dockerfile, $m);

    return trim($m[1] ?? '');
};
assert_same('pdo_mysql gd zip opcache pcntl', $extensionLine($devDockerfile), 'Development image installs the extensions PhpSpreadsheet requires.');
assert_same($extensionLine($devDockerfile), $extensionLine($releaseDockerfile), 'Development and release images install the same extensions.');
foreach (['COPY public ./public', 'COPY composer.json VERSION .env.example ./', 'tokenhub-healthcheck', 'ARG PHP_VERSION='] as $needle) {
    assert_true(str_contains($releaseDockerfile, $needle), 'Release PHP image should include ' . $needle . '.');
}
assert_true(str_contains($read('docker/php/conf/zz-tokenhub-fpm.conf'), 'ping.path = /fpm-ping'), 'PHP-FPM exposes a ping endpoint for health checks.');
$releaseEntrypoint = $read('docker/release/php/entrypoint.sh');
assert_true(str_contains($releaseEntrypoint, 'bin/doctor.php --config'), 'The release entrypoint refuses unsafe production configuration.');
assert_true(str_contains($releaseEntrypoint, 'setpriv --reuid=www-data'), 'Workers run as www-data.');
assert_true(str_contains($read('docker/php/entrypoint.sh'), 'storage/caddy/tls-current.caddy'), 'The development entrypoint creates the Caddy TLS fragment.');

// ---------------------------------------------------------------- Caddy
$releaseCaddy = $read('docker/release/caddy/Caddyfile');
foreach (['{$TOKENHUB_SITE_ADDRESS::80}', '{$TOKENHUB_CADDY_TLS}', '{$TOKENHUB_CADDY_GLOBAL}', 'tls-current.caddy', '/caddy-health'] as $needle) {
    assert_true(str_contains($releaseCaddy, $needle), 'Release Caddyfile should support ' . $needle . '.');
}
$caddyEntrypoint = $read('docker/release/caddy/entrypoint.sh');
assert_true(str_contains($caddyEntrypoint, 'caddy reload') && str_contains($caddyEntrypoint, 'tls-current.caddy'), 'Caddy reloads certificate changes by itself in Docker.');
assert_true(str_contains($releaseCaddy, 'admin localhost:2019'), 'The Caddy admin API listens only inside the container.');
assert_true(!str_contains($read('deploy/docker/compose.yml'), '2019'), 'The Caddy admin API is never published.');
$exampleCaddy = $read('Caddyfile.example');
foreach (['{$TOKENHUB_SITE_ADDRESS:localhost}', '{$TOKENHUB_CADDY_TLS}', '{$TOKENHUB_APP_DIR:/var/www/tokenhub}'] as $needle) {
    assert_true(str_contains($exampleCaddy, $needle), 'Caddyfile.example should support ' . $needle . '.');
}

// ---------------------------------------------------------------- production compose
$compose = $read('deploy/docker/compose.yml');
assert_true(!str_contains($compose, 'build:'), 'Production compose never builds (compose.build.yml does).');
assert_true(!str_contains($compose, './:/srv/app'), 'Production compose does not bind mount source code.');
assert_true(!str_contains($compose, ':latest'), 'Production compose pins image versions.');
foreach ([
    'env_file: .env', 'bin/install.php', 'service_completed_successfully', 'tokenhub-healthcheck', 'bin/scheduler.php',
    '${TOKENHUB_IMAGE_PREFIX:-}tokenhub-php:', 'QDRANT__SERVICE__API_KEY', 'profiles: ["autoscaling"]', 'TOKENHUB_BIND_ADDRESS',
    'tokenhub_storage:', 'qdrant_snapshots:', 'max-size',
] as $needle) {
    assert_true(str_contains($compose, $needle), 'deploy/docker/compose.yml should include ' . $needle . '.');
}
assert_true(substr_count($compose, '- /var/run/docker.sock:') === 1, 'Only the optional autoscaler mounts the Docker socket.');
assert_true(str_contains($read('deploy/docker/compose.build.yml'), 'docker/release/php/Dockerfile'), 'compose.build.yml builds the release images.');

$dev = $read('docker-compose.yml');
assert_true(str_contains($dev, '${REDIS_IMAGE:-') && str_contains($dev, '${QDRANT_IMAGE:-'), 'Development compose lets images be pinned (it keeps existing dev volumes on their current version).');
assert_true(!str_contains($dev, '"php", "-v"'), 'Health checks test the service, not the PHP binary.');

// ---------------------------------------------------------------- installers for every platform
foreach (['deploy/docker/install.sh', 'deploy/docker/install.ps1', 'deploy/docker/upgrade.sh', 'deploy/docker/upgrade.ps1',
    'deploy/docker/backup.sh', 'deploy/docker/backup.ps1', 'deploy/docker/restore.sh', 'deploy/docker/verify.sh', 'deploy/docker/verify.ps1', 'deploy/linux/install.sh',
    'deploy/linux/upgrade.sh', 'bin/install.php', 'bin/doctor.php', 'bin/upgrade.php', 'bin/scheduler.php', 'bin/qdrant-snapshot.php',
    'scripts/release/build-images.sh', 'scripts/package-offline-release.sh', 'VERSION'] as $file) {
    assert_true(is_file($root . '/' . $file), $file . ' should exist.');
}
$linux = $read('deploy/linux/install.sh');
foreach (['apt-get', 'dnf', 'remi', 'ppa:ondrej/php', 'qdrant', 'tokenhub-worker@', 'tokenhub-scheduler', 'setsebool', 'bin/install.php" run'] as $needle) {
    assert_true(str_contains($linux, $needle), 'deploy/linux/install.sh should handle ' . $needle . '.');
}
assert_true(str_contains($linux, 'CentOS 7'), 'The source installer explains that CentOS 7 is not supported.');
foreach (['tokenhub-worker@.service', 'tokenhub-scheduler.service', 'tokenhub-rag-worker.service', 'qdrant.service'] as $unit) {
    assert_true(str_contains($read('deploy/linux/systemd/' . $unit), 'ExecStart='), $unit . ' should start a process.');
}
assert_true(str_contains($read('deploy/linux/systemd/tokenhub-worker@.service'), 'bin/queue-worker.php'), 'systemd uses the reliable queue worker.');

// ---------------------------------------------------------------- backups include every state store
$backup = $read('deploy/docker/backup.sh');
foreach (['mysqldump', 'qdrant-snapshot.php create', 'redis_data', 'tokenhub_storage', 'caddy_data', 'SHA256SUMS'] as $needle) {
    assert_true(str_contains($backup, $needle), 'Docker backup should include ' . $needle . '.');
}
$restore = $read('deploy/docker/restore.sh');
assert_true(str_contains($restore, 'qdrant-snapshot.php') && str_contains($restore, 'restore'), 'Docker restore recovers Qdrant collections.');
assert_true(str_contains($restore, 'CONFIRM_RESTORE'), 'Docker restore requires explicit confirmation.');
$nativeBackup = $read('scripts/backup-tokenhub.sh');
foreach (['collections.txt', 'snapshots/$snapshot', 'storage.tar.gz', 'redis-data.tar.gz'] as $needle) {
    assert_true(str_contains($nativeBackup, $needle), 'Source-install backup should include ' . $needle . '.');
}

// ---------------------------------------------------------------- repository hygiene
foreach (['wsl-admin-list.txt', 'wsl-admin-services.txt', 'wsl-feature-status.txt', 'containers-feature-enable.txt', 'docker-services-status.txt', 'docker-compose.offline.yml'] as $stale) {
    assert_true(!is_file($root . '/' . $stale), $stale . ' should not be in the repository.');
}
assert_true(!str_contains($read('scripts/admin-docker-deploy.ps1'), 'D:\\mysoft'), 'Helper scripts do not hard-code developer paths.');
