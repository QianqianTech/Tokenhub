<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// Webhook / alert deliveries connect to the address the outbound policy
// checked, not to whatever the host name resolves to at connect time.

use TokenHub\Services\PinnedHttpPost;

if (!function_exists('curl_init')) {
    echo "SKIP curl unavailable\n";
    return;
}
$dir = sys_get_temp_dir() . '/tokenhub-pinned-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/index.php', '<?php header("X-Seen-Host: " . ($_SERVER["HTTP_HOST"] ?? "")); if (($_GET["r"] ?? "") === "1") { header("Location: http://169.254.169.254/", true, 302); exit; } http_response_code(202); echo "host=" . ($_SERVER["HTTP_HOST"] ?? "") . ";body=" . file_get_contents("php://input");');
$port = random_int(20000, 40000);
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
try {
    usleep(300000);
    // The host name does not resolve at all: only the pinned address is used.
    $result = PinnedHttpPost::send('http://hooks.tokenhub.invalid:' . $port . '/', '127.0.0.1', '{"a":1}', ['Content-Type: application/json']);
    assert_same(202, $result['status'], 'The request reaches the pinned address.');
    assert_same('host=hooks.tokenhub.invalid:' . $port . ';body={"a":1}', $result['body'], 'The original Host header and body are sent.');
    $redirect = PinnedHttpPost::send('http://hooks.tokenhub.invalid:' . $port . '/?r=1', '127.0.0.1', '{}', []);
    assert_same(302, $redirect['status'], 'Redirects are reported, never followed.');
    $refused = PinnedHttpPost::send('http://hooks.tokenhub.invalid:' . $port . '/', '127.0.0.2', '{}', []);
    assert_same(0, $refused['status'], 'A different address is not tried.');
} finally {
    proc_terminate($process);
    @unlink($dir . '/index.php');
    @rmdir($dir);
}
$webhook = (string) file_get_contents(__DIR__ . '/../../app/Services/WebhookService.php');
assert_true(str_contains($webhook, 'OutboundUrlPolicy::PURPOSE_WEBHOOK') && str_contains($webhook, 'PinnedHttpPost::send('), 'Webhook deliveries re-check the URL and pin the address.');
echo "PASS pinned http post\n";
