<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Providers\OpenAIAdapter;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class AdminChatSessionService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function models(): array
    {
        $where = $this->chatModelWhereClause();
        $supported = array_flip((new ModelSupportPolicy())->modelIds(true));
        return array_values(array_filter(App::db()->select(
            "SELECT m.id, m.name, m.external_name, m.context_length, m.route_priority, p.name AS provider_name
             FROM models m
             JOIN providers p ON p.id = m.provider_id
             WHERE {$where}
             ORDER BY m.route_priority ASC, p.name ASC, m.name ASC"
        ), static fn (array $model): bool => isset($supported[(int) $model['id']])));
    }

    /**
     * Verified trial: runs the message through the production gateway as the
     * selected API key, so authorization, quotas, desensitization, RAG,
     * routing/failover and usage records behave exactly as for that key.
     *
     * The administrator must be allowed to manage the key's department; the
     * trial is audited with the admin id and the resulting request id, and is
     * charged to the selected key like any other call (no silent bypass).
     *
     * @param array<string, mixed> $payload must contain api_key_id
     */
    public function gatewayTrial(array $payload, bool $stream): Response
    {
        $actor = App::context()->adminUser();
        if ($actor === null) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }
        $apiKeyId = (int) ($payload['api_key_id'] ?? 0);
        $apiKey = App::db()->first(
            'SELECT k.*, u.status AS user_status, d.status AS department_status FROM api_keys k JOIN users u ON u.id = k.user_id JOIN departments d ON d.id = k.department_id WHERE k.id = ?',
            [$apiKeyId]
        );
        if ($apiKey === null || !(new PermissionService())->canManage($actor, (int) $apiKey['department_id'])) {
            abort_json('ADMIN_CHAT_KEY_NOT_FOUND', 'API key not found or outside admin scope', 404);
        }
        if ($apiKey['status'] !== 'active' || $apiKey['user_status'] !== 'active' || $apiKey['department_status'] !== 'active') {
            abort_json('API_KEY_INVALID', 'Selected API key is not active', 409);
        }
        unset($apiKey['key_hash']);
        $apiKey['allowed_model_ids'] = (new ApiKeyService())->routableModelIds(['id' => $apiKeyId]);
        $model = trim((string) ($payload['model'] ?? ''));
        if (!(new ApiKeyService())->assertModelAllowed($apiKey, $model)['ok']) {
            abort_json('MODEL_FORBIDDEN', 'Model is not allowed for the selected key', 403);
        }

        $body = [
            'model' => $model,
            'messages' => $this->normalizeMessages(is_array($payload['messages'] ?? null) ? $payload['messages'] : []),
            'temperature' => $this->floatInRange($payload['temperature'] ?? 1, 0, 2, 1),
            'max_tokens' => $this->intInRange($payload['max_tokens'] ?? 1024, 1, 16000, 1024),
            'stream' => $stream,
        ];
        foreach (['rag', 'tools', 'tool_choice', 'response_format', 'stream_options'] as $passThrough) {
            if (isset($payload[$passThrough])) {
                $body[$passThrough] = $payload[$passThrough];
            }
        }
        if ($stream) {
            $body['stream_options'] ??= ['include_usage' => true];
        }

        App::context()->setApiKey($apiKey);
        (new AuditService())->write('admin_chat.gateway_trial', 'api_keys', $apiKeyId, ['model' => $model, 'stream' => $stream]);
        $request = new Request('POST', '/v1/chat/completions', $_SERVER, [], $body, (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), App::context());

        return (new GatewayService())->handle($request)
            ->withHeader('X-TokenHub-Trial-Mode', 'gateway')
            ->withHeader('X-TokenHub-Trial-Api-Key', (string) $apiKeyId);
    }

    /**
     * Direct diagnostic call: bypasses quotas, desensitization, RAG and usage
     * records. Kept for connectivity checks only; results are labelled so they
     * are not mistaken for production-path verification.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        $model = trim((string) ($payload['model'] ?? ''));
        if ($model === '') {
            abort_json('ADMIN_CHAT_INVALID_PAYLOAD', 'model is required', 422);
        }

        $messages = $payload['messages'] ?? [];
        if (!is_array($messages) || $messages === []) {
            abort_json('ADMIN_CHAT_INVALID_PAYLOAD', 'messages must be a non-empty array', 422);
        }

        [$route, $request] = $this->providerRequest($model, $this->normalizeMessages($messages), $payload);
        $started = microtime(true);
        $provider = (new ProviderHttpClient())->request($route, '/v1/chat/completions', $request);
        App::logger()->info('provider', 'admin chat provider request', [
            'provider_id' => $route['provider_id'] ?? null,
            'model_id' => $route['id'] ?? null,
            'status' => $provider['status'] ?? 0,
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
        if (!$provider['ok']) {
            abort_json($provider['error_code'] ?? 'PROVIDER_ERROR', 'Upstream provider request failed', ($provider['error_code'] ?? '') === 'PROVIDER_TIMEOUT' ? 504 : 502);
        }

        $response = $provider['json'] ?? json_decode((string) $provider['body'], true);
        if (!is_array($response)) {
            abort_json('PROVIDER_INVALID_RESPONSE', 'Upstream provider returned an invalid response', 502);
        }

        $message = $response['choices'][0]['message'] ?? [];
        $content = is_array($message) ? (string) ($message['content'] ?? '') : '';

        return [
            'mode' => 'direct_diagnostic',
            'governance' => 'bypassed',
            'model' => $model,
            'provider_model' => (string) ($route['external_name'] ?? $model),
            'provider' => (string) ($route['provider_name'] ?? ''),
            'content' => $content,
            'finish_reason' => (string) ($response['choices'][0]['finish_reason'] ?? ''),
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
            'raw_id' => (string) ($response['id'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(string): void $onChunk
     * @return array{ok: bool, status: int, attempts: int, error_code?: string}
     */
    public function stream(array $payload, callable $onChunk): array
    {
        $model = trim((string) ($payload['model'] ?? ''));
        if ($model === '') {
            abort_json('ADMIN_CHAT_INVALID_PAYLOAD', 'model is required', 422);
        }

        $messages = $payload['messages'] ?? [];
        if (!is_array($messages) || $messages === []) {
            abort_json('ADMIN_CHAT_INVALID_PAYLOAD', 'messages must be a non-empty array', 422);
        }

        [$route, $requestPayload] = $this->providerRequest($model, $this->normalizeMessages($messages), $payload);
        $requestPayload['stream'] = true;
        $gatewayRequest = new GatewayRequest(
            endpoint: '/v1/chat/completions',
            protocol: 'openai',
            capability: 'text_generation',
            model: $model,
            stream: true,
            payload: $requestPayload,
            estimatedUsage: $this->estimateUsage($requestPayload),
        );

        $started = microtime(true);
        $result = (new ProviderHttpClient())->stream($route, $gatewayRequest, new OpenAIAdapter(), $onChunk);
        App::logger()->info('provider', 'admin chat provider stream', [
            'provider_id' => $route['provider_id'] ?? null,
            'model_id' => $route['id'] ?? null,
            'status' => $result['status'] ?? 0,
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            'error_code' => $result['error_code'] ?? null,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function route(string $model): ?array
    {
        $where = $this->chatModelWhereClause();
        return App::db()->first(
            "SELECT m.*, p.name AS provider_name, p.protocol, p.base_url, p.encrypted_secret, p.default_headers, p.timeout_ms, p.retry_count
             FROM models m
             JOIN providers p ON p.id = m.provider_id
             WHERE m.name = ?
               AND {$where}
             ORDER BY m.route_priority ASC
             LIMIT 1",
            [$model]
        );
    }

    private function chatModelWhereClause(): string
    {
        return "m.status = \"active\"
               AND p.status = \"active\"
               AND m.capability = \"text_generation\"
               AND p.protocol = \"openai\"
               AND m.name NOT LIKE \"gap-chat-%\"
               AND p.name NOT LIKE \"Gap Provider %\"
               AND LOWER(p.base_url) NOT LIKE \"%example%\"
               AND LOWER(p.base_url) NOT LIKE \"%invalid%\"";
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function providerRequest(string $model, array $messages, array $payload): array
    {
        $route = $this->route($model);
        if ($route === null) {
            abort_json('ADMIN_CHAT_MODEL_NOT_FOUND', 'Model is not active or not OpenAI-compatible text generation', 404);
        }

        return [$route, [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $this->floatInRange($payload['temperature'] ?? 1, 0, 2, 1),
            'max_tokens' => $this->intInRange($payload['max_tokens'] ?? 1024, 1, 16000, 1024),
        ]];
    }

    /**
     * @param array<int, mixed> $messages
     * @return list<array{role: string, content: string}>
     */
    private function normalizeMessages(array $messages): array
    {
        $normalized = [];
        foreach (array_slice($messages, -20) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) ($message['content'] ?? ''));
            if (!in_array($role, ['system', 'user', 'assistant'], true) || $content === '') {
                continue;
            }
            $normalized[] = [
                'role' => $role,
                'content' => substr($content, 0, 20000),
            ];
        }

        if ($normalized === []) {
            abort_json('ADMIN_CHAT_INVALID_PAYLOAD', 'messages must include user or system content', 422);
        }

        return $normalized;
    }

    private function intInRange(mixed $value, int $min, int $max, int $default): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            return $default;
        }

        return max($min, min($max, (int) $int));
    }

    private function floatInRange(mixed $value, float $min, float $max, float $default): float
    {
        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($float === false) {
            return $default;
        }

        return max($min, min($max, (float) $float));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private function estimateUsage(array $payload): array
    {
        $tokens = max(1, (int) ceil(strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') / 4));
        return ['input_tokens' => $tokens, 'output_tokens' => 0, 'total_tokens' => $tokens];
    }
}
