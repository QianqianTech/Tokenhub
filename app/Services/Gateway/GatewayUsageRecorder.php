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
}
