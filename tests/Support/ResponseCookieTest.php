<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\Response;

$response = Response::ok(['logged_in' => true])
    ->withCookie('tokenhub_admin_session', 'adm_test', time() + 3600, secure: false);

assert_true(isset($response->headers['Set-Cookie']), 'Response should expose a Set-Cookie header.');
assert_true(str_starts_with($response->headers['Set-Cookie'], 'tokenhub_admin_session=adm_test;'), 'Cookie header should contain the cookie name and value.');
assert_true(str_contains($response->headers['Set-Cookie'], 'Path=/'), 'Cookie header should contain Path=/.');
assert_true(str_contains($response->headers['Set-Cookie'], 'HttpOnly'), 'Cookie header should contain HttpOnly.');
assert_true(str_contains($response->headers['Set-Cookie'], 'SameSite=Strict'), 'Cookie header should contain SameSite=Strict.');
assert_true(!str_contains($response->headers['Set-Cookie'], 'Secure'), 'Cookie header should omit Secure when secure=false.');

$secureResponse = Response::ok()
    ->withCookie('tokenhub_admin_session', 'adm_test', time() + 3600, secure: true);
assert_true(str_contains($secureResponse->headers['Set-Cookie'], 'Secure'), 'Cookie header should include Secure when secure=true.');

