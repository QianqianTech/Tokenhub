<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Request;

final class GatewayUsageRecorder
{
    /**
     * @param array<string, mixed>|null $route
     * @param array<string, int|float|string|null> $usage
     * @param array<string, mixed>|null $content
     */
    public function record(Request $httpRequest, GatewayRequest $gatewayRequest, string $status, ?string $errorCode, int $httpStatus, float $started, ?array $route = null, array $usage = [], ?array $content = null): void
    {
        $apiKey = App::context()->apiKey() ?? [];
        $usage += [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'audio_seconds' => 0,
            'tts_chars' => 0,
            'image_count' => 0,
            'video_seconds' => 0,
        ];

        self::rememberResponseChannel($usage['upstream_request_id'] ?? null, $route, $apiKey, $status);

        $record = [
            'request_id' => App::context()->requestId(),
            'trace_id' => App::context()->traceId(),
            'user_id' => $apiKey['user_id'] ?? null,
            'department_id' => $apiKey['department_id'] ?? null,
            'api_key_id' => $apiKey['id'] ?? null,
            'provider_id' => $route['provider_id'] ?? null,
            'model_id' => $route['id'] ?? null,
            'model_name' => $route['name'] ?? $gatewayRequest->model,
            'protocol' => $gatewayRequest->protocol,
            'endpoint' => $gatewayRequest->endpoint,
            'capability' => $gatewayRequest->capability,
            'status' => $status,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            ...$usage,
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            'client_ip' => $httpRequest->clientIp(),
            // Business time is fixed by the producer: the consumer may run much
            // later (queue backlog) and must not move the request to another
            // day or price it with a later price version.
            'created_at' => gmdate('Y-m-d H:i:s', (int) floor($started)),
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'usage_source' => $usage['usage_source'] ?? ($status === 'success' ? 'estimated' : 'none'),
        ];

        if ($content !== null) {
            // Prompt/response bodies never sit in Redis in clear text: the
            // queue carries ciphertext, the consumer applies the retention
            // policy (none/summary/redacted/raw) before anything is stored.
            $content['input'] = MultipartUpload::describe(is_array($content['input'] ?? null) ? $content['input'] : []);
            $record['content_encrypted'] = Crypto::encrypt((string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), ['type' => 'usage.write', 'record' => $record]);
        $logRecord = $record;
        unset($logRecord['content'], $logRecord['content_encrypted']);
        App::logger()->info('gateway-access', 'gateway request', $logRecord);
    }

    private const RESPONSE_CHANNEL_TTL_SECONDS = 86400;

    /**
     * Remembers which channel produced an upstream response id right away, so
     * a follow-up request with previous_response_id (agent loops send it within
     * milliseconds) is pinned to the same channel even before the usage record
     * is written by the queue worker.
     *
     * @param array<string, mixed>|null $route
     * @param array<string, mixed> $apiKey
     */
    public static function rememberResponseChannel(mixed $upstreamId, ?array $route, array $apiKey, string $status): void
    {
        if (!is_string($upstreamId) || $upstreamId === '' || !isset($route['id']) || $status !== 'success') {
            return;
        }
        try {
            App::redis()->set(self::responseChannelKey($upstreamId, $apiKey['id'] ?? null), (string) (int) $route['id'], self::RESPONSE_CHANNEL_TTL_SECONDS);
        } catch (\Throwable) {
            // Best effort: the usage record remains the fallback.
        }
    }

    public static function rememberedResponseChannel(string $upstreamId, mixed $apiKeyId): ?int
    {
        try {
            $value = App::redis()->get(self::responseChannelKey($upstreamId, $apiKeyId));
        } catch (\Throwable) {
            return null;
        }

        return is_scalar($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function responseChannelKey(string $upstreamId, mixed $apiKeyId): string
    {
        return 'resp_channel:' . (int) $apiKeyId . ':' . hash('sha256', $upstreamId);
    }
}
