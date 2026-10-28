<?php

declare(strict_types=1);

namespace TokenHub\Services\Mcp;

use TokenHub\Services\DesensitizationService;
use TokenHub\Services\ExternalIdentityService;
use TokenHub\Services\ExternalModelService;
use TokenHub\Services\ExternalQuotaService;
use TokenHub\Services\ExternalUsageService;
use TokenHub\Services\RagPermissionService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Services\RagSearchService;

/**
 * The tools TokenHub exposes over MCP. Every tool is read-only and runs with
 * exactly the scope of the authenticated API key: knowledge-base bindings,
 * model permissions and quota bindings are the ones the REST /v1 API uses.
 */
final class TokenHubMcpTools
{
    /** @var array<string, \Closure> */
    private array $backends;

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, \Closure> $backends test seams; keys: search, knowledge_bases, datasets, models, quota, usage, me
     */
    public function __construct(private readonly array $apiKey, array $backends = [])
    {
        $this->backends = $backends + [
            // Same outbound masking as POST /v1/rag/search before the query
            // reaches the embedding provider and the retrieval log.
            'search' => static fn (array $key, string $query, array $options): array => (new RagRetrievalService())->search($key, (new DesensitizationService())->outboundText($query, $key, 'mcp_search'), $options),
            'knowledge_bases' => static fn (array $key): array => (new RagPermissionService())->listKnowledgeBases($key, ['limit' => 100]),
            'datasets' => static fn (array $key, string $kbId): array => (new RagPermissionService())->listDatasets($key, $kbId, ['limit' => 100]),
            'models' => static fn (array $key): array => (new ExternalModelService())->authorized($key),
            'quota' => static fn (array $key): array => (new ExternalQuotaService())->current($key),
            'usage' => static fn (array $key, array $query): array => (new ExternalUsageService())->summary($key, $query),
            'me' => static fn (array $key): array => (new ExternalIdentityService())->me($key),
        ];
    }

    public function registerInto(McpToolRegistry $registry): void
    {
        $readOnly = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $noArguments = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];

        $registry->register([
            'name' => 'search_knowledge_base',
            'title' => '知识库检索',
            'description' => 'Search the enterprise knowledge bases this API key can read (hybrid vector + keyword retrieval, '
                . 'Chinese and English). Returns the most relevant passages with source ids, titles and scores. '
                . 'Omit knowledge_base_ids/dataset_ids to search the key\'s default knowledge bases; call list_knowledge_bases to discover ids.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Natural-language question or keywords to search for.', 'minLength' => 1, 'maxLength' => 2000],
                    'knowledge_base_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20, 'description' => 'Restrict the search to these knowledge base ids (kb_id).'],
                    'dataset_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 50, 'description' => 'Restrict the search to these dataset ids.'],
                    'top_k' => ['type' => 'integer', 'minimum' => 1, 'maximum' => RagSearchService::MAX_TOP_K, 'default' => 5, 'description' => 'Maximum number of passages to return.'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            'annotations' => $readOnly,
        ], fn (array $args): McpToolResult => $this->search($args));

        $registry->register([
            'name' => 'list_knowledge_bases',
            'title' => '知识库列表',
            'description' => 'List the knowledge bases this API key can access. Pass knowledge_base_id to list the datasets inside one knowledge base instead.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'knowledge_base_id' => ['type' => 'string', 'description' => 'Optional kb_id; when set, returns that knowledge base\'s datasets.'],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $readOnly,
        ], function (array $args): array {
            $this->allowOnly($args, ['knowledge_base_id']);
            $kbId = $this->optionalString($args, 'knowledge_base_id', 191);

            return $kbId === null
                ? ($this->backends['knowledge_bases'])($this->apiKey)
                : ($this->backends['datasets'])($this->apiKey, $kbId);
        });

        $registry->register([
            'name' => 'list_models',
            'title' => '可用模型',
            'description' => 'List the models this API key may call through the TokenHub gateway, with capability and context length.',
            'inputSchema' => $noArguments,
            'annotations' => $readOnly,
        ], function (array $args): array {
            $this->allowOnly($args, []);
            $models = ($this->backends['models'])($this->apiKey);

            return ['models' => array_values((array) ($models['data'] ?? []))];
        });

        $registry->register([
            'name' => 'get_quota',
            'title' => '额度状态',
            'description' => 'Show the quota policies and per-model quotas that apply to this API key, including used and remaining amounts.',
            'inputSchema' => $noArguments,
            'annotations' => $readOnly,
        ], function (array $args): array {
            $this->allowOnly($args, []);

            return ($this->backends['quota'])($this->apiKey);
        });

        $registry->register([
            'name' => 'get_usage_summary',
            'title' => '用量统计',
            'description' => 'Summarize requests, tokens, cost and latency for this API key over a period (default: the last 30 days), broken down by model.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'description' => 'Start of the period, e.g. 2026-10-01 or an ISO 8601 timestamp (UTC).'],
                    'to' => ['type' => 'string', 'description' => 'End of the period (exclusive), same format as from.'],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => $readOnly,
        ], function (array $args): array {
            $this->allowOnly($args, ['from', 'to']);
            $query = [];
            foreach (['from', 'to'] as $field) {
                $value = $this->optionalString($args, $field, 64);
                if ($value !== null) {
                    if (strtotime($value) === false) {
                        throw new McpToolException($field . ' is not a valid date or timestamp');
                    }
                    $query[$field] = $value;
                }
            }

            return ($this->backends['usage'])($this->apiKey, $query);
        });

        $registry->register([
            'name' => 'get_api_key_info',
            'title' => 'API Key 信息',
            'description' => 'Show which API key, user and department this MCP connection is authenticated as.',
            'inputSchema' => $noArguments,
            'annotations' => $readOnly,
        ], function (array $args): array {
            $this->allowOnly($args, []);

            return ($this->backends['me'])($this->apiKey);
        });
    }

    /**
     * @param array<string, mixed> $args
     */
    private function search(array $args): McpToolResult
    {
        $this->allowOnly($args, ['query', 'knowledge_base_ids', 'dataset_ids', 'top_k']);
        $query = $this->optionalString($args, 'query', 2000);
        if ($query === null) {
            throw new McpToolException('query is required');
        }
        $topK = $args['top_k'] ?? 5;
        if (is_float($topK) && floor($topK) === $topK) {
            $topK = (int) $topK;
        }
        if (!is_int($topK) || $topK < 1 || $topK > 20) {
            throw new McpToolException('top_k must be an integer between 1 and 20');
        }

        $result = ($this->backends['search'])($this->apiKey, $query, [
            'knowledge_base_ids' => $this->stringList($args, 'knowledge_base_ids', 20),
            'dataset_ids' => $this->stringList($args, 'dataset_ids', 50),
            'top_k' => $topK,
        ]);

        $data = RagSearchService::format($query, $result);

        return McpToolResult::structured($data, $this->searchText($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function searchText(array $data): string
    {
        if ((int) $data['searched_datasets'] === 0) {
            return 'No knowledge base is available to this API key (or none matched the requested ids). Ask a TokenHub administrator to bind a knowledge base to the key.';
        }
        if ($data['sources'] === []) {
            return 'No passages met the retrieval thresholds for "' . $data['query'] . '". Try different keywords or a broader knowledge base.';
        }

        $lines = [sprintf('Found %d passage(s) for "%s"%s.', $data['hit_count'], $data['query'], $data['retrieval_id'] ? ' (retrieval_id: ' . $data['retrieval_id'] . ')' : '')];
        foreach ($data['sources'] as $source) {
            $where = trim(($source['knowledge_base_id'] ?? '') . ' / ' . $source['dataset_name'], ' /');
            $lines[] = '';
            $lines[] = sprintf('[%s] %s%s (score %.4f)', $source['source_id'], $source['title'] !== '' ? $source['title'] : '(untitled)', $where !== '' ? ' — ' . $where : '', $source['score']);
            if ($source['source_uri'] !== '') {
                $lines[] = 'source: ' . $source['source_uri'];
            }
            $lines[] = $source['content'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string> $allowed
     */
    private function allowOnly(array $args, array $allowed): void
    {
        $unknown = array_diff(array_keys($args), $allowed);
        if ($unknown !== []) {
            throw new McpToolException('Unknown argument(s): ' . implode(', ', $unknown));
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    private function optionalString(array $args, string $field, int $maxLength): ?string
    {
        if (!array_key_exists($field, $args) || $args[$field] === null) {
            return null;
        }
        if (!is_string($args[$field])) {
            throw new McpToolException($field . ' must be a string');
        }
        $value = trim($args[$field]);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new McpToolException($field . ' must be at most ' . $maxLength . ' characters');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $args
     * @return list<string>
     */
    private function stringList(array $args, string $field, int $maxItems): array
    {
        $value = $args[$field] ?? [];
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw new McpToolException($field . ' must be an array of strings');
        }
        if (count($value) > $maxItems) {
            throw new McpToolException($field . ' accepts at most ' . $maxItems . ' items');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new McpToolException($field . ' must contain non-empty strings');
            }
            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }
}
