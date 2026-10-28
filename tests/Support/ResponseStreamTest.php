<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\Response;

$response = Response::stream(static function (): void {
    echo "data: first\n\n";
    echo "data: second\n\n";
}, 200, Response::sseHeaders());

ob_start();
$response->send();
$body = ob_get_clean();

assert_same("data: first\n\ndata: second\n\n", $body, 'Streaming response should execute writer during send.');
assert_same(200, $response->status, 'Streaming response should keep explicit status.');
assert_same('text/event-stream', $response->headers['Content-Type'], 'Streaming response should keep SSE headers.');

$withHeader = $response->withHeader('X-Request-Id', 'req_test');

ob_start();
$withHeader->send();
$withHeaderBody = ob_get_clean();

assert_same("data: first\n\ndata: second\n\n", $withHeaderBody, 'Streaming response should keep its writer after headers are appended.');
assert_same('req_test', $withHeader->headers['X-Request-Id'], 'Streaming response should keep appended headers.');
