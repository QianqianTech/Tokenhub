<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\AggregatorPayloadPolicy;
use TokenHub\Services\Gateway\AnthropicRequestParser;
use TokenHub\Services\Gateway\BudgetEstimator;
use TokenHub\Services\Gateway\ChannelLimiter;
use TokenHub\Services\Gateway\DeferredWork;
use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\GatewayUsageRecorder;
use TokenHub\Services\Gateway\KimiClaudeCodeBridge;
use TokenHub\Services\Gateway\ModelRouter;
use TokenHub\Services\Gateway\OpenAIRequestParser;
use TokenHub\Services\Gateway\OutboundHeaders;
use TokenHub\Services\Gateway\OutputTokenStats;
use TokenHub\Services\Gateway\PublicVendorMask;
use TokenHub\Services\Gateway\RequestLifecycle;
use TokenHub\Services\Gateway\ReservationLeaseStore;
use TokenHub\Services\Gateway\RouteHealth;
use TokenHub\Services\Gateway\Stream\GeminiJsonArrayStream;
use TokenHub\Services\Gateway\Stream\StreamAccumulator;
use TokenHub\Services\Gateway\ToolCallContractValidator;
use TokenHub\Services\Gateway\UpstreamErrorDetail;
use TokenHub\Services\Gateway\UsageNormalizer;
use TokenHub\Services\Pricing\PricingDimensions;
use TokenHub\Services\Providers\AnthropicAdapter;
use TokenHub\Services\Providers\NativeMultimodalAdapter;
use TokenHub\Services\Providers\OpenAIAdapter;
use TokenHub\Services\Providers\ProviderAdapterInterface;
use TokenHub\Services\Providers\AsyncTaskAdapter;
use TokenHub\Services\Providers\Vendor\VendorAdapter;
use TokenHub\Services\Providers\Vendor\VendorProfiles;
use TokenHub\Services\Providers\VolcengineArkAdapter;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Request;
use TokenHub\Support\SchemaInspector;
use TokenHub\Support\Response;

final class GatewayService
{
    /** Seconds spent waiting on upstream calls in this request (PERF-030). */
    private float $upstreamSeconds = 0.0;
    /** Settlement and usage record run after the response (PERF-010). */
    private bool $deferred = false;

    public function handle(Request $request): Response
    {
        $started = microtime(true);
        // QUAL-001: a client that disconnects must not kill the script before
        // settlement and the usage record; streams stop reading upstream via
        // connection_aborted() instead.
        ignore_user_abort(true);
        $gatewayRequest = $this->parse($request);
        $forward = OutboundHeaders::fromClient($request);
        if ($forward !== []) {
            $gatewayRequest = $this->withOptions($gatewayRequest, ['forward_headers' => $forward]);
        }
        $recorder = new GatewayUsageRecorder();
        $apiKey = App::context()->apiKey() ?? [];

        if ($gatewayRequest->model === '') {
            $recorder->record($request, $gatewayRequest, 'blocked', 'MODEL_FORBIDDEN', 403, $started);
            return $this->clientError($gatewayRequest, 'MODEL_FORBIDDEN', 'No enabled provider route for model', 403);
        }
        $contract = (new ToolCallContractValidator())->validate(UsageNormalizer::kindForEndpoint($gatewayRequest->endpoint, $gatewayRequest->protocol), $gatewayRequest->payload);
        if (!$contract['ok']) {
            $recorder->record($request, $gatewayRequest, 'blocked', $contract['code'], 400, $started);
            return $this->clientError($gatewayRequest, $contract['code'], $contract['message'], 400);
        }
        if (isset($gatewayRequest->estimatedUsage['upload_error'])) {
            [$code, $message] = explode(':', (string) $gatewayRequest->estimatedUsage['upload_error'], 2) + [1 => 'Invalid upload'];
            $recorder->record($request, $gatewayRequest, 'blocked', $code, 422, $started);
            return $this->clientError($gatewayRequest, $code, $message, 422);
        }

        $router = new ModelRouter();
        $allowedModelIds = ($apiKey['id'] ?? null) !== null ? (new ApiKeyService())->routableModelIds($apiKey) : null;
        $candidates = $this->usableCandidates($router->candidates($gatewayRequest, $allowedModelIds));
        $candidates = $this->pinStatefulContinuation($gatewayRequest, $candidates, $apiKey);
        if ($candidates === []) {
            $diagnosis = $this->nativeOnly($router->candidates($gatewayRequest, $allowedModelIds))
                ? ['code' => 'CAPABILITY_UNAVAILABLE', 'message' => 'This capability has no execution backend on the configured channels', 'status' => 501]
                : $router->diagnose($gatewayRequest, $allowedModelIds);
            $recorder->record($request, $gatewayRequest, 'blocked', $diagnosis['code'], $diagnosis['status'], $started);
            return $this->clientError($gatewayRequest, $diagnosis['code'], $diagnosis['message'], $diagnosis['status']);
        }
        $route = $candidates[0];

        $quotaService = new QuotaService();
        $modelQuotaService = new ApiKeyModelQuotaService();
        $budget = new BudgetEstimator();
        $estimate = $budget->estimate($gatewayRequest->estimatedUsage, $gatewayRequest->payload, $gatewayRequest->capability, $route);
        $requiresCostTracking = $apiKey !== [] && $quotaService->requiresCostTracking($apiKey);
        if ($requiresCostTracking) {
            $priced = $budget->withCost($route, $this->withPricingContext($gatewayRequest, $estimate));
            unset($priced['estimate']['pricing_dimensions']);
            if (!$priced['ok']) {
                $recorder->record($request, $gatewayRequest, 'blocked', 'PRICE_UNKNOWN', 402, $started, $route);
                return $this->clientError($gatewayRequest, 'PRICE_UNKNOWN', 'Model price is not configured and the cost limit cannot be enforced', 402);
            }
            $estimate = $priced['estimate'];
        }

        $quota = $quotaService->preflight($apiKey, $estimate);
        if (!$quota['allowed']) {
            $code = $quota['code'] ?? 'QUOTA_EXCEEDED';
            $status = (int) ($quota['status'] ?? 429);
            $recorder->record($request, $gatewayRequest, 'blocked', $code, $status, $started, $route);
            return $this->clientError($gatewayRequest, $code, $quota['message'] ?? 'Quota exceeded', $status);
        }

        $lifecycle = new RequestLifecycle(
            $quotaService,
            $modelQuotaService,
            $apiKey,
            $quota['concurrency_keys'] ?? [],
            $quota['reservations'] ?? [],
            null,
            [],
            new ReservationLeaseStore(),
            App::context()->requestId(),
            (int) ceil(((int) ($route['timeout_ms'] ?? 60000)) / 1000) * max(1, (int) ($route['retry_count'] ?? 1)) + 120,
        );
        // Registered before the lifecycle's own shutdown guard: the deferred
        // settlement must run first.
        DeferredWork::arm();
        $lifecycle->guardShutdown();
        $streaming = false;

        try {
            $modelQuota = $modelQuotaService->preflight($apiKey, $route, $estimate);
            if (!$modelQuota['allowed']) {
                $lifecycle->reject();
                $code = $modelQuota['code'] ?? 'MODEL_QUOTA_EXCEEDED';
                $status = (int) ($modelQuota['status'] ?? 429);
                $recorder->record($request, $gatewayRequest, 'blocked', $code, $status, $started, $route);
                return $this->clientError($gatewayRequest, $code, $modelQuota['message'] ?? 'Model quota exceeded', $status);
            }
            $lifecycle->attachModelQuota($route, $modelQuota['reservations'] ?? []);

            $bridge = new KimiClaudeCodeBridge();
            // Kimi Code has no count_tokens endpoint on either protocol.
            if ($gatewayRequest->capability === 'text_generation' && $bridge->isKimiCodeRoute($route) && str_contains($gatewayRequest->endpoint, '/messages/count_tokens')) {
                $response = $bridge->countTokensResponse($gatewayRequest);
                $usage = ['input_tokens' => $response['input_tokens'], 'output_tokens' => 0, 'total_tokens' => $response['input_tokens'], 'usage_source' => 'estimated'];
                $lifecycle->reject();
                $recorder->record($request, $gatewayRequest, 'success', null, 200, $started, $route, $usage);
                return Response::json($response, 200, ['X-Request-Id' => App::context()->requestId()]);
            }

            // Final payload pipeline: desensitize -> retrieve -> inject -> desensitize
            // again (injected knowledge passes the same outbound filter) -> routing policy.
            $prepared = $this->prepareOutboundPayload($gatewayRequest, $route, $apiKey, $router, $allowedModelIds);
            if (!$prepared['ok']) {
                $lifecycle->reject();
                $recorder->record($request, $gatewayRequest, 'blocked', $prepared['code'], $prepared['status'], $started, $route);
                return $this->clientError($gatewayRequest, $prepared['code'], $prepared['message'], $prepared['status']);
            }
            $finalRequest = $this->withPayload($gatewayRequest, $prepared['payload']);
            $ragResult = $prepared['rag'];

            // Budget follows the FINAL payload (RAG context included).
            $finalEstimate = $budget->estimate($gatewayRequest->estimatedUsage, $finalRequest->payload, $gatewayRequest->capability, $route);
            if ($requiresCostTracking) {
                $finalEstimate = $budget->withCost($route, $this->withPricingContext($gatewayRequest, $finalEstimate))['estimate'];
                unset($finalEstimate['pricing_dimensions']);
            }
            $delta = $budget->delta($estimate, $finalEstimate);
            if ($delta !== []) {
                $extra = $quotaService->reserveDelta($apiKey, $delta);
                if (!$extra['allowed']) {
                    $lifecycle->reject();
                    $recorder->record($request, $finalRequest, 'blocked', $extra['code'] ?? 'QUOTA_EXCEEDED', 429, $started, $route);
                    return $this->clientError($gatewayRequest, $extra['code'] ?? 'QUOTA_EXCEEDED', $extra['message'] ?? 'Quota exceeded', 429);
                }
                $extraModel = $modelQuotaService->reserveDelta($apiKey, $route, $delta);
                if (!$extraModel['allowed']) {
                    $quotaService->rollbackReservations($extra['reservations'] ?? []);
                    $lifecycle->reject();
                    $recorder->record($request, $finalRequest, 'blocked', $extraModel['code'] ?? 'MODEL_QUOTA_EXCEEDED', 429, $started, $route);
                    return $this->clientError($gatewayRequest, $extraModel['code'] ?? 'MODEL_QUOTA_EXCEEDED', $extraModel['message'] ?? 'Model quota exceeded', 429);
                }
                $lifecycle->addReservations($extra['reservations'] ?? [], $extraModel['reservations'] ?? []);
                $estimate = $finalEstimate;
            }

            if ($finalRequest->stream) {
                $streaming = true;
                return $this->streamResponse($request, $finalRequest, $candidates, $lifecycle, $requiresCostTracking, $started, $recorder, $estimate, $ragResult);
            }

            return $this->sendNonStreaming($request, $gatewayRequest, $finalRequest, $candidates, $lifecycle, $requiresCostTracking, $started, $recorder, $estimate, $ragResult);
        } catch (HttpAbortException $abort) {
            $lifecycle->finalizeAbandoned();
            $recorder->record($request, $gatewayRequest, $lifecycle->upstreamStarted() ? 'failed' : 'blocked', $abort->errorCode, $abort->status, $started, $lifecycle->route() ?? $route);
            return $this->clientError($gatewayRequest, $abort->errorCode, $abort->getMessage(), $abort->status);
        } finally {
            // The streaming writer runs after handle() returns and finalizes
            // itself; a deferred settlement runs after the response is sent
            // (and the lifecycle's shutdown guard still backs it up).
            if (!$streaming && !$this->deferred) {
                $lifecycle->finalizeAbandoned();
            }
        }
    }

    /**
     * PERF-010: runs $job after the response was sent when php-fpm allows it
     * (fastcgi_finish_request), inline otherwise.
     *
     * @param callable(): void $job
     */
    private function afterResponse(callable $job, bool $settlement = false): void
    {
        if ($settlement && DeferredWork::enabled()) {
            $this->deferred = true;
        }
        DeferredWork::add($job);
    }

    /**
     * PERF-030: Server-Timing for the client and gateway_overhead_ms for the
     * access log: time spent in TokenHub itself, i.e. total minus upstream.
     *
     * @return array{total_ms: int, upstream_ms: int, overhead_ms: int}
     */
    private function timing(float $started): array
    {
        $total = (int) round((microtime(true) - $started) * 1000);
        $upstream = (int) round($this->upstreamSeconds * 1000);

        return ['total_ms' => $total, 'upstream_ms' => $upstream, 'overhead_ms' => max(0, $total - $upstream)];
    }

    /**
     * @param array{total_ms: int, upstream_ms: int, overhead_ms: int} $timing
     */
    private static function serverTiming(array $timing): string
    {
        return 'gateway;dur=' . $timing['overhead_ms'] . ', upstream;dur=' . $timing['upstream_ms'] . ', total;dur=' . $timing['total_ms'];
    }

    /**
     * Error response in the client protocol's shape (QUAL-007): every error
     * carries error.type like the vendor APIs do (OpenAI SDKs read type and
     * code; Anthropic SDKs and Claude Code need "type": "error" plus
     * error.type). The TokenHub code stays in error.code.
     */
    private function clientError(GatewayRequest $request, string $code, string $message, int $status): Response
    {
        if ($request->protocol === 'anthropic') {
            return Response::errorWith($code, $message, $status, ['type' => UpstreamErrorDetail::anthropicType($status)], ['type' => 'error']);
        }

        return Response::errorWith($code, $message, $status, ['type' => self::openAiErrorType($code, $status)]);
    }

    private static function openAiErrorType(string $code, int $status): string
    {
        return match (true) {
            in_array($code, ['QUOTA_EXCEEDED', 'MODEL_QUOTA_EXCEEDED', 'PRICE_UNKNOWN'], true) => 'insufficient_quota',
            $status === 401 => 'authentication_error',
            $status === 403 => 'permission_error',
            $status === 404 => 'not_found_error',
            $status === 429 => 'rate_limit_error',
            $status === 501 => 'not_implemented_error',
            $status >= 500 => 'server_error',
            default => 'invalid_request_error',
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function withOptions(GatewayRequest $request, array $options): GatewayRequest
    {
        return new GatewayRequest(
            endpoint: $request->endpoint,
            protocol: $request->protocol,
            capability: $request->capability,
            model: $request->model,
            stream: $request->stream,
            payload: $request->payload,
            estimatedUsage: $request->estimatedUsage,
            options: [...$request->options, ...$options],
        );
    }

    /**
     * Health bookkeeping for one failed upstream attempt: 429 cools the
     * channel down for its Retry-After (QUAL-004), credential failures
     * (401/402/403) count and raise an alert (QUAL-003), other transient
     * failures count towards the circuit breaker (QUAL-006).
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $result
     */
    private function recordAttemptFailure(RouteHealth $health, array $route, array $result): void
    {
        $status = (int) ($result['status'] ?? 0);
        $code = (string) ($result['error_code'] ?? 'PROVIDER_ERROR');
        $modelId = (int) ($route['id'] ?? 0);
        if ($status === 429) {
            $retryAfter = $result['retry_after'] ?? null;
            $health->cooldown($modelId, is_numeric($retryAfter) ? (int) $retryAfter : null);
            return;
        }
        if (ProviderHttpClient::credentialFailure($status)) {
            $health->recordFailure($modelId, 'PROVIDER_CREDENTIAL_' . $status);
            $this->credentialAlert($route, $status);
            return;
        }
        if (RouteHealth::countsAsFailure($status, $code)) {
            $health->recordFailure($modelId, $code);
        }
    }

    /**
     * One alert per channel and 10 minutes when the upstream refuses the
     * channel credential (expired key, unpaid account, revoked access).
     *
     * @param array<string, mixed> $route
     */
    private function credentialAlert(array $route, int $status): void
    {
        $providerId = (int) ($route['provider_id'] ?? 0);
        try {
            if (!App::redis()->setIfAbsent('alert:provider_credential:' . $providerId . ':' . (int) ($route['id'] ?? 0), (string) time(), 600)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        App::logger()->error('upstream channel credential rejected', ['provider_id' => $providerId, 'model_id' => $route['id'] ?? null, 'status' => $status]);
        $this->afterResponse(static function () use ($route, $providerId, $status): void {
            try {
                (new AlertService())->record('provider_credential_rejected', 'critical', 'Upstream rejected the channel credential (HTTP ' . $status . '); the channel was skipped', [
                    'provider_id' => $providerId,
                    'model_id' => (int) ($route['id'] ?? 0),
                    'model' => (string) ($route['name'] ?? ''),
                    'status' => $status,
                ]);
            } catch (\Throwable) {
            }
        });
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed>|null $ragResult
     */
    private function sendNonStreaming(Request $request, GatewayRequest $gatewayRequest, GatewayRequest $finalRequest, array $candidates, RequestLifecycle $lifecycle, bool $requiresCostTracking, float $started, GatewayUsageRecorder $recorder, array $estimate, ?array $ragResult): Response
    {
        $taskService = new MultimodalTaskService();
        $health = new RouteHealth();
        $client = (new ProviderHttpClient())->onTick(static fn () => $lifecycle->renewLease());
        $limiter = new ChannelLimiter();
        $bridge = new KimiClaudeCodeBridge();
        $attempts = [];
        $provider = ['ok' => false, 'status' => 0, 'error_code' => 'PROVIDER_ERROR'];
        $route = $candidates[0];
        $adapter = $this->adapterFor($route, $gatewayRequest->capability);
        $providerRequest = $finalRequest;
        $bridgeKimi = false;
        $task = null;
        $maxChannels = $this->maxChannels($finalRequest);

        foreach (array_slice($candidates, 0, $maxChannels) as $index => $candidate) {
            if ($index > 0 && !$this->switchChannel($lifecycle, $candidate, $estimate, $finalRequest)) {
                continue;
            }
            $route = $candidate;
            $capacity = $limiter->acquire($route, App::context()->requestId(), (int) ($estimate['total_tokens'] ?? 0));
            if (!$capacity['ok']) {
                // MT-003: channel at its in-flight / RPM / TPM limit.
                $provider = ['ok' => false, 'status' => 429, 'error_code' => 'CHANNEL_CAPACITY_EXCEEDED', 'retry_after' => 1];
                $attempts[] = ['model_id' => $route['id'] ?? null, 'provider_id' => $route['provider_id'] ?? null, 'status' => 0, 'error_code' => 'CHANNEL_CAPACITY_EXCEEDED', 'reason' => $capacity['reason'] ?? null, 'tries' => 0];
                continue;
            }
            $bridgeKimi = $bridge->shouldBridge($gatewayRequest, $route);
            $providerRequest = $bridgeKimi ? $bridge->toOpenAIProviderRequest($finalRequest) : $finalRequest;
            $adapter = $bridgeKimi ? new OpenAIAdapter() : $this->adapterFor($route, $gatewayRequest->capability);
            if ($task === null && $taskService->isTaskCapability($gatewayRequest->capability)) {
                $task = $taskService->create($gatewayRequest, $route, 'processing', 10);
            }
            if ($task !== null && ($callbackUrl = (new TaskCallbackService())->urlFor($adapter, (string) $task['task_id'], $gatewayRequest->capability)) !== null) {
                $providerRequest = $this->withPayload($providerRequest, [...$providerRequest->payload, '__tokenhub_callback_url' => $callbackUrl]);
            }

            $lifecycle->markUpstreamStarted();
            $upstreamStarted = microtime(true);
            try {
                $provider = $client->sendJson($route, $providerRequest, $adapter, $this->upstreamIdempotencyKey($request));
            } finally {
                $this->upstreamSeconds += microtime(true) - $upstreamStarted;
                $limiter->release($route);
            }
            $attempts[] = [
                'model_id' => $route['id'] ?? null,
                'provider_id' => $route['provider_id'] ?? null,
                'status' => $provider['status'],
                'error_code' => $provider['error_code'] ?? null,
                'tries' => $provider['attempts'] ?? 1,
            ];
            if ($provider['ok']) {
                $health->recordSuccess((int) ($route['id'] ?? 0));
                break;
            }
            $this->recordAttemptFailure($health, $route, $provider);
            if (!$client->failoverable($provider)) {
                break;
            }
        }

        if (!$provider['ok']) {
            $code = $provider['error_code'] ?? 'PROVIDER_ERROR';
            $status = $this->gatewayStatusFor($code, (int) $provider['status']);
            if ($task !== null) {
                $taskService->fail($task, $code, 'Upstream provider request failed', (int) ((microtime(true) - $started) * 1000));
            }
            $timing = $this->timing($started);
            $this->afterResponse(static function () use ($lifecycle, $recorder, $request, $finalRequest, $code, $status, $started, $route, $task, $attempts, $timing): void {
                $lifecycle->settle([]);
                $recorder->record($request, $finalRequest, 'failed', $code, $status, $started, $route, [
                    ...($task !== null ? ['task_id' => (string) $task['task_id']] : []),
                    'attempts' => $attempts,
                    'gateway_overhead_ms' => $timing['overhead_ms'],
                ]);
            }, true);
            return $this->providerError($gatewayRequest, $route, $code, $status, $provider['json'] ?? ($provider['body'] ?? null), $provider['retry_after'] ?? null)['response']
                ->withHeader('Server-Timing', self::serverTiming($timing));
        }

        $binaryAudio = null;
        $binaryType = null;
        $plainText = null;
        $response = $provider['json'] ?? json_decode((string) $provider['body'], true);
        if (!is_array($response)) {
            if (in_array($gatewayRequest->capability, ['tts', 'music_generation'], true) && (string) $provider['body'] !== '') {
                $binaryAudio = (string) $provider['body'];
                $response = ['b64_json' => base64_encode($binaryAudio), 'usage' => $gatewayRequest->estimatedUsage];
            } elseif ($gatewayRequest->capability === 'asr' && (string) $provider['body'] !== '') {
                // response_format=text|srt|vtt: the upstream answers with a
                // text body, which the client must receive unchanged.
                $plainText = (string) $provider['body'];
                $response = ['text' => $plainText];
                $subtitleSeconds = $this->subtitleDurationSeconds($plainText);
                if ($subtitleSeconds !== null) {
                    $response['duration'] = $subtitleSeconds;
                }
                // stream=true transcriptions (gpt-transcribe) answer with SSE;
                // the final transcript.text.done event carries the usage.
                $streamUsage = $this->transcriptionStreamUsage($plainText);
                if ($streamUsage !== null) {
                    $response = ['text' => $streamUsage['text'], 'usage' => $streamUsage['usage']];
                }
            } else {
                $response = ['raw' => (string) $provider['body']];
            }
        }
        $response = $adapter->normalizeResponse($providerRequest, $route, $response);
        if (($response['status'] ?? null) === 'failed' && is_array($response['error'] ?? null)) {
            // Vendor adapters report in-band failures (HTTP 200 with an error body).
            $lifecycle->settle([]);
            $code = (string) ($response['error']['code'] ?? 'PROVIDER_TASK_FAILED');
            if ($task !== null) {
                $taskService->fail($task, $code, (string) ($response['error']['message'] ?? 'Upstream provider task failed'), (int) ((microtime(true) - $started) * 1000));
            }
            $recorder->record($request, $finalRequest, 'failed', $code, 502, $started, $route, [
                ...($task !== null ? ['task_id' => (string) $task['task_id']] : []),
                'attempts' => $attempts,
            ]);

            return $this->clientError($gatewayRequest, $code, 'Upstream provider rejected the task: ' . (string) ($response['error']['message'] ?? 'unknown error'), 502);
        }
        if ($binaryAudio === null && in_array($gatewayRequest->capability, ['tts', 'music_generation'], true) && is_string($response['b64_json'] ?? null) && isset($response['audio_mime'])) {
            // Vendor speech/music answered in JSON (hex/base64/url): the client
            // still receives raw audio bytes, like OpenAI /v1/audio/speech.
            $decoded = base64_decode((string) $response['b64_json'], true);
            if ($decoded !== false) {
                $binaryAudio = $decoded;
                $binaryType = (string) $response['audio_mime'];
            }
        }
        $upstream = $this->upstreamMetadata($response);
        $response = (new PublicVendorMask())->response($response);
        // Usage is read from the upstream (Chat Completions) shape before a
        // bridged response is translated to the client's wire format.
        $usageSource = $response;
        if ($bridgeKimi && $bridge->translatesResponse($gatewayRequest)) {
            $response = $bridge->toClientResponse($gatewayRequest, $route, $response);
        }
        if (($ragResult['enabled'] ?? false) === true) {
            $response['rag'] = [
                'retrieval_id' => $ragResult['retrieval_id'] ?? null,
                'sources' => $ragResult['sources'] ?? [],
                'fallback_used' => (bool) ($ragResult['fallback_used'] ?? false),
                'vector_status' => (string) ($ragResult['vector_status'] ?? 'none'),
                'context_truncated_sources' => (int) ($ragResult['truncated_sources'] ?? 0),
            ];
        }

        $usage = $this->usageFor($adapter, $providerRequest, $usageSource, UsageNormalizer::kindForEndpoint($providerRequest->endpoint, (string) ($route['protocol'] ?? 'openai')), $estimate);
        $usage = $this->withPricingContext($gatewayRequest, $usage);
        $usage = $this->withCostIfNeeded($route, $usage, $requiresCostTracking);
        if ($task !== null) {
            $response = $this->recordMultimodalResult($taskService, new MediaAssetService(), $task, $gatewayRequest, $response, (int) ((microtime(true) - $started) * 1000));
            $usage['task_id'] = (string) $task['task_id'];
        }
        if ($task !== null) {
            // Task settlement (TaskBillingService) reads the usage record
            // chain right away; keep it synchronous.
            $lifecycle->settle($usage);
        }
        $status = $task !== null && $this->isAsyncProviderResponse($response) ? 202 : 200;
        if ($task !== null && $gatewayRequest->endpoint === \TokenHub\Services\Video\OpenAIVideoApi::CREATE_ENDPOINT) {
            // OpenAI Videos API: 200 with a video object (id = task id).
            $response = (new \TokenHub\Services\Video\OpenAIVideoApi())->fromSubmission($response, $gatewayRequest->payload);
            $status = 200;
        }
        if (($usage['usage_source'] ?? null) === 'measured' && isset($usage['output_tokens']) && in_array($gatewayRequest->capability, \TokenHub\Services\Gateway\InputModalities::TEXT_FAMILY, true)) {
            (new OutputTokenStats())->record((int) ($route['id'] ?? 0), (int) $usage['output_tokens']);
        }
        $timing = $this->timing($started);
        $recordUsage = [...$usage, ...$upstream, 'attempts' => $attempts, 'gateway_overhead_ms' => $timing['overhead_ms']];
        // Retained content never stores media bytes or download links: the
        // assets table already references them.
        $content = $this->contentPayload($finalRequest, $binaryAudio !== null ? ['audio_bytes' => strlen($binaryAudio), 'usage' => $usage] : $this->publicProviderResult($response));
        // PERF-010: under php-fpm the client gets its answer first; quota
        // settlement and the usage record follow after fastcgi_finish_request().
        $this->afterResponse(static function () use ($lifecycle, $recorder, $request, $finalRequest, $status, $started, $route, $usage, $recordUsage, $content): void {
            $lifecycle->settle($usage);
            $recorder->record($request, $finalRequest, 'success', null, $status, $started, $route, $recordUsage, $content);
        }, true);
        $serverTiming = self::serverTiming($timing);

        if ($plainText !== null && strtolower((string) $request->header('x-tokenhub-response-mode', '')) !== 'json') {
            return Response::raw($plainText, 200, [
                'Content-Type' => (string) (($provider['headers']['content-type'] ?? '') ?: 'text/plain; charset=utf-8'),
                'X-Request-Id' => App::context()->requestId(),
                'Server-Timing' => $serverTiming,
                ...($task !== null ? ['X-TokenHub-Task-Id' => (string) $task['task_id']] : []),
            ]);
        }

        if ($binaryAudio !== null && strtolower((string) $request->header('x-tokenhub-response-mode', '')) !== 'json') {
            // Native audio contract: raw bytes with the upstream media type.
            $contentType = $binaryType ?? (string) (($provider['headers']['content-type'] ?? '') ?: 'audio/mpeg');
            return Response::raw($binaryAudio, 200, [
                'Content-Type' => $contentType,
                'Content-Length' => (string) strlen($binaryAudio),
                'X-Request-Id' => App::context()->requestId(),
                'Server-Timing' => $serverTiming,
                ...($task !== null ? ['X-TokenHub-Task-Id' => (string) $task['task_id']] : []),
            ]);
        }

        return Response::json($response, $status, ['X-Request-Id' => App::context()->requestId(), 'Server-Timing' => $serverTiming]);
    }

    public function task(string $id): array
    {
        $task = (new MultimodalTaskService())->findAuthorized($id, App::context()->apiKey());
        if ($task === null) {
            abort_json('CONTENT_FORBIDDEN', 'Task not found or outside API key scope', 404);
        }

        return $task;
    }

    /**
     * POST /v1/tasks/{id}:cancel. Vendors only cancel tasks that have not
     * started (Seedance, MiniMax H3, Wan); a running task answers 409. A
     * cancelled task is fully refunded (TaskBillingService).
     *
     * @return array<string, mixed>
     */
    public function cancelTask(string $id): array
    {
        $taskService = new MultimodalTaskService();
        $taskRow = App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$id]);
        if ($taskRow === null || !$taskService->canAccess($taskRow, App::context()->apiKey())) {
            abort_json('CONTENT_FORBIDDEN', 'Task not found or outside API key scope', 404);
        }
        if (in_array((string) ($taskRow['status'] ?? ''), ['succeeded', 'failed', 'expired'], true)) {
            abort_json('TASK_NOT_CANCELLABLE', 'Task already finished', 409);
        }
        $providerTaskId = (string) ($taskRow['provider_task_id'] ?? '');
        $route = !empty($taskRow['model_id']) ? App::db()->first(
            'SELECT m.*, p.protocol, p.base_url, p.encrypted_secret, p.default_headers, p.timeout_ms, p.retry_count
             FROM models m JOIN providers p ON p.id = m.provider_id WHERE m.id = ? LIMIT 1',
            [(int) $taskRow['model_id']]
        ) : null;
        $capability = (string) ($taskRow['task_type'] ?? 'video_generation');
        $adapter = $route !== null ? $this->adapterFor($route, $capability) : null;
        $cancel = $adapter !== null && $providerTaskId !== '' && method_exists($adapter, 'cancelRequest') ? $adapter->cancelRequest($route, $capability, $providerTaskId) : null;
        if ($cancel === null) {
            abort_json('TASK_NOT_CANCELLABLE', 'This vendor does not support cancelling tasks', 409);
        }
        $options = ['method' => (string) ($cancel['method'] ?? 'DELETE'), 'headers' => (array) ($cancel['headers'] ?? ['Accept' => 'application/json'])];
        if (isset($cancel['json'])) {
            $options['json'] = $cancel['json'];
        }
        $provider = (new ProviderHttpClient())->send((string) $cancel['endpoint'], $route, $options);
        if ($provider['status'] < 200 || $provider['status'] >= 300) {
            abort_json('TASK_NOT_CANCELLABLE', 'The vendor refused to cancel the task (it may already be running)', 409);
        }
        $taskService->fail($taskRow, 'TASK_CANCELLED', 'Task was cancelled');
        (new \TokenHub\Services\Billing\TaskBillingService())->settleByTaskId($id);

        return [...$this->task($id), 'cancelled' => true];
    }

    public function refreshTask(string $id): array
    {
        $taskService = new MultimodalTaskService();
        $taskRow = App::db()->first('SELECT * FROM multimodal_tasks WHERE task_id = ?', [$id]);
        if ($taskRow === null || !$taskService->canAccess($taskRow, App::context()->apiKey())) {
            abort_json('CONTENT_FORBIDDEN', 'Task not found or outside API key scope', 404);
        }

        $outcome = $this->refreshTaskRow($taskRow);

        return [...$this->task($id), ...$outcome];
    }

    /**
     * Polls the upstream for one task and applies the result. Used by the
     * client refresh endpoint and by the "tasks.poll" worker job, so tasks
     * reach a terminal state even if no client ever polls.
     *
     * @param array<string, mixed> $taskRow
     * @return array{refreshed: bool, refresh_reason?: string, refreshed_at?: string}
     */
    public function refreshTaskRow(array $taskRow): array
    {
        $taskService = new MultimodalTaskService();
        if (in_array((string) ($taskRow['status'] ?? ''), ['succeeded', 'failed', 'expired'], true)) {
            return ['refreshed' => false, 'refresh_reason' => 'terminal_status'];
        }

        $providerTaskId = (string) ($taskRow['provider_task_id'] ?? '');
        if ($providerTaskId === '' || empty($taskRow['model_id'])) {
            return ['refreshed' => false, 'refresh_reason' => 'provider_task_unavailable'];
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
            return ['refreshed' => false, 'refresh_reason' => 'route_unavailable'];
        }

        $capability = (string) ($taskRow['task_type'] ?? 'video_generation');
        $adapter = $this->adapterFor($route, $capability);
        $taskResult = json_decode((string) ($taskRow['result'] ?? ''), true);
        $taskResult = is_array($taskResult) ? $taskResult : [];
        $poll = $adapter instanceof AsyncTaskAdapter ? $adapter->pollRequest($route, $capability, $providerTaskId, $taskResult) : null;
        if ($poll === null) {
            return ['refreshed' => false, 'refresh_reason' => 'provider_refresh_unsupported'];
        }

        $client = new ProviderHttpClient();
        $providerResponse = $this->providerCall($client, $route, $poll);
        if ($providerResponse === null) {
            return ['refreshed' => false, 'refresh_reason' => 'provider_refresh_failed'];
        }

        $gatewayRequest = new GatewayRequest(
            endpoint: $this->taskEndpoint($capability),
            protocol: (string) ($route['protocol'] ?? 'openai'),
            capability: $capability,
            model: (string) ($route['name'] ?? ''),
            stream: false,
            payload: [],
            estimatedUsage: ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
        );
        $response = $adapter->normalizePollResponse($capability, $route, $providerResponse, $providerTaskId);
        if (($response['follow_up'] ?? false) === true && $adapter instanceof VendorAdapter) {
            // Queue vendors (fal) serve results from a second endpoint.
            $resultRequest = $adapter->resultRequest($route, $providerTaskId);
            if ($resultRequest === null) {
                return ['refreshed' => false, 'refresh_reason' => 'provider_result_fetch_failed'];
            }
            $fetched = $client->send((string) $resultRequest['endpoint'], $route, ['method' => (string) ($resultRequest['method'] ?? 'GET'), 'headers' => (array) ($resultRequest['headers'] ?? [])]);
            $resultBody = $fetched['json'] ?? json_decode((string) $fetched['body'], true);
            if ($fetched['status'] >= 400 && $fetched['status'] < 500) {
                // The job finished but its result is an error (failed fal job): terminal.
                $message = is_array($resultBody) ? (string) (is_string($resultBody['detail'] ?? null) ? $resultBody['detail'] : ($resultBody['error'] ?? 'Upstream result unavailable')) : 'Upstream result unavailable';
                $response = ['provider_task_id' => $providerTaskId, 'status' => 'failed', 'error' => ['code' => 'PROVIDER_TASK_FAILED', 'message' => mb_substr($message, 0, 500)]];
            } elseif ($fetched['status'] === 0 || $fetched['status'] >= 500 || !is_array($resultBody)) {
                return ['refreshed' => false, 'refresh_reason' => 'provider_result_fetch_failed'];
            } else {
                $response = $adapter->normalizeResultResponse($resultBody, $providerTaskId);
            }
        }
        $status = (string) ($response['status'] ?? 'processing');
        $providerTaskId = $this->providerTaskId($response) ?? $providerTaskId;

        if ($status === 'failed') {
            $taskService->fail($taskRow, (string) ($response['error']['code'] ?? 'PROVIDER_TASK_FAILED'), (string) ($response['error']['message'] ?? 'Upstream provider task failed'));
        } elseif ($status === 'succeeded') {
            $response = $this->downloadAuthenticatedAssets($client, $route, $gatewayRequest, $response);
            $assets = new MediaAssetService();
            $createdAssets = $assets->registerResponseAssets($taskRow, $gatewayRequest, $response);
            $taskService->complete($taskRow, [
                'provider_response' => $this->publicProviderResult($response),
                'asset_ids' => array_values(array_filter(array_map(static fn (array $asset): ?string => isset($asset['asset_id']) ? (string) $asset['asset_id'] : null, $createdAssets))),
                // Usage measured by the upstream at completion (actual seconds,
                // upstream cost), kept for reconciliation against the charge
                // made at submission time.
                ...(is_array($response['vendor_usage'] ?? null) ? ['settled_usage' => $response['vendor_usage']] : []),
            ], $providerTaskId);
        } else {
            $taskService->markProcessing($taskRow, ['provider_response' => $this->publicProviderResult($response)], $providerTaskId, isset($response['progress']) ? (int) $response['progress'] : null);
        }

        if (in_array($status, ['succeeded', 'failed'], true)) {
            // Refund a failed task / true-up to the measured usage.
            (new \TokenHub\Services\Billing\TaskBillingService())->settleByTaskId((string) $taskRow['task_id']);
        }

        return ['refreshed' => true, 'refreshed_at' => gmdate('Y-m-d H:i:s')];
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
        if (str_contains($request->path, ':generateContent') || str_contains($request->path, ':streamGenerateContent')) {
            return (new \TokenHub\Services\Gateway\GeminiRequestParser())->parse($request);
        }
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
            options: $request->options,
        );
    }

    /**
     * @param array<string, mixed> $route
     */
    private function adapterFor(array $route, string $capability = 'text_generation'): ProviderAdapterInterface
    {
        $spec = VendorProfiles::specFor($route, $capability);
        if ($spec !== null) {
            return new VendorAdapter($spec);
        }
        if ($this->usesVolcengineArkAdapter($route)) {
            return new VolcengineArkAdapter();
        }

        return match ((string) ($route['protocol'] ?? 'openai')) {
            'anthropic' => new AnthropicAdapter(),
            'gemini' => new \TokenHub\Services\Providers\GeminiAdapter(),
            'native' => new NativeMultimodalAdapter(),
            default => new OpenAIAdapter(),
        };
    }

    /**
     * @param array<string, mixed> $route
     */
    private function usesVolcengineArkAdapter(array $route): bool
    {
        // One definition shared with the admin model-support policy.
        return ModelSupportPolicy::isVolcengineArkRoute($route);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed>|null $ragResult
     */
    private function streamResponse(Request $request, GatewayRequest $gatewayRequest, array $candidates, RequestLifecycle $lifecycle, bool $requiresCostTracking, float $started, GatewayUsageRecorder $recorder, array $estimate, ?array $ragResult): Response
    {
        // PROTO-003: Gemini clients that did not ask for SSE get a JSON array.
        $jsonArray = ($gatewayRequest->options['gemini_json_array'] ?? false) === true;
        $headers = $jsonArray
            ? ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']
            : Response::sseHeaders();

        return Response::stream(function (?Response $response = null) use ($request, $gatewayRequest, $candidates, $lifecycle, $requiresCostTracking, $started, $recorder, $estimate, $jsonArray): void {
            $client = (new ProviderHttpClient())->onTick(static fn () => $lifecycle->renewLease());
            $health = new RouteHealth();
            $limiter = new ChannelLimiter();
            $bridge = new KimiClaudeCodeBridge();
            $normalizer = new UsageNormalizer();
            $attempts = [];
            $result = ['ok' => false, 'status' => 0, 'error_code' => 'PROVIDER_ERROR'];
            $route = $candidates[0];
            $accumulator = null;
            $bridgeState = [];
            $delivered = 0;
            $vendorMask = new PublicVendorMask();
            $maskState = [];
            $arrayStream = $jsonArray ? new GeminiJsonArrayStream() : null;
            $upstreamStarted = null;
            $timingSent = false;
            // Server-Timing goes out with the first byte: gateway time before
            // the upstream call and the upstream's time to first byte.
            $emit = function (string $bytes) use (&$timingSent, &$upstreamStarted, $started): void {
                if ($bytes === '') {
                    return;
                }
                if (!$timingSent) {
                    $timingSent = true;
                    if (!headers_sent()) {
                        $gatewayMs = (int) round((($upstreamStarted ?? microtime(true)) - $started) * 1000);
                        $ttfbMs = $upstreamStarted !== null ? (int) round((microtime(true) - $upstreamStarted) * 1000) : 0;
                        header('Server-Timing: gateway;dur=' . $gatewayMs . ', upstream-ttfb;dur=' . $ttfbMs);
                    }
                }
                echo $bytes;
            };
            // PERF-013b: SSE comment while an open stream is idle (reasoning
            // models can think for minutes before the next event).
            $heartbeat = (float) env_value('GATEWAY_SSE_HEARTBEAT_SECONDS', 15);
            if (!$jsonArray && $heartbeat > 0) {
                $client->onIdle(static function () use ($emit): void {
                    $emit(": keep-alive\n\n");
                }, $heartbeat);
            }

            $lifecycle->onAbortUsage(function () use (&$accumulator, $normalizer, $estimate): array {
                if ($accumulator === null) {
                    return [];
                }
                return $accumulator->usage() ?? $normalizer->estimated($estimate, $accumulator->outputChars());
            });

            try {
                foreach (array_slice($candidates, 0, $this->maxChannels($gatewayRequest)) as $index => $candidate) {
                    if ($index > 0 && $delivered > 0) {
                        break;
                    }
                    if ($index > 0 && !$this->switchChannel($lifecycle, $candidate, $estimate, $gatewayRequest)) {
                        continue;
                    }
                    $route = $candidate;
                    $capacity = $limiter->acquire($route, App::context()->requestId(), (int) ($estimate['total_tokens'] ?? 0));
                    if (!$capacity['ok']) {
                        $result = ['ok' => false, 'status' => 429, 'error_code' => 'CHANNEL_CAPACITY_EXCEEDED', 'retry_after' => 1];
                        $attempts[] = ['model_id' => $route['id'] ?? null, 'provider_id' => $route['provider_id'] ?? null, 'status' => 0, 'error_code' => 'CHANNEL_CAPACITY_EXCEEDED', 'reason' => $capacity['reason'] ?? null, 'tries' => 0];
                        continue;
                    }
                    $useBridge = $bridge->shouldBridge($gatewayRequest, $route) ? $bridge : null;
                    $providerRequest = $useBridge !== null ? $bridge->toOpenAIProviderRequest($gatewayRequest) : $gatewayRequest;
                    $adapter = $useBridge !== null ? new OpenAIAdapter() : $this->adapterFor($route);
                    $kind = UsageNormalizer::kindForEndpoint($providerRequest->endpoint, (string) ($route['protocol'] ?? 'openai'));
                    $accumulator = new StreamAccumulator($kind, (int) env_value('GATEWAY_STREAM_RETAIN_MAX_BYTES', 1048576));
                    $bridgeState = [];
                    $maskState = [];
                    $lifecycle->markUpstreamStarted();
                    $acc = $accumulator;
                    $upstreamStarted = microtime(true);
                    try {
                        $result = $client->stream($route, $providerRequest, $adapter, function (string $chunk) use ($gatewayRequest, $route, $useBridge, $acc, &$bridgeState, &$delivered, $vendorMask, &$maskState, $arrayStream, $emit): void {
                            $acc->push($chunk);
                            $out = $useBridge !== null ? $useBridge->transformOpenAIStreamChunk($gatewayRequest, $route, $chunk, $bridgeState) : $chunk;
                            $out = $vendorMask->streamChunk($out, $maskState);
                            if ($arrayStream !== null) {
                                $out = $arrayStream->push($out);
                            }
                            $delivered += strlen($out);
                            $emit($out);
                        });
                    } finally {
                        $this->upstreamSeconds += microtime(true) - $upstreamStarted;
                        $limiter->release($route);
                    }
                    if ($useBridge !== null && $result['ok']) {
                        $closing = $vendorMask->streamChunk($useBridge->finishStream($gatewayRequest, $route, $bridgeState), $maskState);
                        $delivered += strlen($closing);
                        $emit($closing);
                    }
                    $tail = $vendorMask->flush($maskState);
                    if ($arrayStream !== null && $tail !== '') {
                        $tail = $arrayStream->push($tail);
                    }
                    if ($tail !== '') {
                        $delivered += strlen($tail);
                        $emit($tail);
                    }
                    $accumulator->finish();
                    $attempts[] = ['model_id' => $route['id'] ?? null, 'provider_id' => $route['provider_id'] ?? null, 'status' => $result['status'], 'error_code' => $result['error_code'] ?? null, 'tries' => $result['attempts'] ?? 1];
                    if ($result['ok']) {
                        break;
                    }
                    $this->recordAttemptFailure($health, $route, $result);
                    if (!$client->failoverable($result)) {
                        break;
                    }
                }

                if (!$result['ok']) {
                    $lifecycle->settle([]);
                    $code = $result['error_code'] ?? 'PROVIDER_ERROR';
                    $httpStatus = $this->gatewayStatusFor($code, (int) $result['status']);
                    $error = $this->providerError($gatewayRequest, $route, $code, $httpStatus, $result['body'] ?? null, $result['retry_after'] ?? null);
                    // Nothing reached the client yet: answer with the real
                    // status code and a JSON error, exactly like a direct
                    // upstream call would, instead of 200 + an error event.
                    if (!($delivered === 0 && $response !== null && $response->replaceUncommittedStream($error['response']))) {
                        echo $this->streamErrorEvent($gatewayRequest, $code, $error, $httpStatus, $arrayStream);
                    }
                    $recorder->record($request, $gatewayRequest, 'failed', $code, $httpStatus, $started, $route, ['attempts' => $attempts, 'gateway_overhead_ms' => $this->timing($started)['overhead_ms']]);
                    return;
                }

                $usage = $accumulator->usage() ?? $normalizer->estimated($estimate, $accumulator->outputChars());
                $usage = $this->withPricingContext($gatewayRequest, $usage);
                $usage = $this->withCostIfNeeded($route, $usage, $requiresCostTracking);
                $lifecycle->settle($usage);

                $outcome = (string) ($result['outcome'] ?? 'eof');
                $state = $accumulator->state();
                [$status, $errorCode, $httpStatus] = match (true) {
                    $outcome === 'client_aborted' => ['failed', 'CLIENT_CANCELLED', 499],
                    $outcome === 'timeout' => ['timeout', 'PROVIDER_TIMEOUT', 504],
                    $state === 'upstream_error' => ['failed', 'PROVIDER_STREAM_ERROR', 502],
                    $state === 'incomplete' => ['failed', 'PROVIDER_STREAM_INCOMPLETE', 502],
                    default => ['success', null, 200],
                };
                if ($status === 'success') {
                    $health->recordSuccess((int) ($route['id'] ?? 0));
                    if (($usage['usage_source'] ?? null) === 'measured' && isset($usage['output_tokens'])) {
                        (new OutputTokenStats())->record((int) ($route['id'] ?? 0), (int) $usage['output_tokens']);
                    }
                } elseif ($errorCode !== 'CLIENT_CANCELLED') {
                    $health->recordFailure((int) ($route['id'] ?? 0), (string) $errorCode);
                }
                if (in_array($errorCode, ['PROVIDER_TIMEOUT', 'PROVIDER_STREAM_INCOMPLETE'], true) && $outcome !== 'client_aborted') {
                    $error = $this->providerError($gatewayRequest, $route, (string) $errorCode, $httpStatus, null, null);
                    if (!($delivered === 0 && $response !== null && $response->replaceUncommittedStream($error['response']))) {
                        echo $this->streamErrorEvent($gatewayRequest, (string) $errorCode, $error, $httpStatus, $arrayStream);
                    }
                } elseif ($arrayStream !== null && $outcome !== 'client_aborted') {
                    echo $arrayStream->finish();
                }

                $output = ['stream' => true, 'usage' => $usage, 'terminal_reason' => $accumulator->terminalReason(), 'text' => $accumulator->text(), 'text_truncated' => $accumulator->textTruncated()];
                if ($accumulator->toolCalls() !== []) {
                    $output['tool_calls'] = $accumulator->toolCalls();
                }
                if ($accumulator->error() !== null) {
                    $output['upstream_error'] = $accumulator->error();
                }
                $recorder->record($request, $gatewayRequest, $status, $errorCode, $httpStatus, $started, $route, [
                    ...$usage,
                    ...$accumulator->upstream(),
                    'attempts' => $attempts,
                    'stream_outcome' => $outcome,
                    'first_byte_ms' => $result['first_byte_ms'] ?? null,
                    'gateway_overhead_ms' => max(0, (int) round((($upstreamStarted ?? microtime(true)) - $started) * 1000)),
                ], $this->contentPayload($gatewayRequest, $output));
            } finally {
                $limiter->releaseAll();
                $lifecycle->finalizeAbandoned();
            }
        }, 200, $headers);
    }

    /**
     * Channels that can actually execute the request. "native" provider rows
     * have no execution backend (they used to return a canned 202/200), so
     * they are never used for routing.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function usableCandidates(array $candidates): array
    {
        return array_values(array_filter($candidates, static fn (array $route): bool => (string) ($route['protocol'] ?? '') !== 'native'));
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function nativeOnly(array $candidates): bool
    {
        return $candidates !== [] && $this->usableCandidates($candidates) === [];
    }

    /**
     * Responses continuation (previous_response_id) only works on the channel
     * that produced the previous response. When TokenHub recorded that
     * response, the request is pinned to the same channel and failover is off.
     *
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $apiKey
     * @return list<array<string, mixed>>
     */
    private function pinStatefulContinuation(GatewayRequest $request, array $candidates, array $apiKey): array
    {
        $previous = $request->payload['previous_response_id'] ?? null;
        if (is_string($previous) && $previous !== '' && $candidates !== []) {
            $remembered = \TokenHub\Services\Gateway\GatewayUsageRecorder::rememberedResponseChannel($previous, $apiKey['id'] ?? null);
            if ($remembered !== null) {
                foreach ($candidates as $candidate) {
                    if ((int) ($candidate['id'] ?? 0) === $remembered) {
                        return [$candidate];
                    }
                }
                return [];
            }
        }
        if (!is_string($previous) || $previous === '' || $candidates === [] || !SchemaInspector::hasColumn('usage_records', 'upstream_request_id')) {
            return $previous !== null && $candidates !== [] ? [$candidates[0]] : $candidates;
        }
        $row = App::db()->first(
            'SELECT model_id FROM usage_records WHERE upstream_request_id = ? AND api_key_id <=> ? ORDER BY id DESC LIMIT 1',
            [$previous, $apiKey['id'] ?? null]
        );
        if ($row !== null) {
            foreach ($candidates as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === (int) $row['model_id']) {
                    return [$candidate];
                }
            }
            return [];
        }

        return [$candidates[0]];
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $apiKey
     * @param list<int>|null $allowedModelIds
     * @return array{ok: bool, payload?: array<string, mixed>, rag?: array<string, mixed>|null, code?: string, message?: string, status?: int}
     */
    private function prepareOutboundPayload(GatewayRequest $request, array $route, array $apiKey, ModelRouter $router, ?array $allowedModelIds): array
    {
        $desensitization = new DesensitizationService();
        $payload = $desensitization->outbound($request->payload, $route);
        $ragResult = null;
        if (is_array($request->payload['rag'] ?? null) && ($request->payload['rag']['enabled'] ?? false) === true) {
            // Retrieved context is injected into a conversation; endpoints
            // without one (embeddings, rerank, moderations, legacy
            // completions, media) would receive a malformed payload.
            if (!in_array(UsageNormalizer::kindForEndpoint($request->endpoint, $request->protocol), [UsageNormalizer::KIND_CHAT, UsageNormalizer::KIND_RESPONSES, UsageNormalizer::KIND_ANTHROPIC], true)
                || str_contains($request->endpoint, '/count_tokens')) {
                return ['ok' => false, 'code' => 'RAG_ENDPOINT_UNSUPPORTED', 'message' => 'rag is supported on /v1/chat/completions, /v1/responses and /v1/messages only; use POST /v1/rag/search to retrieve passages directly', 'status' => 422];
            }
            $ragPayload = $payload;
            $ragPayload['rag'] = is_array($payload['rag'] ?? null) ? $payload['rag'] : $request->payload['rag'];
            $ragPayload['rag']['model_capability'] = $request->capability;
            $ragResult = (new RagRetrievalService())->retrieve($apiKey, $ragPayload);
            unset($payload['rag']);
            if (($ragResult['enabled'] ?? false) === true && !empty($ragResult['sources'])) {
                $injector = new RagPromptInjector();
                $kind = UsageNormalizer::kindForEndpoint($request->endpoint, $request->protocol);
                $payload = $injector->inject($payload, $ragResult['sources'], in_array($kind, ['anthropic', 'responses'], true) ? $kind : null);
                $ragResult['truncated_sources'] = $injector->truncatedSources();
                // Retrieved knowledge is outbound content too.
                $payload = $desensitization->outbound($payload, $route);
            }
        }
        unset($payload['rag']);

        // OpenAI-compatible streams only report usage when asked to; without
        // it every streamed request would be billed from an estimate.
        if ($request->stream && (str_contains($request->endpoint, '/chat/completions') || $request->endpoint === '/v1/completions') && filter_var(env_value('GATEWAY_FORCE_STREAM_USAGE', true), FILTER_VALIDATE_BOOL)
            && !isset($payload['stream_options']['include_usage'])) {
            $payload['stream_options'] = [...(is_array($payload['stream_options'] ?? null) ? $payload['stream_options'] : []), 'include_usage' => true];
        }

        // Routing fields (provider/models/plugins/route) are guarded on every
        // protocol: aggregators also expose Anthropic Messages (OpenRouter
        // /messages) and accept the same second-hop fields there.
        if (in_array((string) ($route['protocol'] ?? 'openai'), ['openai', 'anthropic'], true)) {
            $controls = (new ProviderHeaderVault())->decode(is_array($route['default_headers'] ?? null) || is_string($route['default_headers'] ?? null) ? $route['default_headers'] : null);
            $policy = (new AggregatorPayloadPolicy())->apply($payload, $route, $controls, function (string $name) use ($request, $router, $allowedModelIds): ?array {
                $sibling = $router->route($this->withModel($request, $name), $allowedModelIds);
                return $sibling;
            });
            if (!$policy['ok']) {
                return ['ok' => false, 'code' => $policy['code'], 'message' => $policy['message'], 'status' => $policy['code'] === 'MODEL_FORBIDDEN' ? 403 : 422];
            }
            $payload = $policy['payload'];
        }

        return ['ok' => true, 'payload' => $payload, 'rag' => $ragResult];
    }

    private function maxChannels(GatewayRequest $request): int
    {
        if (isset($request->payload['previous_response_id'])) {
            return 1;
        }

        return max(1, (int) env_value('GATEWAY_MAX_CHANNELS_PER_REQUEST', 2));
    }

    /**
     * Moves the request's model quota to another channel before failing over.
     *
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $estimate
     */
    private function switchChannel(RequestLifecycle $lifecycle, array $candidate, array $estimate, ?GatewayRequest $request = null): bool
    {
        $apiKey = App::context()->apiKey() ?? [];
        $current = $lifecycle->route();
        if ($current !== null && $request !== null && !$this->failoverCompatible($current, $candidate, $request->payload, $apiKey)) {
            return false;
        }
        $modelQuota = (new ApiKeyModelQuotaService())->preflight($apiKey, $candidate, $estimate);
        if (!$modelQuota['allowed']) {
            return false;
        }
        $lifecycle->switchModelQuota($candidate, $modelQuota['reservations'] ?? []);
        App::logger()->warning('gateway', 'failing over to next channel', ['model_id' => $candidate['id'] ?? null, 'provider_id' => $candidate['provider_id'] ?? null]);

        return true;
    }

    /**
     * The outbound payload was prepared (desensitized, routing-policy merged)
     * for the first channel. Failing over is only allowed when that
     * preparation is equally valid for the next channel: same provider, or no
     * provider-specific routing fields and identical desensitization policies.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $apiKey
     */
    private function failoverCompatible(array $current, array $candidate, array $payload, array $apiKey): bool
    {
        if ((string) ($current['protocol'] ?? '') !== (string) ($candidate['protocol'] ?? '')) {
            return false;
        }
        $desensitization = new DesensitizationService();
        $policyIds = static fn (array $route): array => array_map(static fn (array $policy): int => (int) ($policy['id'] ?? 0), $desensitization->policiesFor($apiKey, $route));
        if ($policyIds($current) !== $policyIds($candidate)) {
            return false;
        }
        if ((int) ($current['provider_id'] ?? 0) === (int) ($candidate['provider_id'] ?? -1)) {
            return true;
        }
        foreach (['models', 'provider', 'plugins', 'route', 'transforms'] as $field) {
            if (array_key_exists($field, $payload)) {
                return false;
            }
        }
        $vault = new ProviderHeaderVault();
        foreach ([$current, $candidate] as $route) {
            $controls = $vault->decode(is_array($route['default_headers'] ?? null) || is_string($route['default_headers'] ?? null) ? $route['default_headers'] : null);
            if (!empty($controls['tokenhub_provider_policy'])) {
                return false;
            }
        }

        return true;
    }

    private function gatewayStatusFor(string $code, int $upstreamStatus): int
    {
        return match ($code) {
            'PROVIDER_TIMEOUT' => 504,
            'PROVIDER_RATE_LIMITED', 'CHANNEL_CAPACITY_EXCEEDED' => 429,
            'OUTBOUND_BLOCKED' => 502,
            'PROVIDER_ENDPOINT_UNSUPPORTED' => 501,
            'PROVIDER_REJECTED' => in_array($upstreamStatus, [400, 404, 413, 422], true) ? $upstreamStatus : 502,
            default => 502,
        };
    }

    /**
     * Client-facing error for a failed upstream call. Request-level
     * rejections (400/404/413/422) carry the upstream's sanitized type, code,
     * param and message (see UpstreamErrorDetail); everything else keeps the
     * generic TokenHub message. Anthropic-protocol endpoints get the
     * Anthropic error shape ("type": "error", error.type) that their SDKs and
     * Claude Code parse.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed>|string|null $upstreamBody
     * @return array{response: Response, message: string, type: string}
     */
    private function providerError(GatewayRequest $request, array $route, string $code, int $status, array|string|null $upstreamBody, int|string|null $retryAfter): array
    {
        $detail = $code === 'PROVIDER_REJECTED' ? UpstreamErrorDetail::extract($upstreamBody, $status, $route) : null;
        $message = $detail['message'] ?? $this->providerErrorMessage($code);
        $fields = [];
        if (isset($detail['type'])) {
            $fields['type'] = $detail['type'];
        }
        if (isset($detail['param'])) {
            $fields['param'] = $detail['param'];
        }
        if (isset($detail['code'])) {
            $fields['upstream_code'] = $detail['code'];
        }
        $top = [];
        $type = $detail['type'] ?? 'upstream_error';
        if ($request->protocol === 'anthropic') {
            $type = UpstreamErrorDetail::anthropicType($status, $detail['type'] ?? null);
            $fields['type'] = $type;
            $top = ['type' => 'error'];
        } elseif (!isset($fields['type'])) {
            // QUAL-007: OpenAI SDKs expect error.type on every error.
            $type = self::openAiErrorType($code, $status);
            $fields['type'] = $type;
        }
        $response = Response::errorWith($code, $message, $status, $fields, $top);
        if ($retryAfter !== null && $retryAfter !== '' && (int) $retryAfter > 0) {
            $response = $response->withHeader('Retry-After', (string) (int) $retryAfter);
        }

        return ['response' => $response, 'message' => Response::redactMessage($message), 'type' => $type];
    }

    /**
     * In-band error for a stream that already started, in the framing the
     * client protocol's SDK understands (QUAL-007):
     * - Anthropic: "event: error" + {"type":"error","error":{...}}
     * - OpenAI-compatible: a bare "data: {"error":{...}}" event followed by
     *   "data: [DONE]" (the OpenAI SDKs raise APIError on it)
     * - Gemini: "data: {"error":{code,message,status}}", or the last element
     *   of the JSON array for non-SSE clients.
     *
     * @param array{response: Response, message: string, type: string} $error
     */
    private function streamErrorEvent(GatewayRequest $request, string $code, array $error, int $status = 502, ?GeminiJsonArrayStream $arrayStream = null): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($request->protocol === 'anthropic') {
            return 'event: error' . "\n"
                . 'data: ' . json_encode(['type' => 'error', 'error' => ['code' => $code, 'type' => $error['type'], 'message' => $error['message']]], $flags) . "\n\n";
        }
        if ($request->protocol === 'gemini') {
            $body = ['code' => $status, 'message' => $error['message'], 'status' => $code];
            if ($arrayStream !== null) {
                return $arrayStream->error($body);
            }
            return 'data: ' . json_encode(['error' => $body], $flags) . "\n\n";
        }
        $type = $error['type'] !== 'upstream_error' ? $error['type'] : self::openAiErrorType($code, $status);

        return 'data: ' . json_encode(['error' => ['message' => $error['message'], 'type' => $type, 'code' => $code]], $flags) . "\n\n"
            . "data: [DONE]\n\n";
    }

    private function providerErrorMessage(string $code): string
    {
        return match ($code) {
            'PROVIDER_TIMEOUT' => 'Upstream provider timed out',
            'PROVIDER_RATE_LIMITED' => 'Upstream provider rate limited the request',
            'OUTBOUND_BLOCKED' => 'Upstream address is not allowed by the outbound policy',
            'PROVIDER_ENDPOINT_UNSUPPORTED' => 'The selected channel does not support this endpoint',
            'PROVIDER_REJECTED' => 'Upstream provider rejected the request',
            'PROVIDER_STREAM_INCOMPLETE' => 'Upstream stream ended before completion',
            'CHANNEL_CAPACITY_EXCEEDED' => 'Every channel for this model is at its capacity limit; retry shortly',
            default => 'Upstream provider request failed',
        };
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, string>
     */
    private function upstreamMetadata(array $response): array
    {
        $meta = [];
        foreach (['id' => 'upstream_request_id', 'model' => 'upstream_model', 'provider' => 'upstream_provider'] as $source => $target) {
            if (isset($response[$source]) && is_scalar($response[$source]) && (string) $response[$source] !== '') {
                $meta[$target] = (string) $response[$source];
            }
        }

        return $meta;
    }

    /**
     * Legacy adapter usage (media metrics) with protocol-normalized token
     * usage on top; falls back to an explicitly "estimated" usage.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $estimate
     * @return array<string, mixed>
     */
    private function usageFor(ProviderAdapterInterface $adapter, GatewayRequest $request, array $response, string $kind, array $estimate): array
    {
        $usage = $adapter->usage($request, $response);
        $measured = (new UsageNormalizer())->fromResponse($kind, $response);
        if ($measured !== null) {
            return [...$usage, ...$measured];
        }
        $usage['output_tokens'] = (int) ($usage['output_tokens'] ?? 0);
        $usage['input_tokens'] = (int) ($usage['input_tokens'] ?? ($estimate['input_tokens'] ?? 0));
        $usage['total_tokens'] = max((int) ($usage['total_tokens'] ?? 0), $usage['input_tokens'] + $usage['output_tokens']);
        $usage['usage_source'] = 'estimated';

        return $usage;
    }

    private function withModel(GatewayRequest $request, string $model): GatewayRequest
    {
        return new GatewayRequest(
            endpoint: $request->endpoint,
            protocol: $request->protocol,
            capability: $request->capability,
            model: $model,
            stream: false,
            payload: [],
            estimatedUsage: $request->estimatedUsage,
        );
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
        $public = $this->publicProviderResult($response);
        if (isset($response['data']) && is_array($response['data']) && $request->capability === 'image_generation') {
            $public['data'] = $this->publicMediaData($response['data'], $createdAssets, $assets);
        }
        // TTS JSON envelope (X-TokenHub-Response-Mode: json) carries the audio.
        if ($request->capability === 'tts' && !empty($response['b64_json']) && is_string($response['b64_json'])
            && filter_var(env_value('GATEWAY_MEDIA_INLINE_RESPONSE', true), FILTER_VALIDATE_BOOL)) {
            $public['b64_json'] = $response['b64_json'];
        }

        return [
            ...$public,
            'task_id' => $task['task_id'],
            'status' => $response['status'] ?? 'succeeded',
            'assets' => $publicAssets,
        ];
    }

    /**
     * OpenAI images contract: every data item carries either "url" or
     * "b64_json". Upstream URLs are never exposed; a url item gets a
     * short-lived TokenHub download link for its registered asset, and
     * b64_json items keep their bytes (the client asked for them, or the
     * model only returns base64). GATEWAY_MEDIA_INLINE_RESPONSE=false
     * restores the asset-only response (clients then use
     * /v1/assets/{id}/download-url).
     *
     * @param list<mixed> $data
     * @param list<array<string, mixed>> $createdAssets
     * @return list<mixed>
     */
    private function publicMediaData(array $data, array $createdAssets, MediaAssetService $assets): array
    {
        $inline = filter_var(env_value('GATEWAY_MEDIA_INLINE_RESPONSE', true), FILTER_VALIDATE_BOOL);
        $ttl = max(60, (int) env_value('GATEWAY_MEDIA_URL_TTL_SECONDS', 3600));
        $byIndex = [];
        foreach ($createdAssets as $asset) {
            if (isset($asset['data_index'], $asset['asset_id'])) {
                $byIndex[(int) $asset['data_index']] = $asset;
            }
        }
        $out = [];
        foreach (array_values($data) as $index => $item) {
            if (!is_array($item)) {
                $out[] = $item;
                continue;
            }
            $public = $item;
            unset($public['url'], $public['b64_json'], $public['storage_path']);
            if ($inline && !empty($item['b64_json']) && is_string($item['b64_json'])) {
                $public['b64_json'] = $item['b64_json'];
            } elseif ($inline && !empty($item['url']) && isset($byIndex[$index])) {
                $public['url'] = $assets->createDownloadUrl($byIndex[$index], $ttl)['download_url'];
            }
            if (isset($byIndex[$index]['asset_id'])) {
                $public['asset_id'] = (string) $byIndex[$index]['asset_id'];
            }
            $out[] = $public;
        }

        return $out;
    }

    /**
     * @return array{text: string, usage: array<string, mixed>}|null
     */
    private function transcriptionStreamUsage(string $body): ?array
    {
        if (!str_contains($body, 'transcript.text.')) {
            return null;
        }
        $text = '';
        $usage = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $event = json_decode(trim(substr($line, 5)), true);
            if (!is_array($event)) {
                continue;
            }
            if (($event['type'] ?? '') === 'transcript.text.done') {
                $text = (string) ($event['text'] ?? $text);
                $usage = is_array($event['usage'] ?? null) ? $event['usage'] : $usage;
            } elseif (($event['type'] ?? '') === 'transcript.text.delta') {
                $text .= (string) ($event['delta'] ?? '');
            }
        }

        return ['text' => $text, 'usage' => $usage];
    }

    /**
     * Audio length of an SRT/VTT transcript (last cue end), for billing
     * transcriptions whose response carries no duration field.
     */
    private function subtitleDurationSeconds(string $text): ?int
    {
        if (preg_match_all('/(\d{1,2}):(\d{2}):(\d{2})[,.](\d{3})/', $text, $matches, PREG_SET_ORDER) < 1) {
            return null;
        }
        $max = 0.0;
        foreach ($matches as $match) {
            $max = max($max, (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3] + (int) $match[4] / 1000);
        }

        return (int) ceil($max);
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
     * One upstream call described by an adapter (poll / result fetch / authenticated download).
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $call
     * @return array<string, mixed>|null decoded JSON body, null on failure
     */
    private function providerCall(ProviderHttpClient $client, array $route, array $call): ?array
    {
        $options = ['method' => (string) ($call['method'] ?? 'GET'), 'headers' => (array) ($call['headers'] ?? ['Accept' => 'application/json'])];
        if (isset($call['json'])) {
            $options['json'] = $call['json'];
        }
        $provider = $client->send((string) $call['endpoint'], $route, $options);
        if ($provider['status'] === 0 || $provider['status'] >= 400) {
            App::logger()->warning('provider', 'provider task call failed', ['model_id' => $route['id'] ?? null, 'status' => $provider['status'], 'error_code' => $provider['error_code'] ?? null]);
            return null;
        }
        $decoded = $provider['json'] ?? json_decode((string) $provider['body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Results that only download with the channel credential (OpenRouter
     * video content) are fetched here and handed to the asset store as bytes.
     *
     * @param array<string, mixed> $route
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function downloadAuthenticatedAssets(ProviderHttpClient $client, array $route, GatewayRequest $request, array $response): array
    {
        if (!is_array($response['assets'] ?? null)) {
            return $response;
        }
        $maxBytes = (int) env_value('OUTBOUND_DOWNLOAD_MAX_BYTES', 104857600);
        foreach ($response['assets'] as $index => $asset) {
            if (!is_array($asset) || !is_array($asset['download'] ?? null)) {
                continue;
            }
            $download = $client->send((string) $asset['download']['endpoint'], $route, ['method' => 'GET', 'headers' => ['Accept' => '*/*']]);
            unset($response['assets'][$index]['download']);
            if ($download['status'] >= 200 && $download['status'] < 300 && $download['body'] !== '' && strlen($download['body']) <= $maxBytes) {
                $response['assets'][$index]['b64_json'] = base64_encode($download['body']);
                $type = strtolower(trim(explode(';', (string) ($download['headers']['content-type'] ?? ''))[0]));
                if (preg_match('#^(video|image|audio)/#', $type) === 1) {
                    $response['assets'][$index]['mime_type'] = $type;
                }
            } else {
                unset($response['assets'][$index]);
            }
        }
        $response['assets'] = array_values($response['assets']);

        return $response;
    }

    private function taskEndpoint(string $capability): string
    {
        return match ($capability) {
            'image_generation' => '/v1/images/generations',
            'tts' => '/v1/audio/speech',
            'asr' => '/v1/audio/transcriptions',
            'music_generation' => '/v1/music/generations',
            default => '/v1/videos/generations',
        };
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

    /**
     * Request attributes and token modalities the pricing rules may need.
     * Image generation output is image tokens when the upstream reports
     * tokens (gpt-image, Gemini image models).
     *
     * @param array<string, mixed> $usage
     * @return array<string, mixed>
     */
    private function withPricingContext(GatewayRequest $request, array $usage): array
    {
        $dimensions = PricingDimensions::fromPayload($request->capability, $request->payload);
        if ($dimensions !== []) {
            $usage['pricing_dimensions'] = $dimensions;
        }
        if ($request->capability === 'image_generation' && ($usage['usage_source'] ?? null) === 'measured'
            && !isset($usage['image_output_tokens']) && (int) ($usage['output_tokens'] ?? 0) > 0) {
            $usage['image_output_tokens'] = (int) $usage['output_tokens'];
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
