<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Providers\ProviderAdapterInterface;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ProviderHttpClient
{
    public function endpointFor(string $path): string
    {
        return match (true) {
            str_contains($path, '/chat/completions') => '/v1/chat/completions',
            str_contains($path, '/responses') => '/v1/responses',
            str_contains($path, '/embeddings') => '/v1/embeddings',
            str_contains($path, '/messages/count_tokens') => '/v1/messages/count_tokens',
            str_contains($path, '/messages') => '/v1/messages',
            str_contains($path, '/audio/transcriptions') => '/v1/audio/transcriptions',
            str_contains($path, '/audio/speech') => '/v1/audio/speech',
            str_contains($path, '/images/generations') => '/v1/images/generations',
            str_contains($path, '/videos/generations') => '/v1/videos/generations',
            default => $path,
        };
    }

    /**
     * @param array<string, mixed> $route
     * @param array{method?: string, headers?: array<string, string>, json?: array<string, mixed>, body?: string} $request
     * @return array{url: string, method: string, headers: array<string, string>, body: string, timeout: float}
     */
    public function prepareRequest(string $endpoint, array $route, array $request): array
    {
        $headers = $this->decodeHeaders($route['default_headers'] ?? null);
        foreach (($request['headers'] ?? []) as $name => $value) {
            $headers[$name] = $value;
        }
        $headers = $this->publicHeaders($headers);

        if (!empty($route['encrypted_secret']) && !isset($headers['Authorization']) && !isset($headers['authorization']) && !isset($headers['x-api-key'])) {
            $secret = Crypto::decrypt((string) $route['encrypted_secret']);
            if ($secret !== '') {
                if (($route['protocol'] ?? '') === 'anthropic') {
                    $headers['x-api-key'] = $secret;
                    $headers['anthropic-version'] ??= '2023-06-01';
                } else {
                    $headers['Authorization'] = 'Bearer ' . $secret;
                }
            }
        }

        $method = strtoupper((string) ($request['method'] ?? 'POST'));
        $body = $method === 'GET' ? '' : ($request['body'] ?? json_encode($request['json'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'url' => $this->providerUrl((string) $route['base_url'], $endpoint),
            'method' => $method,
            'headers' => $headers,
            'body' => (string) $body,
            'timeout' => max(0.1, ((int) ($route['timeout_ms'] ?? 60000)) / 1000),
        ];
    }

    /**
     * @param array<string, mixed> $route
     * @param array{headers?: array<string, string>, json?: array<string, mixed>, body?: string} $request
     * @return array{status: int, body: string, json: array<string, mixed>|null}
     */
    public function send(string $endpoint, array $route, array $request): array
    {
        $prepared = $this->prepareRequest($endpoint, $route, $request);
        $headerLines = [];
        foreach ($prepared['headers'] as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $prepared['method'],
                'header' => implode("\r\n", $headerLines),
                'timeout' => $prepared['timeout'],
                'ignore_errors' => true,
            ],
        ]);
        if ($prepared['method'] !== 'GET') {
            $contextOptions = stream_context_get_options($context);
            $contextOptions['http']['content'] = $prepared['body'];
            $context = stream_context_create($contextOptions);
        }

        $body = @file_get_contents($prepared['url'], false, $context);
        $status = $this->statusCode($http_response_header ?? []);
        $decoded = is_string($body) ? json_decode($body, true) : null;

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: int, body: string, json: array<string, mixed>|null, error_code?: string}
     */
    public function request(array $route, string $path, array $payload, bool $stream = false): array
    {
        if (isset($route['external_name']) && $route['external_name'] !== '') {
            $payload['model'] = $route['external_name'];
        }
        $response = $this->send($this->endpointFor($path), $route, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => $stream ? 'text/event-stream' : 'application/json',
            ],
            'json' => $payload,
        ]);

        if ($response['status'] === 0) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error_code' => 'PROVIDER_TIMEOUT'];
        }

        if ($response['status'] >= 400) {
            return [
                'ok' => false,
                'status' => $response['status'],
                'body' => $response['body'],
                'json' => $response['json'],
                'error_code' => $response['status'] >= 500 ? 'PROVIDER_ERROR' : 'PROVIDER_REJECTED',
            ];
        }

        return ['ok' => true, ...$response];
    }

    /**
     * @param array<string, mixed> $route
     * @return array{ok: bool, status: int, body: string, json: array<string, mixed>|null, attempts: int, error_code?: string}
     */
    public function sendJson(array $route, GatewayRequest $gatewayRequest, ProviderAdapterInterface $adapter, ?string $idempotencyKey = null): array
    {
        $request = $adapter->buildRequest($gatewayRequest, $route);
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $request['headers'] = [...($request['headers'] ?? []), 'Idempotency-Key' => $idempotencyKey];
        }
        $endpoint = isset($request['endpoint']) && is_string($request['endpoint'])
            ? $request['endpoint']
            : $this->endpointFor($gatewayRequest->endpoint);
        unset($request['endpoint']);
        $attempts = max(1, (int) ($route['retry_count'] ?? 1));
        $last = ['status' => 0, 'body' => '', 'json' => null];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $started = microtime(true);
            $last = $this->send($endpoint, $route, $request);
            App::logger()->info('provider', 'provider request', [
                'provider_id' => $route['provider_id'] ?? null,
                'model_id' => $route['id'] ?? null,
                'status' => $last['status'],
                'attempt' => $attempt,
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            if ($last['status'] > 0 && $last['status'] < 500) {
                break;
            }
        }

        if ($last['status'] === 0) {
            return ['ok' => false, ...$last, 'attempts' => $attempts, 'error_code' => 'PROVIDER_TIMEOUT'];
        }

        if ($last['status'] >= 400) {
            return [
                'ok' => false,
                ...$last,
                'attempts' => $attempts,
                'error_code' => $last['status'] >= 500 ? 'PROVIDER_ERROR' : 'PROVIDER_REJECTED',
            ];
        }

        return ['ok' => true, ...$last, 'attempts' => $attempts];
    }

    /**
     * @param array<string, mixed> $route
     * @param callable(string): void $onChunk
     * @return array{ok: bool, status: int, attempts: int, error_code?: string}
     */
    public function stream(array $route, GatewayRequest $gatewayRequest, ProviderAdapterInterface $adapter, callable $onChunk): array
    {
        $endpoint = $this->endpointFor($gatewayRequest->endpoint);
        $request = $adapter->buildRequest($gatewayRequest, $route);
        $attempts = 1;
        $lastStatus = 0;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $prepared = $this->prepareRequest($endpoint, $route, $request);
            $headerLines = [];
            foreach ($prepared['headers'] as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headerLines),
                    'content' => $prepared['body'],
                    'timeout' => $prepared['timeout'],
                    'ignore_errors' => true,
                ],
            ]);

            $started = microtime(true);
            $stream = @fopen($prepared['url'], 'rb', false, $context);
            $lastStatus = $this->statusCode($http_response_header ?? []);
            App::logger()->info('provider', 'provider stream opened', [
                'provider_id' => $route['provider_id'] ?? null,
                'model_id' => $route['id'] ?? null,
                'status' => $lastStatus,
                'attempt' => $attempt,
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            if (!is_resource($stream)) {
                continue;
            }

            if ($lastStatus >= 500) {
                fclose($stream);
                continue;
            }

            if ($lastStatus >= 400 || $lastStatus === 0) {
                fclose($stream);
                break;
            }

            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $onChunk($chunk);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
            fclose($stream);
            return ['ok' => true, 'status' => $lastStatus, 'attempts' => $attempt];
        }

        if ($lastStatus === 0) {
            return ['ok' => false, 'status' => 0, 'attempts' => $attempts, 'error_code' => 'PROVIDER_TIMEOUT'];
        }

        return [
            'ok' => false,
            'status' => $lastStatus,
            'attempts' => $attempts,
            'error_code' => $lastStatus >= 500 ? 'PROVIDER_ERROR' : 'PROVIDER_REJECTED',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function decodeHeaders(mixed $headers): array
    {
        if (is_array($headers)) {
            return $this->publicHeaders($headers);
        }

        if (!is_string($headers) || $headers === '') {
            return [];
        }

        $decoded = json_decode($headers, true);
        return is_array($decoded) ? $this->publicHeaders($decoded) : [];
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private function publicHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);
            if (in_array($key, ['cf-connecting-ip', 'forwarded', 'true-client-ip', 'x-forwarded-for', 'x-real-ip'], true)) {
                continue;
            }
            if ($key === 'tokenhub_adapter' || str_starts_with($key, 'tokenhub_')) {
                continue;
            }
            if (is_scalar($value)) {
                $out[(string) $name] = (string) $value;
            }
        }

        return $out;
    }

    private function providerUrl(string $baseUrl, string $endpoint): string
    {
        $base = rtrim($baseUrl, '/');
        $path = '/' . ltrim($endpoint, '/');
        if ((preg_match('~/v1$~i', $base) === 1 || preg_match('~/api/v3$~i', $base) === 1) && str_starts_with($path, '/v1/')) {
            $path = substr($path, 3);
        }

        return $base . $path;
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
