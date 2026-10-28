<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * "Why did request X fail?" for administrators.
 *
 * Given a request id (returned to clients in X-Request-Id and every error
 * body) it returns the recorded outcome, the channel attempts, usage/cost
 * provenance and a plain-language explanation with the next step, so
 * permission, quota, configuration and upstream problems can be told apart
 * without database access.
 */
final class RequestDiagnosticsService
{
    /**
     * Error catalog: cause and operator action per gateway error code.
     */
    public const CATALOG = [
        'MODEL_FORBIDDEN' => ['category' => 'permission', 'cause' => 'The API key has no grant for this model or channel.', 'action' => 'Grant the model to the key (API keys > Models) or call a granted model.'],
        'MODEL_NOT_FOUND' => ['category' => 'configuration', 'cause' => 'No model with this name is configured.', 'action' => 'Check the model name or import it from the provider catalog.'],
        'MODEL_UNAVAILABLE' => ['category' => 'configuration', 'cause' => 'Every channel for the model is disabled or retired.', 'action' => 'Enable a channel or move the key to another model.'],
        'MODEL_CAPABILITY_UNSUPPORTED' => ['category' => 'configuration', 'cause' => 'No enabled channel supports this endpoint or a requested feature (tools, JSON schema, reasoning, images).', 'action' => 'Use a model whose capability contract includes the feature, or update the contract after verifying it.'],
        'CAPABILITY_UNAVAILABLE' => ['category' => 'configuration', 'cause' => 'Only "native" channels exist for this capability and they have no execution backend.', 'action' => 'Configure an OpenAI/Anthropic compatible channel (e.g. Volcengine Ark) for the capability.'],
        'QUOTA_EXCEEDED' => ['category' => 'quota', 'cause' => 'A quota policy bound to the key, user or department is exhausted for the current window (reservations of in-flight requests count).', 'action' => 'Wait for the window reset, raise the limit, or apply an audited quota adjustment.'],
        'MODEL_QUOTA_EXCEEDED' => ['category' => 'quota', 'cause' => 'The per-key model quota is exhausted.', 'action' => 'Raise the model quota for the key or wait for the reset.'],
        'CONCURRENCY_EXCEEDED' => ['category' => 'quota', 'cause' => 'Too many simultaneous requests for the bound policy.', 'action' => 'Reduce parallelism or raise concurrency_limit.'],
        'PRICE_UNKNOWN' => ['category' => 'configuration', 'cause' => 'A cost limit applies but the model has no price, and GATEWAY_UNKNOWN_PRICE_POLICY=deny.', 'action' => 'Add a model price or relax the unknown-price policy.'],
        'DESENSITIZATION_BLOCKED' => ['category' => 'policy', 'cause' => 'Outbound content (including injected RAG context) matched a blocking desensitization rule.', 'action' => 'Review the desensitization event for this request id.'],
        'ROUTING_PARAMETER_FORBIDDEN' => ['category' => 'policy', 'cause' => 'The request tried to change aggregator routing (provider/plugins/models) beyond the channel policy.', 'action' => 'Remove the parameter or set tokenhub_client_routing/tokenhub_provider_policy on the provider.'],
        'ROUTING_AUTO_ROUTER_FORBIDDEN' => ['category' => 'policy', 'cause' => 'Auto routers choose models outside TokenHub authorization.', 'action' => 'Use an explicit model or set tokenhub_allow_auto_router on the provider.'],
        'TOOL_CALL_MISMATCH' => ['category' => 'client', 'cause' => 'A tool result references a tool call id that was never issued in the conversation.', 'action' => 'Send the assistant message with tool_calls before the matching tool results.'],
        'TOOL_CHOICE_UNKNOWN' => ['category' => 'client', 'cause' => 'tool_choice names an undeclared tool.', 'action' => 'Declare the tool in tools or change tool_choice.'],
        'OUTBOUND_BLOCKED' => ['category' => 'configuration', 'cause' => 'The channel URL resolves to a private, loopback or metadata address.', 'action' => 'For private inference servers set tokenhub_allow_private_network on the provider or OUTBOUND_PRIVATE_ALLOWLIST.'],
        'PROVIDER_ENDPOINT_UNSUPPORTED' => ['category' => 'configuration', 'cause' => 'The channel endpoint map marks this endpoint unsupported.', 'action' => 'Route the request to a channel that supports the endpoint.'],
        'PROVIDER_TIMEOUT' => ['category' => 'upstream', 'cause' => 'The upstream did not answer (or stopped streaming) within timeout_ms.', 'action' => 'Check upstream status; raise timeout_ms for long generations.'],
        'PROVIDER_RATE_LIMITED' => ['category' => 'upstream', 'cause' => 'The upstream returned 429 for every attempted channel.', 'action' => 'Respect Retry-After, add channels or raise the upstream account limits.'],
        'PROVIDER_REJECTED' => ['category' => 'upstream', 'cause' => 'The upstream refused the request (4xx): bad parameters, auth or account state.', 'action' => 'Check the provider secret, account balance and request parameters.'],
        'PROVIDER_ERROR' => ['category' => 'upstream', 'cause' => 'The upstream returned 5xx on every attempt.', 'action' => 'Check upstream status; the circuit breaker will route around a failing channel.'],
        'PROVIDER_STREAM_ERROR' => ['category' => 'upstream', 'cause' => 'The upstream sent an error event after the stream started.', 'action' => 'Inspect the upstream error in the retained output; usually overload or content policy.'],
        'PROVIDER_STREAM_INCOMPLETE' => ['category' => 'upstream', 'cause' => 'The stream ended without its terminal event.', 'action' => 'Treat output as truncated; check upstream/network stability.'],
        'CLIENT_CANCELLED' => ['category' => 'client', 'cause' => 'The client disconnected; usage observed until then was charged.', 'action' => 'None unless unexpected; check client timeouts.'],
        'RAG_REQUIRED_NO_CONTEXT' => ['category' => 'rag', 'cause' => 'RAG was required but no dataset/context met the thresholds.', 'action' => 'Bind a dataset to the key or lower the retrieval threshold.'],
        'RAG_RETRIEVAL_FAILED' => ['category' => 'rag', 'cause' => 'Required retrieval failed (vector store or embedding provider).', 'action' => 'Check Qdrant and the embedding channel health.'],
        'API_KEY_INVALID' => ['category' => 'permission', 'cause' => 'Unknown, disabled or deleted key, or its user/department is disabled.', 'action' => 'Issue or re-enable a key.'],
        'API_KEY_EXPIRED' => ['category' => 'permission', 'cause' => 'The key passed expires_at (also after a rotation grace period).', 'action' => 'Switch the client to the rotated key.'],
    ];

    /**
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function explain(string $requestId, ?array $actor): array
    {
        $record = App::db()->first('SELECT * FROM usage_records WHERE request_id = ?', [$requestId]);
        if ($record === null) {
            return [
                'request_id' => $requestId,
                'found' => false,
                'hint' => 'No usage record yet: the request may still be queued for persistence (check operations status: queue backlog) or the id is wrong.',
            ];
        }
        if ($actor === null || !(new PermissionService())->canManage($actor, isset($record['department_id']) ? (int) $record['department_id'] : null)) {
            abort_json('ADMIN_NOT_FOUND', 'Request not found or outside admin scope', 404);
        }

        $code = (string) ($record['error_code'] ?? '');
        $route = !empty($record['model_id'])
            ? App::db()->first('SELECT m.id, m.name, m.external_name, m.status, p.id AS provider_id, p.name AS provider_name, p.status AS provider_status FROM models m JOIN providers p ON p.id = m.provider_id WHERE m.id = ?', [(int) $record['model_id']])
            : null;

        return [
            'request_id' => $requestId,
            'found' => true,
            'status' => $record['status'],
            'http_status' => isset($record['http_status']) ? (int) $record['http_status'] : null,
            'error_code' => $code !== '' ? $code : null,
            'explanation' => $code !== '' ? (self::CATALOG[$code] ?? ['category' => 'unknown', 'cause' => 'Unrecognised error code.', 'action' => 'Check gateway logs for this request id.']) : null,
            'channel' => $route,
            'attempts' => isset($record['attempts']) && is_string($record['attempts']) ? json_decode($record['attempts'], true) : null,
            'usage' => [
                'source' => $record['usage_source'] ?? null,
                'input_tokens' => (int) $record['input_tokens'],
                'output_tokens' => (int) $record['output_tokens'],
                'cache_read_tokens' => (int) ($record['cache_read_tokens'] ?? 0),
                'reasoning_tokens' => (int) ($record['reasoning_tokens'] ?? 0),
                'cost_amount' => $record['cost_amount'],
                'cost_currency' => $record['cost_currency'] ?? null,
                'upstream_cost' => $record['upstream_cost'] ?? null,
                'reconciled_at' => $record['reconciled_at'] ?? null,
            ],
            'stream' => [
                'outcome' => $record['stream_outcome'] ?? null,
                'first_byte_ms' => isset($record['first_byte_ms']) ? (int) $record['first_byte_ms'] : null,
            ],
            'upstream' => [
                'request_id' => $record['upstream_request_id'] ?? null,
                'model' => $record['upstream_model'] ?? null,
                'provider' => $record['upstream_provider'] ?? null,
            ],
            'latency_ms' => isset($record['latency_ms']) ? (int) $record['latency_ms'] : null,
            'persistence' => [
                'aggregated_at' => $record['aggregated_at'] ?? null,
                'events_emitted_at' => $record['events_emitted_at'] ?? null,
            ],
            'created_at' => $record['created_at'],
        ];
    }
}
