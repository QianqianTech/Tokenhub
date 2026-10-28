<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Providers\ProviderAdapterInterface;
use TokenHub\Services\Providers\ProviderAuth;
use TokenHub\Services\Providers\ProviderEndpointResolver;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ProviderHttpClient
{
    public function __construct(private readonly ?OutboundUrlPolicy $outboundPolicy = null)
    {
    }

    public function endpointFor(string $path): string
    {
        return match (true) {
            str_contains($path, '/chat/completions') => '/v1/chat/completions',
            str_ends_with($path, '/completions') => '/v1/completions',
            str_contains($path, '/responses') => '/v1/responses',
            str_contains($path, '/embeddings') => '/v1/embeddings',
            str_contains($path, '/rerank') => '/v1/rerank',
            str_contains($path, '/moderations') => '/v1/moderations',
            str_contains($path, '/messages/count_tokens') => '/v1/messages/count_tokens',
            str_contains($path, '/messages') => '/v1/messages',
            str_contains($path, '/audio/transcriptions') => '/v1/audio/transcriptions',
            str_contains($path, '/audio/translations') => '/v1/audio/translations',
            str_contains($path, '/audio/speech') => '/v1/audio/speech',
            str_contains($path, '/images/generations') => '/v1/images/generations',
            str_contains($path, '/images/edits') => '/v1/images/edits',
            str_contains($path, '/videos/generations'), $path === '/v1/videos' => '/v1/videos/generations',
            str_contains($path, '/music/generations') => '/v1/music/generations',
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

        $method = strtoupper((string) ($request['method'] ?? 'POST'));
        $body = $method === 'GET' ? '' : ($request['body'] ?? json_encode($request['json'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $target = $this->target($route, $endpoint, false);
        $url = (string) ($target['url'] ?? '');

        $lowerNames = array_map('strtolower', array_keys($headers));
        if (!empty($route['encrypted_secret']) && !in_array('authorization', $lowerNames, true) && !in_array('x-api-key', $lowerNames, true) && $url !== '') {
            $secret = Crypto::decrypt((string) $route['encrypted_secret']);
            if ($secret !== '') {
                try {
                    $authed = (new ProviderAuth())->apply($this->authScheme($route), $secret, $method, $url, (string) $body, $headers, (string) ($route['protocol'] ?? 'openai'));
                    $headers = $authed['headers'];
                    $url = $authed['url'];
                } catch (\RuntimeException $exception) {
                    App::logger()->warning('provider', 'provider auth failed', ['provider_id' => $route['provider_id'] ?? null, 'error' => $exception->getMessage()]);
                    $target['error_code'] ??= 'PROVIDER_AUTH_FAILED';
                }
            }
        }

        return [
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'body' => (string) $body,
            'timeout' => max(0.1, ((int) ($route['timeout_ms'] ?? 60000)) / 1000),
            'error_code' => $target['error_code'] ?? null,
        ];
    }

    /**
     * Resolves the upstream URL and applies the outbound policy.
     *
     * @param array<string, mixed> $route
     * @return array{url?: string, error_code?: string, reason?: string}
     */
    public function target(array $route, string $endpoint, bool $enforceEgress = true): array
    {
        if (preg_match('#^https?://#i', $endpoint) === 1) {
            // Absolute URLs (vendor polling/content URLs) only on the channel's
            // own host family: credentials must never follow a foreign URL.
            if (!$this->sameHostFamily((string) ($route['base_url'] ?? ''), $endpoint)) {
                return ['error_code' => 'OUTBOUND_BLOCKED', 'reason' => 'foreign_host'];
            }
            return $enforceEgress ? $this->enforceEgress($route, $endpoint) : ['url' => $endpoint];
        }
        $controls = (new ProviderHeaderVault())->decode(is_array($route['default_headers'] ?? null) || is_string($route['default_headers'] ?? null) ? $route['default_headers'] : null);
        $resolved = (new ProviderEndpointResolver())->resolve((string) ($route['base_url'] ?? ''), $endpoint, $controls);
        if (!$resolved['ok']) {
            return ['error_code' => 'PROVIDER_ENDPOINT_UNSUPPORTED', 'reason' => $resolved['reason'] ?? 'unsupported'];
        }

        $url = (string) $resolved['url'];
        if (!$enforceEgress) {
            return ['url' => $url];
        }

        return $this->enforceEgress($route, $url);
    }

    /**
     * @param array<string, mixed> $route
     * @return array{url?: string, error_code?: string, reason?: string}
     */
    public function enforceEgress(array $route, string $url): array
    {
        $controls = (new ProviderHeaderVault())->decode(is_array($route['default_headers'] ?? null) || is_string($route['default_headers'] ?? null) ? $route['default_headers'] : null);
        $allowPrivate = filter_var($controls['tokenhub_allow_private_network'] ?? false, FILTER_VALIDATE_BOOL);
        $check = $this->egressCheck($url, $allowPrivate);
        if (!$check['ok']) {
            App::logger()->warning('provider', 'provider egress refused', [
                'provider_id' => $route['provider_id'] ?? null,
                'host' => $check['host'] ?? null,
                'reason' => $check['reason'] ?? null,
            ]);
            return ['error_code' => 'OUTBOUND_BLOCKED', 'reason' => $check['reason'] ?? 'denied'];
        }

        return ['url' => $url];
    }

    /**
     * @return array{ok: bool, reason?: string, host?: string}
     */
    private function egressCheck(string $url, bool $allowPrivate): array
    {
        if ($this->outboundPolicy !== null) {
            // Injected policies (tests, custom callers) are evaluated directly.
            $result = $this->outboundPolicy->check($url, OutboundUrlPolicy::PURPOSE_PROVIDER, ['allow_private' => $allowPrivate]);
            return ['ok' => $result['ok'], 'reason' => $result['reason'] ?? null, 'host' => $result['host'] ?? null];
        }
        $policy = new OutboundUrlPolicy();
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $cacheKey = 'egress:provider:' . hash('sha256', $host . '|' . ($allowPrivate ? '1' : '0') . '|' . (string) env_value('OUTBOUND_PRIVATE_ALLOWLIST', ''));
        try {
            $cached = App::redis()->get($cacheKey);
        } catch (\Throwable) {
            $cached = null;
        }
        if (is_array($cached) && isset($cached['ok'])) {
            return $cached;
        }
        $result = $policy->check($url, OutboundUrlPolicy::PURPOSE_PROVIDER, ['allow_private' => $allowPrivate]);
        $summary = ['ok' => $result['ok'], 'reason' => $result['reason'] ?? null, 'host' => $host];
        try {
            App::redis()->set($cacheKey, $summary, 60);
        } catch (\Throwable) {
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $route
     * @param array{headers?: array<string, string>, json?: array<string, mixed>, body?: string} $request
     * @return array{status: int, body: string, json: array<string, mixed>|null}
     */
    public function send(string $endpoint, array $route, array $request): array
    {
        $prepared = $this->prepareRequest($endpoint, $route, $request);
        if ($prepared['error_code'] === null) {
            $prepared['error_code'] = $this->enforceEgress($route, $prepared['url'])['error_code'] ?? null;
        }
        if ($prepared['error_code'] !== null) {
            return ['status' => 0, 'body' => '', 'json' => null, 'error_code' => $prepared['error_code']];
        }
        $transport = $this->transportSend($prepared);
        $responseHeaders = $transport['header_lines'];
        $body = $transport['body'];
        $status = $transport['error'] !== null ? 0 : $this->statusCode($responseHeaders);
        $decoded = is_string($body) && $status !== 0 ? json_decode($body, true) : null;

        return [
            'status' => $status,
            'body' => is_string($body) && $status !== 0 ? $body : '',
            'json' => is_array($decoded) ? $decoded : null,
            'headers' => $this->headerMap($responseHeaders),
            ...($transport['error'] !== null ? ['transport_error' => $transport['error']] : []),
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
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error_code' => $response['error_code'] ?? 'PROVIDER_TIMEOUT'];
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
     * Non-streaming upstream call with bounded retries.
     *
     * Retries connection failures, 429 and 5xx up to route.retry_count total
     * attempts. Policy refusals (OUTBOUND_BLOCKED, PROVIDER_ENDPOINT_UNSUPPORTED)
     * are never retried. Every attempt is returned in attempt_log so the gateway
     * can record what actually happened upstream.
     *
     * @param array<string, mixed> $route
     * @return array{ok: bool, status: int, body: string, json: array<string, mixed>|null, attempts: int, attempt_log: list<array<string, mixed>>, headers?: array<string, string>, error_code?: string, retry_after?: int|null}
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
        $log = [];
        $attempt = 0;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $started = microtime(true);
            $last = $this->send($endpoint, $route, $request);
            $latency = (int) ((microtime(true) - $started) * 1000);
            $log[] = ['attempt' => $attempt, 'status' => $last['status'], 'latency_ms' => $latency, 'error_code' => $last['error_code'] ?? null];
            App::logger()->info('provider', 'provider request', [
                'provider_id' => $route['provider_id'] ?? null,
                'model_id' => $route['id'] ?? null,
                'status' => $last['status'],
                'attempt' => $attempt,
                'latency_ms' => $latency,
            ]);

            if (!$this->retryable($last['status'], $last['error_code'] ?? null) || $attempt === $attempts) {
                break;
            }
            $this->backoff($attempt, $this->retryAfter($last['headers'] ?? []));
        }
        $attemptsUsed = min($attempt, $attempts);

        if ($last['status'] === 0) {
            return ['ok' => false, ...$last, 'attempts' => $attemptsUsed, 'attempt_log' => $log, 'error_code' => $last['error_code'] ?? 'PROVIDER_TIMEOUT'];
        }

        if ($last['status'] >= 400) {
            return [
                'ok' => false,
                ...$last,
                'attempts' => $attemptsUsed,
                'attempt_log' => $log,
                'error_code' => $this->errorCodeForStatus($last['status']),
                'retry_after' => $this->retryAfter($last['headers'] ?? []),
            ];
        }

        return ['ok' => true, ...$last, 'attempts' => $attemptsUsed, 'attempt_log' => $log];
    }

    /**
     * Streaming upstream call.
     *
     * Transport outcome only; protocol-level completion (terminal event, in-band
     * error) is decided by the caller's stream accumulator. Connection failures,
     * 429 and 5xx are retried only before the first byte reaches the client.
     *
     * outcome: "eof" (upstream closed), "timeout" (read stalled past timeout_ms),
     * "client_aborted" (downstream disconnected, upstream read stopped).
     *
     * @param array<string, mixed> $route
     * @param callable(string): void $onChunk
     * @return array{ok: bool, status: int, attempts: int, attempt_log?: list<array<string, mixed>>, bytes?: int, outcome?: string, first_byte_ms?: int|null, error_code?: string, body?: string, retry_after?: int|null}
     */
    public function stream(array $route, GatewayRequest $gatewayRequest, ProviderAdapterInterface $adapter, callable $onChunk, ?callable $clientAborted = null): array
    {
        $request = $adapter->buildRequest($gatewayRequest, $route);
        $endpoint = isset($request['endpoint']) && is_string($request['endpoint'])
            ? $request['endpoint']
            : $this->endpointFor($gatewayRequest->endpoint);
        unset($request['endpoint']);
        $attempts = max(1, (int) ($route['retry_count'] ?? 1));
        $clientAborted ??= static fn (): bool => connection_aborted() === 1;
        $lastStatus = 0;
        $lastError = null;
        $lastBody = '';
        $retryAfter = null;
        $log = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $prepared = $this->prepareRequest($endpoint, $route, $request);
            if ($prepared['error_code'] === null) {
                $prepared['error_code'] = $this->enforceEgress($route, $prepared['url'])['error_code'] ?? null;
            }
            if ($prepared['error_code'] !== null) {
                return ['ok' => false, 'status' => 0, 'attempts' => $attempt, 'attempt_log' => $log, 'error_code' => $prepared['error_code']];
            }
            $prepared['method'] = 'POST';
            $started = microtime(true);
            $opened = $this->transportStream($prepared, $onChunk, $clientAborted, $started);
            $responseHeaders = $opened['header_lines'];
            $lastStatus = $opened['opened'] || $opened['error'] === null ? $this->statusCode($responseHeaders) : 0;
            $retryAfter = $this->retryAfter($this->headerMap($responseHeaders));
            $log[] = ['attempt' => $attempt, 'status' => $lastStatus, 'latency_ms' => $opened['open_ms']];
            App::logger()->info('provider', 'provider stream opened', [
                'provider_id' => $route['provider_id'] ?? null,
                'model_id' => $route['id'] ?? null,
                'status' => $lastStatus,
                'attempt' => $attempt,
                'latency_ms' => $opened['open_ms'],
                ...($opened['error'] !== null && !$opened['opened'] ? ['transport_error' => $opened['error']] : []),
            ]);

            if (!$opened['opened'] && $opened['outcome'] === 'client_aborted') {
                // The client left before the upstream answered: nothing to
                // retry or fail over, the caller records a cancellation.
                return ['ok' => true, 'status' => 0, 'attempts' => $attempt, 'attempt_log' => $log, 'bytes' => 0, 'outcome' => 'client_aborted', 'first_byte_ms' => null];
            }
            if (!$opened['opened'] && $lastStatus === 0) {
                // Connect failure, TLS failure or no response headers in time.
                $lastError = 'PROVIDER_TIMEOUT';
                if ($attempt < $attempts) {
                    $this->backoff($attempt, null);
                }
                continue;
            }

            if ($lastStatus >= 400 || $lastStatus === 0) {
                $lastBody = $opened['error_body'];
                $lastError = $lastStatus === 0 ? 'PROVIDER_TIMEOUT' : $this->errorCodeForStatus($lastStatus);
                if ($this->retryable($lastStatus, null) && $attempt < $attempts) {
                    $this->backoff($attempt, $retryAfter);
                    continue;
                }
                break;
            }

            return [
                'ok' => true,
                'status' => $lastStatus,
                'attempts' => $attempt,
                'attempt_log' => $log,
                'bytes' => $opened['bytes'],
                'outcome' => $opened['outcome'],
                'first_byte_ms' => $opened['first_byte_ms'],
            ];
        }

        return [
            'ok' => false,
            'status' => $lastStatus,
            'attempts' => min($attempts, max(1, count($log))),
            'attempt_log' => $log,
            'error_code' => $lastError ?? ($lastStatus === 0 ? 'PROVIDER_TIMEOUT' : $this->errorCodeForStatus($lastStatus)),
            'body' => $lastBody,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Upstream timeouts, all overridable through the environment:
     *
     * connect_ms   TCP + TLS establishment (GATEWAY_UPSTREAM_CONNECT_TIMEOUT_MS,
     *              default 5000, never above the channel timeout). A dead host
     *              fails over in seconds instead of waiting the full read timeout.
     * idle_s       no byte received for this long (the channel timeout_ms), the
     *              same contract the channel timeout always had.
     * max_s        hard ceiling for one upstream exchange including a long
     *              stream (GATEWAY_UPSTREAM_MAX_DURATION_SECONDS, default 1800,
     *              0 = unlimited), so a slow-drip upstream cannot hold a worker
     *              forever.
     *
     * @return array{connect_ms: int, idle_s: float, max_s: int}
     */
    public function timeouts(float $channelTimeoutSeconds): array
    {
        $channelMs = max(100, (int) round($channelTimeoutSeconds * 1000));
        $connectMs = (int) env_value('GATEWAY_UPSTREAM_CONNECT_TIMEOUT_MS', 5000);
        $connectMs = $connectMs > 0 ? min($connectMs, $channelMs) : $channelMs;
        $maxSeconds = max(0, (int) env_value('GATEWAY_UPSTREAM_MAX_DURATION_SECONDS', 1800));

        return [
            'connect_ms' => max(100, $connectMs),
            'idle_s' => (float) ($channelMs / 1000),
            'max_s' => $maxSeconds,
        ];
    }

    public function usesCurl(): bool
    {
        return function_exists('curl_init')
            && strtolower((string) env_value('GATEWAY_UPSTREAM_TRANSPORT', 'curl')) !== 'stream';
    }

    /**
     * cURL options shared by the buffered and the streaming transport.
     *
     * @param array{url: string, method: string, headers: array<string, string>, body: string, timeout: float} $prepared
     * @return array<int, mixed>
     */
    public function curlOptions(array $prepared): array
    {
        $timeouts = $this->timeouts((float) $prepared['timeout']);
        $headerLines = [];
        foreach ($prepared['headers'] as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // No "Expect: 100-continue" round trip before large bodies.
        $headerLines[] = 'Expect:';

        $options = [
            CURLOPT_URL => $prepared['url'],
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT_MS => $timeouts['connect_ms'],
            // The idle timeout is enforced by the progress callback
            // (idleWatch), which sees every received byte.
            CURLOPT_TIMEOUT => $timeouts['max_s'],
            CURLOPT_NOPROGRESS => false,
            // Redirects would bypass the outbound policy check.
            CURLOPT_FOLLOWLOCATION => false,
            // Same egress path as before: ignore http(s)_proxy environment
            // variables unless the operator opts in explicitly.
            CURLOPT_PROXY => (string) env_value('GATEWAY_UPSTREAM_PROXY', ''),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_NODELAY => true,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        $method = strtoupper((string) $prepared['method']);
        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            if ($method !== 'POST') {
                $options[CURLOPT_CUSTOMREQUEST] = $method;
            }
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string) $prepared['body'];
        }

        return $options;
    }

    /**
     * Buffered upstream exchange.
     *
     * @param array{url: string, method: string, headers: array<string, string>, body: string, timeout: float} $prepared
     * @return array{header_lines: list<string>, body: string|false, error: ?string}
     */
    private function transportSend(array $prepared): array
    {
        if (!$this->usesCurl()) {
            return $this->streamWrapperSend($prepared);
        }

        $headerLines = [];
        $idle = ['timed_out' => false];
        $handle = curl_init();
        curl_setopt_array($handle, $this->curlOptions($prepared) + [
            CURLOPT_XFERINFOFUNCTION => $this->idleWatch((float) $prepared['timeout'], $idle),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headerLines): int {
                $this->collectHeader($headerLines, $line);
                return strlen($line);
            },
        ]);
        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = $errno !== 0 ? 'curl_' . $errno . ':' . curl_error($handle) : null;
        unset($handle);

        return ['header_lines' => $headerLines, 'body' => is_string($body) ? $body : false, 'error' => $error];
    }

    /**
     * Streaming upstream exchange. Bytes of a successful response go to
     * $onChunk as they arrive; an error response body is captured (64 KiB)
     * instead of being forwarded.
     *
     * @param array{url: string, method: string, headers: array<string, string>, body: string, timeout: float} $prepared
     * @param callable(string): void $onChunk
     * @param callable(): bool $clientAborted
     * @return array{opened: bool, header_lines: list<string>, outcome: string, bytes: int, first_byte_ms: ?int, open_ms: int, error_body: string, error: ?string}
     */
    private function transportStream(array $prepared, callable $onChunk, callable $clientAborted, float $started): array
    {
        if (!$this->usesCurl()) {
            return $this->streamWrapperStream($prepared, $onChunk, $clientAborted, $started);
        }

        $headerLines = [];
        $state = ['status' => null, 'bytes' => 0, 'first_byte_ms' => null, 'open_ms' => null, 'error_body' => '', 'aborted' => false, 'error_body_full' => false];
        $handle = curl_init();
        curl_setopt_array($handle, $this->curlOptions($prepared) + [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headerLines, &$state, $started): int {
                $this->collectHeader($headerLines, $line);
                if (trim($line) === '' && $headerLines !== []) {
                    // End of the response head: the stream is open.
                    $state['status'] = $this->statusCode($headerLines);
                    $state['open_ms'] = (int) ((microtime(true) - $started) * 1000);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$state, $onChunk, $clientAborted, $started): int {
                $status = (int) ($state['status'] ?? 0);
                if ($status >= 400 || $status === 0) {
                    $room = 65536 - strlen($state['error_body']);
                    if ($room <= 0) {
                        $state['error_body_full'] = true;
                        return -1;
                    }
                    $state['error_body'] .= substr($chunk, 0, $room);
                    return strlen($chunk);
                }
                if ($clientAborted()) {
                    $state['aborted'] = true;
                    return -1;
                }
                $state['first_byte_ms'] ??= (int) ((microtime(true) - $started) * 1000);
                $state['bytes'] += strlen($chunk);
                $onChunk($chunk);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
                return strlen($chunk);
            },
            CURLOPT_XFERINFOFUNCTION => $this->idleWatch((float) $prepared['timeout'], $state, static function () use (&$state, $clientAborted): bool {
                if ($clientAborted()) {
                    $state['aborted'] = true;
                    return true;
                }
                return false;
            }),
        ]);
        curl_exec($handle);
        $errno = curl_errno($handle);
        $error = $errno !== 0 ? 'curl_' . $errno . ':' . curl_error($handle) : null;
        unset($handle);

        $opened = $state['status'] !== null && (int) $state['status'] > 0;
        $outcome = 'eof';
        if ($state['aborted']) {
            $outcome = 'client_aborted';
        } elseif ($errno !== 0 && !$state['error_body_full']) {
            // Idle timeout, max duration or a connection reset mid-stream: the
            // stream did not end cleanly.
            $outcome = 'timeout';
        }

        return [
            'opened' => $opened,
            'header_lines' => $headerLines,
            'outcome' => $outcome,
            'bytes' => (int) $state['bytes'],
            'first_byte_ms' => $state['first_byte_ms'],
            'open_ms' => (int) ($state['open_ms'] ?? (int) ((microtime(true) - $started) * 1000)),
            'error_body' => (string) $state['error_body'],
            'error' => $error,
        ];
    }

    /**
     * Progress callback enforcing the idle timeout: the transfer is aborted
     * when no byte was sent or received for the channel timeout, which is the
     * contract the stream wrapper's read timeout had. Sets $state['timed_out'].
     *
     * @param array<string, mixed> $state
     * @param null|callable(): bool $shouldAbort
     */
    private function idleWatch(float $idleSeconds, array &$state, ?callable $shouldAbort = null): \Closure
    {
        $lastActivity = microtime(true);
        $seen = -1;

        return function ($handle, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($idleSeconds, &$state, $shouldAbort, &$lastActivity, &$seen): int {
            if ($shouldAbort !== null && $shouldAbort()) {
                return 1;
            }
            $progress = (int) $downloaded + (int) $uploaded;
            $now = microtime(true);
            if ($progress !== $seen) {
                $seen = $progress;
                $lastActivity = $now;
                return 0;
            }
            if ($now - $lastActivity > $idleSeconds) {
                $state['timed_out'] = true;
                return 1;
            }

            return 0;
        };
    }

    /**
     * Keeps only the head of the final response: an interim "HTTP/1.1 100
     * Continue" (or any earlier status line) starts a new header block.
     *
     * @param list<string> $headerLines
     */
    private function collectHeader(array &$headerLines, string $line): void
    {
        $line = rtrim($line, "\r\n");
        if (str_starts_with($line, 'HTTP/')) {
            $headerLines = [$line];
            return;
        }
        if ($line !== '') {
            $headerLines[] = $line;
        }
    }

    /**
     * Fallback when ext-curl is missing (or GATEWAY_UPSTREAM_TRANSPORT=stream).
     * The PHP stream wrapper has a single timeout for connect and read.
     *
     * @param array{url: string, method: string, headers: array<string, string>, body: string, timeout: float} $prepared
     * @return array{header_lines: list<string>, body: string|false, error: ?string}
     */
    private function streamWrapperSend(array $prepared): array
    {
        $body = @file_get_contents($prepared['url'], false, $this->streamContext($prepared));
        $headerLines = $http_response_header ?? [];

        return ['header_lines' => $headerLines, 'body' => $body, 'error' => $body === false && $headerLines === [] ? 'stream_failed' : null];
    }

    /**
     * @param array{url: string, method: string, headers: array<string, string>, body: string, timeout: float} $prepared
     * @param callable(string): void $onChunk
     * @param callable(): bool $clientAborted
     * @return array{opened: bool, header_lines: list<string>, outcome: string, bytes: int, first_byte_ms: ?int, open_ms: int, error_body: string, error: ?string}
     */
    private function streamWrapperStream(array $prepared, callable $onChunk, callable $clientAborted, float $started): array
    {
        $stream = @fopen($prepared['url'], 'rb', false, $this->streamContext($prepared));
        $headerLines = $http_response_header ?? [];
        $openMs = (int) ((microtime(true) - $started) * 1000);
        $result = ['opened' => false, 'header_lines' => $headerLines, 'outcome' => 'eof', 'bytes' => 0, 'first_byte_ms' => null, 'open_ms' => $openMs, 'error_body' => '', 'error' => null];
        if (!is_resource($stream)) {
            $result['error'] = 'stream_failed';
            return $result;
        }
        $result['opened'] = true;
        $status = $this->statusCode($headerLines);
        if ($status >= 400 || $status === 0) {
            $result['error_body'] = (string) stream_get_contents($stream, 65536);
            fclose($stream);
            return $result;
        }

        $maxSeconds = $this->timeouts((float) $prepared['timeout'])['max_s'];
        stream_set_timeout($stream, (int) ceil((float) $prepared['timeout']));
        while (!feof($stream)) {
            if ($clientAborted()) {
                $result['outcome'] = 'client_aborted';
                break;
            }
            if ($maxSeconds > 0 && microtime(true) - $started > $maxSeconds) {
                $result['outcome'] = 'timeout';
                break;
            }
            $chunk = fread($stream, 8192);
            $meta = stream_get_meta_data($stream);
            if (($meta['timed_out'] ?? false) === true) {
                $result['outcome'] = 'timeout';
                break;
            }
            if ($chunk === false || $chunk === '') {
                continue;
            }
            $result['first_byte_ms'] ??= (int) ((microtime(true) - $started) * 1000);
            $result['bytes'] += strlen($chunk);
            $onChunk($chunk);
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }
        fclose($stream);

        return $result;
    }

    /**
     * @param array{url: string, method: string, headers: array<string, string>, body: string, timeout: float} $prepared
     * @return resource
     */
    private function streamContext(array $prepared)
    {
        $headerLines = [];
        foreach ($prepared['headers'] as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $http = [
            'method' => $prepared['method'],
            'header' => implode("\r\n", $headerLines),
            'timeout' => $prepared['timeout'],
            'ignore_errors' => true,
            // Redirects would bypass the outbound policy check.
            'follow_location' => 0,
        ];
        if ($prepared['method'] !== 'GET') {
            $http['content'] = $prepared['body'];
        }

        return stream_context_create(['http' => $http]);
    }

    public function errorCodeForStatus(int $status): string
    {
        return match (true) {
            $status === 429 => 'PROVIDER_RATE_LIMITED',
            $status === 408 || $status === 504 => 'PROVIDER_TIMEOUT',
            $status >= 500 => 'PROVIDER_ERROR',
            default => 'PROVIDER_REJECTED',
        };
    }

    public function retryable(int $status, ?string $errorCode): bool
    {
        if (in_array($errorCode, ['OUTBOUND_BLOCKED', 'PROVIDER_ENDPOINT_UNSUPPORTED'], true)) {
            return false;
        }

        return $status === 0 || $status === 429 || $status === 408 || $status >= 500;
    }

    private function backoff(int $attempt, ?int $retryAfterSeconds): void
    {
        $maxMs = (int) env_value('PROVIDER_RETRY_MAX_BACKOFF_MS', 2000);
        $delayMs = $retryAfterSeconds !== null ? $retryAfterSeconds * 1000 : 200 * (2 ** ($attempt - 1));
        $delayMs = max(0, min($maxMs, $delayMs));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function retryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $time = strtotime($value);

        return $time === false ? null : max(0, $time - time());
    }

    /**
     * @param list<string> $lines
     * @return array<string, string>
     */
    private function headerMap(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function decodeHeaders(mixed $headers): array
    {
        if (!is_array($headers) && !is_string($headers)) {
            return [];
        }

        return $this->publicHeaders((new ProviderHeaderVault())->revealForUse($headers));
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

    /**
     * Upstream auth scheme: provider control "tokenhub_auth", else the vendor
     * profile's default, else bearer / x-api-key by protocol.
     *
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    public function authScheme(array $route): array
    {
        $controls = (new ProviderHeaderVault())->decode(is_array($route['default_headers'] ?? null) || is_string($route['default_headers'] ?? null) ? $route['default_headers'] : null);
        $scheme = $controls['tokenhub_auth'] ?? null;
        if (is_string($scheme)) {
            $decoded = json_decode($scheme, true);
            $scheme = is_array($decoded) ? $decoded : ['type' => $scheme];
        }
        if (is_array($scheme) && $scheme !== []) {
            return $scheme;
        }
        $profile = \TokenHub\Services\Providers\Vendor\VendorProfiles::forRoute($route);

        return is_array($profile['auth'] ?? null) ? $profile['auth'] : [];
    }

    private function sameHostFamily(string $baseUrl, string $url): bool
    {
        $base = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($base === '' || $host === '') {
            return false;
        }
        if ($base === $host) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || filter_var($base, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        // Registrable domain: last two labels, three under two-level public
        // suffixes (com.cn, co.uk, ...).
        $suffix = static function (string $name): string {
            $labels = explode('.', $name);
            $two = implode('.', array_slice($labels, -2));
            $public = ['com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn', 'co.uk', 'org.uk', 'com.hk', 'com.tw', 'com.au', 'co.jp', 'co.kr', 'com.sg', 'com.br'];

            return in_array($two, $public, true) ? implode('.', array_slice($labels, -3)) : $two;
        };

        return $suffix($base) === $suffix($host);
    }

    /**
     * Kept for callers/tests that only need URL joining without policy checks.
     */
    public function providerUrl(string $baseUrl, string $endpoint): string
    {
        $resolved = (new ProviderEndpointResolver())->resolve($baseUrl, $endpoint);

        return (string) ($resolved['url'] ?? (rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/')));
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
