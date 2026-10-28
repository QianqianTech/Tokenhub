<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\Mcp\McpServer;
use TokenHub\Services\Mcp\McpToolRegistry;
use TokenHub\Services\Mcp\TokenHubMcpTools;

// MCP server protocol behaviour with fake backends (no MySQL/Qdrant needed).

$calls = [];
$observed = [];
$backends = [
    'search' => static function (array $key, string $query, array $options) use (&$calls): array {
        $calls[] = ['search', $key['id'], $query, $options];
        if ($query === 'forbidden') {
            abort_json('RAG_KB_FORBIDDEN', 'Knowledge base permission denied', 403);
        }
        if ($query === 'explode') {
            throw new RuntimeException('qdrant down');
        }
        if ($query === 'nothing') {
            return ['retrieval_id' => 'rtr_empty', 'dataset_count' => 2, 'sources' => []];
        }
        return ['retrieval_id' => 'rtr_1', 'dataset_count' => 1, 'sources' => [[
            'source_id' => 'src_1', 'title' => '报销制度', 'content' => str_repeat('差旅', 4000), 'score' => 0.912345,
            'kb_id' => 'kb_finance', 'dataset_id' => 'ds_1', 'dataset_name' => '财务制度', 'source_uri' => 'policy.pdf#p3', 'modality' => 'text',
        ]]];
    },
    'knowledge_bases' => static fn (array $key): array => ['knowledge_bases' => [['kb_id' => 'kb_finance', 'name' => '财务']]],
    'datasets' => static fn (array $key, string $kbId): array => ['kb_id' => $kbId, 'datasets' => []],
    'models' => static fn (array $key): array => ['object' => 'list', 'data' => [['id' => 'gpt-4o', 'capability' => 'text_generation']]],
    'quota' => static fn (array $key): array => ['policies' => [], 'per_model' => []],
    'usage' => static function (array $key, array $query) use (&$calls): array {
        $calls[] = ['usage', $query];
        return ['totals' => ['request_count' => 3]];
    },
    'me' => static fn (array $key): array => ['api_key' => ['id' => $key['id']]],
];
$registry = new McpToolRegistry(static function (string $tool, bool $isError, int $latencyMs, ?Throwable $failure) use (&$observed): void {
    $observed[] = [$tool, $isError, $failure?->getMessage()];
});
(new TokenHubMcpTools(['id' => 7, 'user_id' => 3, 'department_id' => 2], $backends))->registerInto($registry);
$protocolErrors = [];
$server = new McpServer($registry, '9.9.9', static function (Throwable $t, string $method) use (&$protocolErrors): void {
    $protocolErrors[] = $method . ': ' . $t->getMessage();
});

$rpc = static fn (string $method, mixed $params = null, string|int|null $id = 1): string => json_encode(
    array_filter(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], static fn ($v) => $v !== null),
    JSON_UNESCAPED_UNICODE
);
$send = static function (string $body, ?string $version = null) use ($server): array {
    $result = $server->handle($body, $version);
    // Round-trip through JSON exactly as the controller sends it.
    $result['json'] = $result['body'] === null ? null : json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $result['decoded'] = $result['json'] === null ? null : json_decode($result['json'], true);
    return $result;
};

// initialize: version negotiation and capabilities
$init = $send($rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]));
assert_same(200, $init['status'], 'initialize returns 200.');
assert_same('2025-06-18', $init['decoded']['result']['protocolVersion'], 'A supported client version is echoed.');
assert_same('tokenhub', $init['decoded']['result']['serverInfo']['name'], 'serverInfo names TokenHub.');
assert_same('9.9.9', $init['decoded']['result']['serverInfo']['version'], 'serverInfo carries the app version.');
assert_same(['listChanged' => false], $init['decoded']['result']['capabilities']['tools'], 'Tools capability is declared.');
$future = $send($rpc('initialize', ['protocolVersion' => '2099-01-01']));
assert_same(McpServer::SUPPORTED_VERSIONS[0], $future['decoded']['result']['protocolVersion'], 'Unknown versions get the newest supported version.');
assert_same(400, $send($rpc('ping'), '1999-01-01')['status'], 'An unsupported MCP-Protocol-Version header is rejected with 400.');

// notifications and client responses are acknowledged with 202 and no body
$notification = $send(json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
assert_same(202, $notification['status'], 'Notifications get 202.');
assert_same(null, $notification['body'], 'Notifications get no body.');
assert_same(202, $send(json_encode(['jsonrpc' => '2.0', 'id' => 5, 'result' => new stdClass()]))['status'], 'Client responses are accepted.');

// ping returns an empty JSON object, not an array
$ping = $send($rpc('ping', null, 'p-1'));
assert_true(str_contains($ping['json'], '"result":{}'), 'ping result must serialize as {}.');
assert_same('p-1', $ping['decoded']['id'], 'String ids are preserved.');

// protocol errors
assert_same(McpServer::PARSE_ERROR, $send('{not json')['decoded']['error']['code'], 'Malformed JSON is a parse error.');
assert_same(400, $send('{not json')['status'], 'Parse errors use HTTP 400.');
assert_same(McpServer::METHOD_NOT_FOUND, $send($rpc('resources/list'))['decoded']['error']['code'], 'Undeclared methods are not found.');
assert_same(McpServer::INVALID_REQUEST, $send(json_encode(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping']))['decoded']['error']['code'], 'jsonrpc must be 2.0.');
assert_same(McpServer::INVALID_REQUEST, $send(json_encode(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping']))['decoded']['error']['code'], 'Null ids are invalid in MCP.');
assert_same(McpServer::INVALID_PARAMS, $send($rpc('tools/call', ['name' => 'nope']))['decoded']['error']['code'], 'Unknown tools are invalid params.');
assert_same(McpServer::INVALID_PARAMS, $send($rpc('tools/call', ['name' => 'list_models', 'arguments' => [1, 2]]))['decoded']['error']['code'], 'Arguments must be an object.');

// batches (2025-03-26 clients)
$batch = $send(json_encode([
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
    ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
    ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
]));
assert_same(2, count($batch['decoded']), 'Batches answer every request but not notifications.');
assert_same([1, 2], array_column($batch['decoded'], 'id'), 'Batch responses keep ids.');

// tools/list: schema shape
$list = $send($rpc('tools/list'));
$tools = $list['decoded']['result']['tools'];
assert_same(['search_knowledge_base', 'list_knowledge_bases', 'list_models', 'get_quota', 'get_usage_summary', 'get_api_key_info'], array_column($tools, 'name'), 'All TokenHub tools are listed.');
foreach ($tools as $tool) {
    assert_same('object', $tool['inputSchema']['type'], "{$tool['name']} input schema is an object.");
    assert_same(true, $tool['annotations']['readOnlyHint'], "{$tool['name']} is read-only.");
    assert_true(is_string($tool['description']) && $tool['description'] !== '', "{$tool['name']} has a description.");
}
assert_true(str_contains($list['json'], '"inputSchema":{"type":"object","properties":{},"additionalProperties":false}'), 'Argument-less tools serialize properties as {}.');

// tools/call: search
$search = $send($rpc('tools/call', ['name' => 'search_knowledge_base', 'arguments' => ['query' => '出差报销标准', 'knowledge_base_ids' => ['kb_finance'], 'top_k' => 3]]));
$result = $search['decoded']['result'];
assert_same(false, $result['isError'], 'A successful search is not an error.');
assert_same(['search', 7, '出差报销标准', ['knowledge_base_ids' => ['kb_finance'], 'dataset_ids' => [], 'top_k' => 3]], $calls[0], 'Search runs with the authenticated key and the given scope.');
assert_same('rtr_1', $result['structuredContent']['retrieval_id'], 'The retrieval id is returned for feedback.');
assert_same(0.9123, $result['structuredContent']['sources'][0]['score'], 'Scores are rounded.');
assert_same(6001, mb_strlen($result['structuredContent']['sources'][0]['content']), 'Very long passages are truncated.');
assert_true(str_contains($result['content'][0]['text'], '[src_1] 报销制度 — kb_finance / 财务制度'), 'The text block cites the source id, title and location.');
assert_true(str_contains($send($rpc('tools/call', ['name' => 'search_knowledge_base', 'arguments' => ['query' => 'nothing']]))['decoded']['result']['content'][0]['text'], 'No passages met'), 'Empty results explain themselves.');

// tool errors are results with isError=true, not protocol errors
$denied = $send($rpc('tools/call', ['name' => 'search_knowledge_base', 'arguments' => ['query' => 'forbidden']]))['decoded']['result'];
assert_same(true, $denied['isError'], 'Permission denials are tool errors.');
assert_same('[RAG_KB_FORBIDDEN] Knowledge base permission denied', $denied['content'][0]['text'], 'Denials keep the TokenHub error code.');
$broken = $send($rpc('tools/call', ['name' => 'search_knowledge_base', 'arguments' => ['query' => 'explode']]))['decoded']['result'];
assert_same(true, $broken['isError'], 'Unexpected failures are tool errors.');
assert_true(!str_contains($broken['content'][0]['text'], 'qdrant'), 'Internal error details are not leaked to the client.');
assert_same(['search_knowledge_base', true, 'qdrant down'], end($observed), 'The observer receives the internal failure for logging.');
foreach ([
    ['query' => ''],
    ['query' => 'x', 'top_k' => 0],
    ['query' => 'x', 'top_k' => 'many'],
    ['query' => 'x', 'dataset_ids' => 'ds_1'],
    ['query' => 'x', 'surprise' => true],
] as $badArguments) {
    $bad = $send($rpc('tools/call', ['name' => 'search_knowledge_base', 'arguments' => $badArguments]))['decoded']['result'];
    assert_same(true, $bad['isError'], 'Invalid search arguments are rejected: ' . json_encode($badArguments));
}

// other tools
$models = $send($rpc('tools/call', ['name' => 'list_models']))['decoded']['result'];
assert_same('gpt-4o', $models['structuredContent']['models'][0]['id'], 'list_models works without arguments.');
assert_same('kb_x', $send($rpc('tools/call', ['name' => 'list_knowledge_bases', 'arguments' => ['knowledge_base_id' => 'kb_x']]))['decoded']['result']['structuredContent']['kb_id'], 'list_knowledge_bases can list datasets.');
$send($rpc('tools/call', ['name' => 'get_usage_summary', 'arguments' => ['from' => '2026-10-01']]));
assert_same(['usage', ['from' => '2026-10-01']], end($calls), 'Usage summary forwards the period.');
assert_same(true, $send($rpc('tools/call', ['name' => 'get_usage_summary', 'arguments' => ['from' => 'not a date']]))['decoded']['result']['isError'], 'Bad dates are tool errors.');
assert_same(7, $send($rpc('tools/call', ['name' => 'get_api_key_info', 'arguments' => new stdClass()]))['decoded']['result']['structuredContent']['api_key']['id'], 'get_api_key_info returns the key identity.');
assert_same([], $protocolErrors, 'No request escaped as an internal protocol error.');
