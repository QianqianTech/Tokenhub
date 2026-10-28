<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/app/Support/helpers.php';
spl_autoload_register(function (string $class) use ($basePath): void {
    $prefix = 'TokenHub\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $basePath . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$check = function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tests = [];
$tests['router matches path params'] = function () use ($check): void {
    $router = new TokenHub\Support\Router();
    $router->get('/items/{id}', fn ($request) => TokenHub\Support\Response::json(['id' => $request->params['id']]));
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/items/abc';
    ob_start();
    $router->dispatch('GET', '/items/abc')->send();
    $body = ob_get_clean();
    $check(str_contains($body, '"id":"abc"'), 'Expected route param in response body');
};
$tests['logger sanitizes sensitive fields'] = function () use ($check): void {
    $logger = new TokenHub\Support\Logger(sys_get_temp_dir());
    $clean = $logger->sanitize(['Authorization' => 'Bearer secret', 'nested' => ['api_key' => 'tkh_secret'], 'safe' => 'ok']);
    $check($clean['Authorization'] === '****', 'Authorization was not redacted');
    $check($clean['nested']['api_key'] === '****', 'Nested api_key was not redacted');
    $check($clean['safe'] === 'ok', 'Safe value was changed');
};
$tests['desensitization redacts nested secrets'] = function () use ($check): void {
    $service = new TokenHub\Services\DesensitizationService();
    $clean = $service->redact(['token' => 'abc', 'nested' => ['email' => 'leo@example.com', 'safe' => 'ok']]);
    $check($clean['token'] === '****', 'Token key was not redacted');
    $check($clean['nested']['email'] === '****', 'Email value was not redacted');
    $check($clean['nested']['safe'] === 'ok', 'Safe nested value was changed');
};
$tests['provider client maps compatible endpoints'] = function () use ($check): void {
    $client = new TokenHub\Services\ProviderHttpClient();
    $check($client->endpointFor('/v1/chat/completions') === '/v1/chat/completions', 'Chat endpoint mismatch');
    $check($client->endpointFor('/v1/messages') === '/v1/messages', 'Anthropic endpoint mismatch');
    $check($client->endpointFor('/v1/assets/abc/download-url') === '/v1/assets/abc/download-url', 'Unknown endpoint should pass through');
};
$tests['permission service checks department descendants by path'] = function () use ($check): void {
    $service = new TokenHub\Services\PermissionService();
    $actor = ['role' => 'super_admin', 'department_id' => 2, 'managed_department_ids' => '[2]'];
    $check($service->canAccessDepartmentPath($actor, '/1/2/9/'), 'Super admin should access descendant department path without database lookup.');
};
$tests['api key cidr matching works'] = function () use ($check): void {
    $service = new TokenHub\Services\ApiKeyService();
    $check($service->ipAllowed('10.1.2.3', ['10.0.0.0/8']), 'CIDR should allow 10.1.2.3');
    $check(!$service->ipAllowed('192.168.1.5', ['10.0.0.0/8']), 'CIDR should reject 192.168.1.5');
};
$tests['request client ip uses first forwarded address'] = function () use ($check): void {
    $request = new TokenHub\Support\Request('GET', '/', [
        'HTTP_X_FORWARDED_FOR' => '10.1.2.3, 172.18.0.2',
        'REMOTE_ADDR' => '172.18.0.2',
    ], [], [], '', new TokenHub\Support\RequestContext('test-request', 'test-trace'));
    $check($request->clientIp() === '172.18.0.2', 'An untrusted peer must not control the client IP through X-Forwarded-For.');
};
$tests['crypto hash is deterministic and encrypted text is reversible'] = function () use ($check): void {
    $_ENV['APP_KEY'] = 'test-key-for-tokenhub';
    $hashA = TokenHub\Support\Crypto::hashKey('abc');
    $hashB = TokenHub\Support\Crypto::hashKey('abc');
    $check($hashA === $hashB, 'Hash is not deterministic');
    $encrypted = TokenHub\Support\Crypto::encrypt('secret');
    $check(TokenHub\Support\Crypto::decrypt($encrypted) === 'secret', 'Encrypted text did not decrypt');
};

$failed = 0;
$messages = [];
foreach ($tests as $name => $test) {
    try {
        $test();
        $messages[] = "PASS {$name}";
    } catch (Throwable $e) {
        $failed++;
        $messages[] = "FAIL {$name}: {$e->getMessage()}";
    }
}

foreach (glob(__DIR__ . '/*/*Test.php') ?: [] as $file) {
    ob_start();
    try {
        require $file;
        ob_end_clean();
        $messages[] = "PASS " . str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $file);
    } catch (Throwable $e) {
        ob_end_clean();
        $failed++;
        $messages[] = "FAIL " . str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $file) . ": {$e->getMessage()}";
    }
}

foreach ($messages as $message) {
    echo $message . "\n";
}

if ($failed > 0) {
    exit(1);
}

echo "All tests passed\n";
