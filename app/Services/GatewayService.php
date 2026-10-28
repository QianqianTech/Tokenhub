<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\AnthropicRequestParser;
use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\GatewayUsageRecorder;
use TokenHub\Services\Gateway\KimiClaudeCodeBridge;
use TokenHub\Services\Gateway\ModelRouter;
use TokenHub\Services\Gateway\OpenAIRequestParser;
use TokenHub\Services\Providers\AnthropicAdapter;
use TokenHub\Services\Providers\NativeMultimodalAdapter;
use TokenHub\Services\Providers\OpenAIAdapter;
use TokenHub\Services\Providers\ProviderAdapterInterface;
use TokenHub\Services\Providers\VolcengineArkAdapter;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class GatewayService
{
    public function handle(Request $request): Response
    {
        $started = microtime(true);
        $gatewayRequest = $this->parse($request);
        $recorder = new GatewayUsageRecorder();

        if ($gatewayRequest->model === '') {
            $recorder->record($request, $gatewayRequest, 'blocked', 'MODEL_FORBIDDEN', 403, $started);
            return Response::error('MODEL_FORBIDDEN', 'No enabled provider route for model', 403);
        }

        $route = (new ModelRouter())->route($gatewayRequest);
        if ($route === null) {
            $recorder->record($request, $gatewayRequest, 'blocked', 'MODEL_FORBIDDEN', 403, $started);
            return Response::error('MODEL_FORBIDDEN', 'No enabled provider route for model', 403);
        }

        $quotaService = new QuotaService();
        $quota = $quotaService->preflight(App::context()->apiKey() ?? [], $gatewayRequest->estimatedUsage);
        if (!$quota['allowed']) {
            $code = $quota['code'] ?? 'QUOTA_EXCEEDED';
            $status = (int) ($quota['status'] ?? 429);
            $recorder->record($request, $gatewayRequest, 'blocked', $code, $status, $started, $route);
            return Response::error($code, $quota['message'] ?? 'Quota exceeded', $status);
        }
        $requiresCostTracking = (bool) ($quota['requires_cost_tracking'] ?? false);
        $concurrencyKeys = $quota['concurrency_keys'] ?? [];
        $quotaReservations = $quota['reservations'] ?? [];
        $modelQuotaService = new ApiKeyModelQuotaService();
        $modelQuota = $modelQuotaService->preflight(App::context()->apiKey() ?? [], $route, $gatewayRequest->estimatedUsage);
        if (!$modelQuota['allowed']) {
            $quotaService->releaseConcurrency($concurrencyKeys);
            $code = $modelQuota['code'] ?? 'MODEL_QUOTA_EXCEEDED';
            $status = (int) ($modelQuota['status'] ?? 429);
            $recorder->record($request, $gatewayRequest, 'blocked', $code, $status, $started, $route);
            return Response::error($code, $modelQuota['message'] ?? 'Model quota exceeded', $status);
        }
        $modelQuotaReservations = $modelQuota['reservations'] ?? [];
        $bridge = new KimiClaudeCodeBridge();
        $bridgeKimi = $bridge->shouldBridge($gatewayRequest, $route);
        if ($bridgeKimi && str_contains($gatewayRequest->endpoint, '/messages/count_tokens')) {
            $response = $bridge->countTokensResponse($gatewayRequest);
            $usage = ['input_tokens' => $response['input_tokens'], 'output_tokens' => 0, 'total_tokens' => $response['input_tokens']];
            $recorder->record($request, $gatewayRequest, 'success', null, 200, $started, $route, $usage);
            $quotaService->releaseConcurrency($concurrencyKeys);
            return Response::json($response, 200, ['X-Request-Id' => App::context()->requestId()]);
        }

        $providerRequest = $bridgeKimi ? $bridge->toOpenAIProviderRequest($gatewayRequest) : $gatewayRequest;
        $adapter = $bridgeKimi ? new OpenAIAdapter() : $this->adapterFor($route);
        $taskService = new MultimodalTaskService();
        $task = null;

        if ($route['protocol'] === 'native') {
            if ($taskService->isTaskCapability($gatewayRequest->capability)) {
                $task = $taskService->create($gatewayRequest, $route, 'queued', 0);
            }
            $response = $this->compatibleResponse($gatewayRequest, $route, $task);
            $usage = $this->withCostIfNeeded($route, $adapter->usage($gatewayRequest, $response), $requiresCostTracking);
            if ($task !== null) {
                $usage['task_id'] = (string) $task['task_id'];
            }
            $quotaService->recordUsage(App::context()->apiKey() ?? [], $usage, $quotaReservations);
            $modelQuotaService->recordUsage(App::context()->apiKey() ?? [], $route, $usage, $modelQuotaReservations);
            $recorder->record($request, $gatewayRequest, 'success', null, 202, $started, $route, $usage, $this->contentPayload($gatewayRequest, $response));
            $quotaService->releaseConcurrency($concurrencyKeys);
            return Response::json($response, 202, ['X-Request-Id' => App::context()->requestId()]);
        }

        $desensitization = new DesensitizationService();
        $recordRequest = $this->withPayload($gatewayRequest, $desensitization->outbound($gatewayRequest->payload, $route));
        $providerRequest = $this->withPayload($providerRequest, $desensitization->outbound($providerRequest->payload, $route));
        $ragPayload = $recordRequest->payload;
        if (is_array($ragPayload['rag'] ?? null)) {
            $ragPayload['rag']['model_capability'] = $recordRequest->capability;
        }
        $ragResult = (new RagRetrievalService())->retrieve(App::context()->apiKey() ?? [], $ragPayload);
        if (($ragResult['enabled'] ?? false) === true) {
            $recordRequest = $this->withPayload($recordRequest, $ragResult['payload'] ?? $recordRequest->payload);
            $providerPayload = $providerRequest->payload;
            unset($providerPayload['rag']);
            if (!empty($ragResult['sources']) && $providerPayload !== $recordRequest->payload) {
                $providerPayload = (new RagPromptInjector())->inject($providerPayload, $ragResult['sources']);
            } elseif (($ragResult['payload'] ?? null) === $recordRequest->payload) {
                $providerPayload = $recordRequest->payload;
            }
            $providerRequest = $this->withPayload($providerRequest, $providerPayload);
        }

        if ($providerRequest->stream) {
            return $this->streamResponse($request, $recordRequest, $providerRequest, $route, $adapter, $quotaService, $modelQuotaService, $concurrencyKeys, $quotaReservations, $modelQuotaReservations, $requiresCostTracking, $started, $recorder, $bridgeKimi ? $bridge : null);
        }

        if ($taskService->isTaskCapability($gatewayRequest->capability)) {
            $task = $taskService->create($gatewayRequest, $route, 'processing', 10);
        }

        $provider = (new ProviderHttpClient())->sendJson($route, $providerRequest, $adapter, $this->upstreamIdempotencyKey($request));
        $quotaService->releaseConcurrency($concurrencyKeys);

        if (!$provider['ok']) {
            $quotaService->recordUsage(App::context()->apiKey() ?? [], [], $quotaReservations);
            $modelQuotaService->recordUsage(App::context()->apiKey() ?? [], $route, [], $modelQuotaReservations);
            $code = $provider['error_code'] ?? 'PROVIDER_ERROR';
            $status = $code === 'PROVIDER_TIMEOUT' ? 504 : 502;
            if ($task !== null) {
                $taskService->fail($task, $code, 'Upstream provider request failed', (int) ((microtime(true) - $started) * 1000));
            }
            $recorder->record($request, $gatewayRequest, 'failed', $code, $status, $started, $route, $task !== null ? ['task_id' => (string) $task['task_id']] : []);
            return Response::error($code, 'Upstream provider request failed', $status);
        }

        $response = $provider['json'] ?? json_decode((string) $provider['body'], true);
        if (!is_array($response)) {
            if ($task !== null && $gatewayRequest->capability === 'tts' && (string) $provider['body'] !== '') {
                $response = ['b64_json' => base64_encode((string) $provider['body']), 'usage' => $gatewayRequest->estimatedUsage];
            } else {
                $response = ['raw' => (string) $provider['body']];
            }
        }
        $response = $adapter->normalizeResponse($providerRequest, $route, $response);
        if ($bridgeKimi) {
            $response = $bridge->toAnthropicResponse($gatewayRequest, $route, $response);
        }
        if (($ragResult['enabled'] ?? false) === true) {
            $response['rag'] = [
                'retrieval_id' => $ragResult['retrieval_id'] ?? null,
                'sources' => $ragResult['sources'] ?? [],
                'fallback_used' => (bool) ($ragResult['fallback_used'] ?? false),
            ];
        }
        $usage = $this->withCostIfNeeded($route, $bridgeKimi ? (new AnthropicAdapter())->usage($gatewayRequest, $response) : $adapter->usage($providerRequest, $response), $requiresCostTracking);
        if ($task !== null) {
            $response = $this->recordMultimodalResult($taskService, new MediaAssetService(), $task, $gatewayRequest, $response, (int) ((microtime(true) - $started) * 1000));
            $usage['task_id'] = (string) $task['task_id'];
        }
        $quotaService->recordUsage(App::context()->apiKey() ?? [], $usage, $quotaReservations);
        $modelQuotaService->recordUsage(App::context()->apiKey() ?? [], $route, $usage, $modelQuotaReservations);
        $status = $task !== null && $this->isAsyncProviderResponse($response) ? 202 : 200;
        $recorder->record($request, $recordRequest, 'success', null, $status, $started, $route, $usage, $this->contentPayload($recordRequest, $response));
        return Response::json($response, $status, ['X-Request-Id' => App::context()->requestId()]);
    }

    public function task(string $id): array
    {
        $task = (new MultimodalTaskService())->findAuthorized($id, App::context()->apiKey());
        if ($task === null) {
            abort_json('CONTENT_FORBIDDEN', 'Task not found or outside API key scope', 404);
        }

        return $task;
    }

    public function refreshTask(string $id): array
    {
        $taskService = new MultimodalTaskService();
        $taskRow = App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$id]);
        if ($taskRow === null || !$taskService->canAccess($taskRow, App::context()->apiKey())) {
            abort_json('CONTENT_FORBIDDEN', 'Task not found or outside API key scope', 404);
        }

        if (in_array((string) ($taskRow['status'] ?? ''), ['succeeded', 'failed'], true)) {
            return [...$this->task($id), 'refreshed' => false, 'refresh_reason' => 'terminal_status'];
        }

        $providerTaskId = (string) ($taskRow['provider_task_id'] ?? '');
        if ($providerTaskId === '' || empty($taskRow['model_id'])) {
            return [...$this->task($id), 'refreshed' => false, 'refresh_reason' => 'provider_task_unavailable'];
        }

        $route = App::db()->first(
            'SELECT m.*, p.protocol, p.base_url, p.encrypted_secret, p.default_headers, p.timeout_ms, p.retry_count
             FROM models m
             JOIN providers p ON p.id = m.provider_id
             WHERE m.id = ? AND p.status = "active"
             LIMIT 1',
            [(int) $taskRow['model_id']]
        );
        if ($route === null) {
            return [...$this->task($id), 'refreshed' => false, 'refresh_reason' => 'route_unavailable'];
        }

        $endpoint = $this->providerTaskEndpoint($route, $providerTaskId);
        if ($endpoint === null) {
            return [...$this->task($id), 'refreshed' => false, 'refresh_reason' => 'provider_refresh_unsupported'];
        }

        $provider = (new ProviderHttpClient())->send($endpoint, $route, [
            'method' => 'GET',
            'headers' => ['Accept' => 'application/json'],
        ]);
        if ($provider['status'] === 0 || $provider['status'] >= 400) {
            return [...$this->task($id), 'refreshed' => false, 'refresh_reason' => 'provider_refresh_failed'];
        }

        $providerResponse = $provider['json'] ?? json_decode((string) $provider['body'], true);
        if (!is_array($providerResponse)) {
            return [...$this->task($id), 'refreshed' => false, 'refresh_reason' => 'provider_refresh_invalid_response'];
        }

        $gatewayRequest = new GatewayRequest(
            endpoint: '/v1/videos/generations',
            protocol: (string) ($route['protocol'] ?? 'openai'),
            capability: (string) ($taskRow['task_type'] ?? 'video_generation'),
            model: (string) ($route['name'] ?? ''),
            stream: false,
            payload: [],
            estimatedUsage: ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
        );
        $adapter = $this->adapterFor($route);
        $response = $adapter->normalizeResponse($gatewayRequest, $route, $providerResponse);
        $status = (string) ($response['status'] ?? 'processing');
        $providerTaskId = $this->providerTaskId($response) ?? $providerTaskId;

        if ($status === 'failed') {
            $taskService->fail($taskRow, 'PROVIDER_TASK_FAILED', 'Upstream provider task failed');
        } elseif ($status === 'succeeded') {
            $assets = new MediaAssetService();
            $createdAssets = $assets->registerResponseAssets($taskRow, $gatewayRequest, $response);
            $taskService->complete($taskRow, [
                'provider_response' => $this->publicProviderResult($response),
                'asset_ids' => array_values(array_filter(array_map(static fn (array $asset): ?string => isset($asset['asset_id']) ? (string) $asset['asset_id'] : null, $createdAssets))),
            ], $providerTaskId);
        } else {
            $taskService->markProcessing($taskRow, ['provider_response' => $this->publicProviderResult($response)], $providerTaskId, isset($response['progress']) ? (int) $response['progress'] : null);
        }

        return [...$this->task($id), 'refreshed' => true, 'refreshed_at' => gmdate('Y-m-d H:i:s')];
    }

    public function assetDownloadUrl(string $id): array
    {
        $service = new MediaAssetService();
        $asset = $service->findAuthorized($id, App::context()->apiKey());
        if ($asset === null) {
            abort_json('CONTENT_FORBIDDEN', 'Asset not found or outside API key scope', 404);
        }

        return $service->createDownloadUrl($asset);
    }

    private function parse(Request $request): GatewayRequest
    {
        if (str_contains($request->path, '/messages')) {
            return (new AnthropicRequestParser())->parse($request);
        }

        return (new OpenAIRequestParser())->parse($request);
    }

    private function withPayload(GatewayRequest $request, array $payload): GatewayRequest
    {
        return new GatewayRequest(
            endpoint: $request->endpoint,
            protocol: $request->protocol,
            capability: $request->capability,
            model: $request->model,
            stream: $request->stream,
            payload: $payload,
            estimatedUsage: $request->estimatedUsage,
        );
    }

    /**
     * @param array<string, mixed> $route
     */
    private function adapterFor(array $route): ProviderAdapterInterface
    {
        if ($this->usesVolcengineArkAdapter($route)) {
            return new VolcengineArkAdapter();
        }

        return match ((string) ($route['protocol'] ?? 'openai')) {
            'anthropic' => new AnthropicAdapter(),
            'native' => new NativeMultimodalAdapter(),
            default => new OpenAIAdapter(),
        };
    }

    /**
     * @param array<string, mixed> $route
     */
    private function usesVolcengineArkAdapter(array $route): bool
    {
        $headers = $route['default_headers'] ?? null;
        if (is_string($headers) && $headers !== '') {
            $decoded = json_decode($headers, true);
            if (is_array($decoded) && (string) ($decoded['tokenhub_adapter'] ?? '') === 'volcengine_ark') {
                return true;
            }
        } elseif (is_array($headers) && (string) ($headers['tokenhub_adapter'] ?? '') === 'volcengine_ark') {
            return true;
        }

        $haystack = strtolower(implode(' ', array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            [$route['name'] ?? '', $route['base_url'] ?? '', $route['external_name'] ?? '']
        )));

        return str_contains($haystack, 'ark.cn-')
            || str_contains($haystack, 'volces.com/api/v3')
            || str_contains($haystack, 'seedance')
            || str_contains($haystack, 'seedream');
    }

    private function compatibleResponse(GatewayRequest $request, array $route, ?array $task = null): array
    {
        $payload = $request->payload;

        if ($request->protocol === 'anthropic') {
            return [
                'id' => App::context()->requestId(),
                'type' => 'message',
                'role' => 'assistant',
                'model' => $payload['model'] ?? $route['name'],
                'content' => [['type' => 'text', 'text' => 'TokenHub native route accepted the request.']],
                'usage' => ['input_tokens' => $this->estimateInputTokens($payload), 'output_tokens' => 16],
            ];
        }

        if ($request->capability === 'embedding') {
            $input = $this->estimateInputTokens($payload);
            return ['object' => 'list', 'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.0, 0.0, 0.0]]], 'model' => $payload['model'] ?? $route['name'], 'usage' => ['prompt_tokens' => $input, 'total_tokens' => $input]];
        }

        if (in_array($request->capability, ['asr', 'tts', 'image_generation', 'video_generation'], true)) {
            return ['task_id' => $task['task_id'] ?? null, 'status' => 'queued'];
        }

        return [
            'id' => App::context()->requestId(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $payload['model'] ?? $route['name'],
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'TokenHub native route accepted the request.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $this->estimateInputTokens($payload), 'completion_tokens' => 16, 'total_tokens' => $this->estimateInputTokens($payload) + 16],
        ];
    }

    private function streamResponse(Request $request, GatewayRequest $gatewayRequest, GatewayRequest $providerRequest, array $route, ProviderAdapterInterface $adapter, QuotaService $quotaService, ApiKeyModelQuotaService $modelQuotaService, array $concurrencyKeys, array $quotaReservations, array $modelQuotaReservations, bool $requiresCostTracking, float $started, GatewayUsageRecorder $recorder, ?KimiClaudeCodeBridge $bridge = null): Response
    {
        $client = new ProviderHttpClient();
        $state = [];
        $bridgeState = [];

        return Response::stream(function () use ($request, $gatewayRequest, $providerRequest, $route, $adapter, $quotaService, $modelQuotaService, $concurrencyKeys, $quotaReservations, $modelQuotaReservations, $requiresCostTracking, $started, $recorder, $client, $bridge, &$state, &$bridgeState): void {
            $result = $client->stream($route, $providerRequest, $adapter, function (string $chunk) use ($gatewayRequest, $providerRequest, $route, $adapter, $bridge, &$state, &$bridgeState): void {
                $state = $adapter->accumulateStreamUsage($providerRequest, $chunk, $state);
                echo $bridge !== null ? $bridge->transformOpenAIStreamChunk($gatewayRequest, $route, $chunk, $bridgeState) : $chunk;
            });

            $quotaService->releaseConcurrency($concurrencyKeys);

            if (!$result['ok']) {
                $quotaService->recordUsage(App::context()->apiKey() ?? [], [], $quotaReservations);
                $modelQuotaService->recordUsage(App::context()->apiKey() ?? [], $route, [], $modelQuotaReservations);
                $code = $result['error_code'] ?? 'PROVIDER_ERROR';
                echo 'event: error' . "\n";
                echo 'data: ' . json_encode(['error' => ['code' => $code, 'message' => 'Upstream provider request failed']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                $recorder->record($request, $gatewayRequest, 'failed', $code, $code === 'PROVIDER_TIMEOUT' ? 504 : 502, $started, $route);
                return;
            }

            $usage = $this->withCostIfNeeded($route, $state['usage'] ?? $this->estimatedStreamUsage($gatewayRequest, $state), $requiresCostTracking);
            $quotaService->recordUsage(App::context()->apiKey() ?? [], $usage, $quotaReservations);
            $modelQuotaService->recordUsage(App::context()->apiKey() ?? [], $route, $usage, $modelQuotaReservations);
            $recorder->record($request, $gatewayRequest, 'success', null, 200, $started, $route, $usage, $this->contentPayload($gatewayRequest, ['stream' => true, 'usage' => $usage]));
        }, 200, Response::sseHeaders());
    }

    private function estimatedStreamUsage(GatewayRequest $request, array $state): array
    {
        $input = (int) ($request->estimatedUsage['input_tokens'] ?? 0);
        $output = max(1, (int) ceil(((int) ($state['output_chars'] ?? 0)) / 4));
        return ['input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output];
    }

    private function upstreamIdempotencyKey(Request $request): string
    {
        $provided = trim((string) $request->header('idempotency-key', ''));
        if (preg_match('/^[A-Za-z0-9._-]{1,120}$/', $provided) === 1) {
            return $provided;
        }

        return App::context()->requestId();
    }

    private function recordMultimodalResult(MultimodalTaskService $tasks, MediaAssetService $assets, array $task, GatewayRequest $request, array $response, int $latencyMs): array
    {
        $providerTaskId = $this->providerTaskId($response);
        $createdAssets = $assets->registerResponseAssets($task, $request, $response);
        $publicAssets = array_map(static fn (array $asset): array => (new MediaAssetService())->publicAsset($asset), $createdAssets);
        $assetIds = array_values(array_filter(array_map(static fn (array $asset): ?string => isset($asset['asset_id']) ? (string) $asset['asset_id'] : null, $createdAssets)));
        $result = [
            'provider_response' => $this->publicProviderResult($response),
            'asset_ids' => $assetIds,
        ];

        if ($this->isAsyncProviderResponse($response)) {
            $tasks->markProcessing($task, $result, $providerTaskId, isset($response['progress']) ? (int) $response['progress'] : 10);
            return [
                'task_id' => $task['task_id'],
                'status' => 'processing',
                'provider_task_id' => $providerTaskId,
                'assets' => $publicAssets,
            ];
        }

        $tasks->complete($task, $result, $providerTaskId, $latencyMs);
        return [
            ...$this->publicProviderResult($response),
            'task_id' => $task['task_id'],
            'status' => $response['status'] ?? 'succeeded',
            'assets' => $publicAssets,
        ];
    }

    private function isAsyncProviderResponse(array $response): bool
    {
        return in_array((string) ($response['status'] ?? ''), ['queued', 'pending', 'processing', 'running'], true)
            || (!empty($response['provider_task_id']) && empty($response['data']) && empty($response['assets']) && empty($response['asset_url']) && empty($response['b64_json']));
    }

    private function providerTaskId(array $response): ?string
    {
        foreach (['provider_task_id', 'task_id', 'id'] as $key) {
            if (!empty($response[$key]) && is_scalar($response[$key])) {
                return (string) $response[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function providerTaskEndpoint(array $route, string $providerTaskId): ?string
    {
        if (!$this->usesVolcengineArkAdapter($route)) {
            return null;
        }

        return '/contents/generations/tasks/' . rawurlencode($providerTaskId);
    }

    private function publicProviderResult(array $response): array
    {
        unset($response['url'], $response['asset_url'], $response['assets'], $response['b64_json'], $response['storage_path']);
        if (isset($response['data']) && is_array($response['data'])) {
            $response['data'] = array_map(static function (mixed $item): mixed {
                if (!is_array($item)) {
                    return $item;
                }
                unset($item['url'], $item['b64_json'], $item['storage_path']);
                return $item;
            }, $response['data']);
        }

        return $response;
    }

    private function estimateInputTokens(array $payload): int
    {
        return max(1, (int) ceil(strlen(json_encode($payload, JSON_UNESCAPED_UNICODE)) / 4));
    }

    private function withCost(array $route, array $usage): array
    {
        if (empty($route['id'])) {
            return $usage;
        }

        $priced = (new PriceService())->priceUsage((int) $route['id'], $usage);
        if ($priced['cost_amount'] !== null) {
            $usage['cost_amount'] = $priced['cost_amount'];
        }

        return $usage;
    }

    private function withCostIfNeeded(array $route, array $usage, bool $required): array
    {
        return $required ? $this->withCost($route, $usage) : $usage;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function contentPayload(GatewayRequest $request, array $response): array
    {
        return [
            'session_id' => $this->sessionId($request->payload),
            'input' => $request->payload,
            'output' => $response,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sessionId(array $payload): ?string
    {
        foreach (['conversation_id', 'session_id'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        $metadata = $payload['metadata'] ?? null;
        if (is_array($metadata)) {
            foreach (['conversation_id', 'session_id'] as $key) {
                if (isset($metadata[$key]) && is_scalar($metadata[$key]) && (string) $metadata[$key] !== '') {
                    return (string) $metadata[$key];
                }
            }
        }

        return null;
    }

}
