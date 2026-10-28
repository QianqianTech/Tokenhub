<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Bootstrap\Kernel;
use TokenHub\Services\RagSearchService;
use TokenHub\Support\HttpAbortException;

// POST /v1/rag/search: validation, key-scoped backend call and the public
// result shape shared with the MCP search_knowledge_base tool.

$calls = [];
$masked = [];
$desensitizer = static function (string $query, array $apiKey) use (&$masked): string {
    $masked[] = [$apiKey['id'], $query];
    if ($query === 'blocked') {
        abort_json('DESENSITIZATION_BLOCKED', 'blocked', 422);
    }
    return str_replace('13800138000', '****', $query === 'only-secret' ? '' : $query);
};
$service = new RagSearchService(static function (array $apiKey, string $query, array $options) use (&$calls): array {
    $calls[] = [$apiKey['id'], $query, $options];
    if ($query === 'no-binding') {
        return ['retrieval_id' => null, 'dataset_count' => 0, 'sources' => []];
    }

    return ['retrieval_id' => 'rtr_1', 'dataset_count' => 2, 'sources' => [
        ['source_id' => 'src_1', 'title' => '差旅报销', 'content' => str_repeat('报销', 4000), 'score' => 0.912345, 'kb_id' => 'kb_finance', 'dataset_id' => 'ds_1', 'dataset_name' => '财务制度', 'source_uri' => 'policy.pdf#p3', 'modality' => 'text', 'retrieval_strategy' => 'hybrid'],
        ['source_id' => 'src_2', 'title' => '', 'content' => 'short', 'score' => 0.5, 'dataset_id' => 'ds_2'],
        'not-a-source',
    ]];
}, $desensitizer);

$expectError = static function (callable $fn, string $code, string $message): void {
    try {
        $fn();
    } catch (HttpAbortException $exception) {
        assert_same($code, $exception->errorCode, $message);
        assert_same(422, $exception->status, $message . ' (status)');
        return;
    }
    throw new RuntimeException($message . ' Expected ' . $code);
};

$key = ['id' => 42, 'department_id' => 3];

$result = $service->search($key, ['query' => '  差旅怎么报销  ', 'knowledge_base_ids' => ['kb_finance', 'kb_finance', ' kb_hr '], 'top_k' => 3.0]);
assert_same([42, '差旅怎么报销', ['knowledge_base_ids' => ['kb_finance', 'kb_hr'], 'dataset_ids' => [], 'top_k' => 3]], $calls[0], 'Backend receives the key, the trimmed query and de-duplicated ids.');
assert_same('rtr_1', $result['retrieval_id'], 'Retrieval id is returned for feedback.');
assert_same(2, $result['searched_datasets'], 'Searched dataset count is reported.');
assert_same(2, $result['hit_count'], 'Malformed sources are skipped.');
assert_same(RagSearchService::MAX_SOURCE_CHARS + 1, mb_strlen($result['sources'][0]['content']), 'Long passages are truncated with an ellipsis.');
assert_same(true, $result['sources'][0]['content_truncated'], 'Truncation is flagged.');
assert_same(0.9123, $result['sources'][0]['score'], 'Scores are rounded.');
assert_same('kb_finance', $result['sources'][0]['knowledge_base_id'], 'kb_id is exposed as knowledge_base_id.');
assert_same('hybrid', $result['sources'][0]['retrieval_strategy'], 'Retrieval strategy is exposed.');
assert_same(null, $result['sources'][1]['knowledge_base_id'], 'Missing kb id stays null.');
assert_same(false, $result['sources'][1]['content_truncated'], 'Short passages are not truncated.');

$service->search($key, ['query' => 'x']);
assert_same(RagSearchService::DEFAULT_TOP_K, $calls[1][2]['top_k'], 'top_k defaults to 5.');

$empty = $service->search($key, ['query' => 'no-binding']);
assert_same(['query' => 'no-binding', 'retrieval_id' => null, 'searched_datasets' => 0, 'hit_count' => 0, 'sources' => []], $empty, 'Keys without bindings get an empty, well-formed result.');

$before = count($calls);
$expectError(static fn () => $service->search($key, []), 'RAG_QUERY_REQUIRED', 'Missing query is rejected.');
$expectError(static fn () => $service->search($key, ['query' => '   ']), 'RAG_QUERY_REQUIRED', 'Blank query is rejected.');
$expectError(static fn () => $service->search($key, ['query' => 7]), 'RAG_QUERY_REQUIRED', 'Non-string query is rejected.');
$expectError(static fn () => $service->search($key, ['query' => str_repeat('a', 2001)]), 'RAG_SEARCH_INVALID', 'Over-long query is rejected.');
$expectError(static fn () => $service->search($key, ['query' => 'q', 'top_k' => 0]), 'RAG_SEARCH_INVALID', 'top_k below 1 is rejected.');
$expectError(static fn () => $service->search($key, ['query' => 'q', 'top_k' => 21]), 'RAG_SEARCH_INVALID', 'top_k above 20 is rejected.');
$expectError(static fn () => $service->search($key, ['query' => 'q', 'top_k' => '5']), 'RAG_SEARCH_INVALID', 'String top_k is rejected.');
$expectError(static fn () => $service->search($key, ['query' => 'q', 'dataset_ids' => 'ds_1']), 'RAG_SEARCH_INVALID', 'Scalar id list is rejected.');
$expectError(static fn () => $service->search($key, ['query' => 'q', 'dataset_ids' => ['a' => 'ds_1']]), 'RAG_SEARCH_INVALID', 'Object id list is rejected.');
$expectError(static fn () => $service->search($key, ['query' => 'q', 'dataset_ids' => [1]]), 'RAG_SEARCH_INVALID', 'Non-string ids are rejected.');
$expectError(static fn () => $service->search($key, ['query' => 'q', 'knowledge_base_ids' => array_map(static fn (int $i): string => 'kb_' . $i, range(1, 21))]), 'RAG_SEARCH_INVALID', 'Too many knowledge base ids are rejected.');
$expectError(static fn () => $service->search($key, ['query' => 'q', 'rag' => true]), 'RAG_SEARCH_INVALID', 'Unknown fields are rejected.');
assert_same($before, count($calls), 'Invalid requests never reach retrieval.');

// Outbound masking runs before retrieval (embedding provider, retrieval log).
$before = count($calls);
$maskedResult = $service->search($key, ['query' => '客户 13800138000 的报销']);
assert_same('客户 **** 的报销', $calls[$before][1], 'The masked query, not the raw one, reaches retrieval.');
assert_same('客户 **** 的报销', $maskedResult['query'], 'The response echoes the masked query.');
assert_same([42, '客户 13800138000 的报销'], end($masked), 'The desensitizer sees the caller key and the raw query.');
$expectError(static fn () => $service->search($key, ['query' => 'blocked']), 'DESENSITIZATION_BLOCKED', 'Blocking policies stop the search.');
$expectError(static fn () => $service->search($key, ['query' => 'only-secret']), 'RAG_QUERY_REQUIRED', 'A query masked to nothing is rejected.');
assert_same($before + 1, count($calls), 'Blocked queries never reach retrieval.');

$routes = array_map(static fn (array $route): string => $route['method'] . ' ' . $route['path'], Kernel::router()->routes());
assert_true(in_array('POST /v1/rag/search', $routes, true), 'POST /v1/rag/search is routed.');

echo "OK\n";
