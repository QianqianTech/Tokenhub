<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Bootstrap\Kernel;
use TokenHub\Support\Response;

unset($_COOKIE['tokenhub_admin_session']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/admin-api/system/brand-identity';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$response = Kernel::router()->dispatch('GET', '/admin-api/system/brand-identity');

assert_true($response instanceof Response, 'Admin public brand identity route should return a response.');
assert_same(200, $response->status, 'Admin public brand identity route should not require an admin session.');

$payload = json_decode($response->body, true);
assert_true(is_array($payload), 'Admin public brand identity response should be JSON.');
assert_same(true, $payload['ok'] ?? null, 'Admin public brand identity response should be successful.');
assert_true(isset($payload['data']['product_name']), 'Admin public brand identity should expose product_name.');
assert_true(isset($payload['data']['admin_title']), 'Admin public brand identity should expose admin_title.');
assert_true(array_key_exists('logo_url', $payload['data'] ?? []), 'Admin public brand identity should expose logo_url.');
assert_true(array_key_exists('favicon_url', $payload['data'] ?? []), 'Admin public brand identity should expose favicon_url.');
assert_true(!isset($payload['data']['sections']), 'Admin public brand identity must not expose the full settings center payload.');

