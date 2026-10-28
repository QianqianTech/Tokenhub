<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\Response;

$json = Response::json(['ok' => true], 201, ['X-Test' => 'yes']);
assert_same(201, $json->status, 'JSON response should keep status.');
assert_same('application/json; charset=utf-8', $json->headers['Content-Type'], 'JSON response should set content type.');
assert_true(str_contains($json->body, '"ok":true'), 'JSON body should encode payload.');

$error = Response::error('service_unavailable', 'Service unavailable', 503);
assert_same(503, $error->status, 'Error response should keep status.');
assert_true(str_contains($error->body, 'service_unavailable'), 'Error response should include code.');
assert_true(!str_contains($error->body, 'DB_PASSWORD'), 'Error response should not leak sensitive config names.');

$sseHeaders = Response::sseHeaders();
assert_same('text/event-stream', $sseHeaders['Content-Type'], 'SSE headers should use event stream content type.');
assert_same('no-cache', $sseHeaders['Cache-Control'], 'SSE headers should disable cache.');

