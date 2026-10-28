<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/AdminController.php') ?: '';
$operationsPath = __DIR__ . '/../../app/Services/AdminOperationsService.php';
$downloadsPath = __DIR__ . '/../../app/Services/AdminDownloadService.php';
$operations = is_file($operationsPath) ? (file_get_contents($operationsPath) ?: '') : '';
$downloads = is_file($downloadsPath) ? (file_get_contents($downloadsPath) ?: '') : '';
$downloadController = file_get_contents(__DIR__ . '/../../app/Http/Controllers/DownloadController.php') ?: '';

assert_true(str_contains($kernel, "'/admin-api/waf/overview'"), 'Admin routes should expose the WAF overview endpoint.');
assert_true(str_contains($kernel, "'/admin-api/waf/ip-bans'"), 'Admin routes should expose IP ban management endpoints.');
assert_true(str_contains($kernel, "'/admin-api/operations/status'"), 'Admin routes should expose read-only operations status.');
assert_true(str_contains($kernel, "'/admin-api/alerts/{id}/status'"), 'Admin routes should expose alert lifecycle actions.');
assert_true(str_contains($kernel, "'/admin-api/media-assets/{asset_id}/download-url'"), 'Admin routes should expose scoped media downloads.');
assert_true(str_contains($kernel, "'/admin-api/log-archive-files/{id}/download-url'"), 'Admin routes should expose scoped archive downloads.');
assert_true(str_contains($controller, 'requireSuperAdmin'), 'Control-plane actions should enforce super-admin authority in the controller.');
assert_true(str_contains($operations, 'function status'), 'Operations service should aggregate read-only health and queue status.');
assert_true(str_contains($downloads, 'createMediaDownload'), 'Admin download service should issue scoped media download URLs.');
assert_true(str_contains($downloads, 'createArchiveDownload'), 'Admin download service should issue scoped archive download URLs.');
assert_true(str_contains($downloadController, "'admin_media'"), 'Download controller should validate admin media download payloads.');
assert_true(str_contains($downloadController, "'admin_log_archive'"), 'Download controller should validate admin archive download payloads.');
