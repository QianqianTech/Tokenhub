<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Bootstrap\Kernel;

// public/openapi.json (served at /v1/openapi.json) must describe exactly the
// API-key surface the router exposes: every /v1/* and /mcp route, and no
// operation that is not routed. SDK generators ignore x- extensions, so
// nothing may live only in an extension block.

$document = json_decode((string) file_get_contents(__DIR__ . '/../../public/openapi.json'), true);
assert_true(is_array($document), 'OpenAPI document should be valid JSON.');
assert_true(!isset($document['x-agent-management-contract']), 'Agent management operations belong in paths, not in an extension.');

$normalize = static fn (string $path): string => (string) preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\+?\}/', '{}', $path);

$routed = [];
foreach (Kernel::router()->routes() as $route) {
    if (str_starts_with($route['path'], '/v1/') || $route['path'] === '/mcp') {
        $routed[$route['method'] . ' ' . $normalize($route['path'])] = true;
    }
}
$documented = [];
foreach ($document['paths'] as $path => $operations) {
    foreach ($operations as $method => $operation) {
        if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
            $documented[strtoupper($method) . ' ' . $normalize((string) $path)] = $operation;
        }
    }
}

$missing = array_keys(array_diff_key($routed, $documented));
sort($missing);
assert_same([], $missing, 'Every routed /v1 and /mcp operation should be in public/openapi.json.');
$stale = array_keys(array_diff_key($documented, $routed));
sort($stale);
assert_same([], $stale, 'public/openapi.json should not document operations the router does not serve.');

foreach ($documented as $name => $operation) {
    assert_true(isset($operation['responses']) && $operation['responses'] !== [], $name . ' should declare responses.');
    if (str_contains($name, '/v1/management/')) {
        assert_true(isset($operation['x-required-scope']) && is_string($operation['x-required-scope']) && $operation['x-required-scope'] !== '', $name . ' should declare its management scope.');
    }
}

$scopes = $document['components']['schemas']['ManagementScope']['enum'] ?? [];
$service = file_get_contents(__DIR__ . '/../../app/Services/ApiKeyManagementScopeService.php') ?: '';
foreach ($scopes as $scope) {
    assert_true(str_contains($service, "'{$scope}'"), 'Documented management scope ' . $scope . ' should exist in ApiKeyManagementScopeService.');
}
preg_match_all("/^        '([a-z_]+:(?:read|write))',$/m", $service, $matches);
assert_same($matches[1], $scopes, 'ManagementScope enum should list exactly the scopes the service accepts, in order.');

$controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/ExternalController.php') ?: '';
assert_true(str_contains($controller, "x-tokenhub-release"), 'The served document should carry the installed release next to the contract version.');

// The served document must stay a valid OpenAPI document: empty schemas ({})
// must not turn into JSON arrays on the way out.
$served = (new \TokenHub\Http\Controllers\ExternalController())->openapi(new \TokenHub\Support\Request('GET', '/v1/openapi.json', [], [], [], '', \TokenHub\Support\RequestContext::fromHeaders([])));
assert_same(200, $served->status, 'OpenAPI document is served.');
$servedDocument = json_decode($served->body);
assert_true($servedDocument instanceof stdClass, 'Served OpenAPI is a JSON object.');
assert_true($servedDocument->components->schemas->ModerationRequest->properties->input->oneOf[1]->items instanceof stdClass, 'Empty schemas stay objects in the served document.');
assert_same(trim((string) file_get_contents(__DIR__ . '/../../VERSION')), $servedDocument->info->{'x-tokenhub-release'} ?? null, 'Served document names the installed release.');
