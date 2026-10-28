<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Middleware\CsrfMiddleware;
use TokenHub\Support\Config;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;
use TokenHub\Support\Response;

$root = dirname(__DIR__, 2);
$configSource = file_get_contents($root . '/config/app.php') ?: '';
$csrfSource = file_get_contents($root . '/app/Middleware/CsrfMiddleware.php') ?: '';
$kernelSource = file_get_contents($root . '/app/Bootstrap/Kernel.php') ?: '';
$adminControllerSource = file_get_contents($root . '/app/Http/Controllers/AdminController.php') ?: '';

assert_true(str_contains($configSource, "env('SESSION_SECURE'"), 'app config should expose SESSION_SECURE as app.session_secure.');
assert_true(str_contains($csrfSource, "Response::error('ADMIN_CSRF_INVALID', 'Invalid CSRF token', 419)"), 'CSRF middleware should return HTTP 419.');
assert_true(!str_contains($kernelSource, '/admin-api/waf'), 'Kernel should not expose WAF endpoints after the WAF feature was removed.');
assert_true(!str_contains($kernelSource, 'WafInspectionMiddleware') && !str_contains($kernelSource, 'SystemIpBanMiddleware'), 'Kernel should not register WAF or IP ban middleware.');
assert_true(str_contains($kernelSource, "/admin-api/system/brand-identity"), 'Kernel should expose public admin brand identity settings.');
assert_true(str_contains($kernelSource, "/admin-api/system/settings-center"), 'Kernel should expose the settings center endpoint.');
assert_true(str_contains($kernelSource, "/admin-api/system/settings-center/{section}"), 'Kernel should expose settings center section updates.');
assert_true(str_contains($kernelSource, "/admin-api/system/settings-center/certificate-alert-check"), 'Kernel should expose certificate alert checks.');
assert_true(str_contains($kernelSource, "/admin-api/providers/{id}/models"), 'Kernel should expose provider model sync endpoints.');
assert_true(str_contains($kernelSource, "/admin-api/system/certificate-request"), 'Kernel should expose certificate request endpoint.');
assert_true(str_contains($kernelSource, "/admin-api/system/network-interface-ips"), 'Kernel should expose server NIC IP discovery endpoint.');
assert_true(str_contains($adminControllerSource, 'brandIdentity'), 'Admin controller should expose public brand identity.');
assert_true(str_contains($adminControllerSource, 'settingsCenter'), 'Admin controller should expose the settings center.');
assert_true(str_contains($adminControllerSource, 'updateSettingsCenterSection'), 'Admin controller should update settings center sections.');
assert_true(str_contains($adminControllerSource, 'checkCertificateAlerts'), 'Admin controller should run certificate alert checks.');
assert_true(str_contains($adminControllerSource, 'requestCertificate'), 'Admin controller should request domain or IP certificates.');
assert_true(str_contains($adminControllerSource, 'networkInterfaceIps'), 'Admin controller should return server NIC IP candidates.');

$tmp = sys_get_temp_dir() . '/tokenhub-config-' . bin2hex(random_bytes(4));
mkdir($tmp . '/config', 0777, true);
copy($root . '/config/app.php', $tmp . '/config/app.php');
file_put_contents($tmp . '/.env', "SESSION_SECURE=false\n");
$config = new Config($tmp);
assert_same(false, $config->get('app.session_secure'), 'SESSION_SECURE=false should become boolean false.');

file_put_contents($tmp . '/.env', "SESSION_SECURE=true\n");
$config = new Config($tmp);
assert_same(true, $config->get('app.session_secure'), 'SESSION_SECURE=true should become boolean true.');

array_map('unlink', glob($tmp . '/config/*') ?: []);
rmdir($tmp . '/config');
unlink($tmp . '/.env');
rmdir($tmp);

$request = new Request('POST', '/admin-api/providers', [], [], [], '', RequestContext::fromHeaders([]));
$response = (new CsrfMiddleware())($request, fn () => Response::ok());
assert_same(419, $response->status, 'CSRF failure should return HTTP 419.');
