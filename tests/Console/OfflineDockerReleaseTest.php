<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);

$read = static function (string $path): string {
    $content = file_get_contents($path);
    assert_true(is_string($content), "Missing release artifact: {$path}");

    return $content;
};

$compose = $read($root . '/docker-compose.offline.yml');
$phpDockerfile = $read($root . '/docker/release/php/Dockerfile');
$caddyDockerfile = $read($root . '/docker/release/caddy/Dockerfile');
$ragDockerfile = $read($root . '/docker/release/rag-worker/Dockerfile');
$packager = $read($root . '/scripts/package-offline-release.ps1');
$windowsImporter = $read($root . '/scripts/import-offline-release.ps1');
$unixImporter = $read($root . '/scripts/import-offline-release.sh');

assert_true(str_contains($phpDockerfile, 'composer install --no-dev'), 'PHP image must install production Composer dependencies.');
assert_true(str_contains($phpDockerfile, 'docker-php-ext-install pdo_mysql gd zip'), 'PHP dependency stage must install PDO MySQL, GD and ZIP before Composer validates platform requirements.');
assert_true(str_contains($phpDockerfile, 'pecl install redis'), 'PHP dependency stage must install Redis before Composer validates platform requirements.');
assert_true(str_contains($phpDockerfile, 'COPY app ./app'), 'PHP image must contain PHP application code.');
assert_true(str_contains($caddyDockerfile, 'COPY public /srv/app/public'), 'Caddy image must contain public assets.');
assert_true(str_contains($ragDockerfile, 'COPY python-rag-worker/tokenhub_rag_worker'), 'RAG image must contain worker code.');
assert_true(!str_contains($compose, 'build:'), 'Offline Compose must not build on the target host.');
assert_true(!str_contains($compose, './:/srv/app'), 'Offline Compose must not bind mount application source.');
assert_true(!str_contains($compose, './public:/srv/app/public'), 'Offline Compose must not bind mount public assets.');
assert_true(str_contains($compose, 'tokenhub_storage:'), 'Offline Compose must use a named application storage volume.');
assert_true(str_contains($packager, 'linux/amd64') && str_contains($packager, 'linux/arm64'), 'Packager must export amd64 and arm64 images.');
assert_true(str_contains($packager, "Copy-Item (Join-Path \$Root '.env')"), 'Packager must copy the configured .env into the offline bundle.');
assert_true(!str_contains($packager, 'Offline release password'), 'Packager must not prompt for an offline release password.');
assert_true(!str_contains($packager, 'utf8NoBOM'), 'Packager must use an encoding supported by Windows PowerShell 5.1.');
assert_true(str_contains($packager, '& docker @Arguments | Out-Host'), 'Packager must not capture Docker command output as an image tag.');
assert_true(str_contains($windowsImporter, 'docker load') && str_contains($unixImporter, 'docker load'), 'Importers must load offline image archives.');
assert_true(str_contains($windowsImporter, 'ConfirmRestore'), 'Windows importer must require explicit restore confirmation.');
assert_true(str_contains($unixImporter, 'CONFIRM_RESTORE=1'), 'POSIX importer must require explicit restore confirmation.');
assert_true(str_contains($windowsImporter, "Copy-Item (Join-Path \$BundleRoot '.env')"), 'Windows importer must use the bundled .env.');
assert_true(str_contains($unixImporter, 'cp .env .runtime.env'), 'POSIX importer must use the bundled .env.');
