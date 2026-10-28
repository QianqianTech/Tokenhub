<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers\Vendor;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\MultipartUpload;
use TokenHub\Services\OutboundUrlPolicy;
use TokenHub\Services\Providers\AsyncTaskAdapter;
use TokenHub\Services\Providers\ProviderAdapterInterface;
use TokenHub\Services\SafeRemoteFetcher;
use TokenHub\Support\Decimal;

/**
 * Generic adapter driven by a VendorProfiles capability spec (MM-010).
 *
 * Clients keep calling the OpenAI-style TokenHub endpoints; the adapter maps
 * the request to the vendor API, normalizes the create / poll answers to the
 * canonical task view (see AsyncTaskAdapter) and returns synchronous media in
 * the OpenAI shapes (images data[], speech bytes, transcription text).
 *
 * Canonical vendor metrics go to "vendor_usage" (never "usage", which the
 * protocol usage normalizer reads as token usage).
 */
final class VendorAdapter implements ProviderAdapterInterface, AsyncTaskAdapter
{
    /**
     * @param array<string, mixed> $spec
     */
    public function __construct(
        private readonly array $spec,
        private readonly ?SafeRemoteFetcher $fetcher = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function spec(): array
    {
        return $this->spec;
    }

    public function protocol(): string
    {
        return 'openai';
    }

    public function isAsync(): bool
    {
        return ($this->spec['mode'] ?? 'sync') === 'async';
    }

    public function supportsCallback(string $capability = ''): bool
    {
        return isset($this->spec['callback']) && $this->isAsync();
    }

    public function buildRequest(GatewayRequest $request, array $route): array
    {
        $payload = $request->payload;
        $callback = isset($payload['__tokenhub_callback_url']) && is_string($payload['__tokenhub_callback_url']) ? $payload['__tokenhub_callback_url'] : null;
        unset($payload['__tokenhub_callback_url'], $payload['rag']);
        if ($request->capability === 'video_generation' && !isset($payload['duration']) && !isset($payload['seconds']) && isset($this->spec['default_duration'])) {
            // Always send the duration TokenHub bills (see usage()).
            $payload['duration'] = (int) $this->spec['default_duration'];
        }
        $model = $this->upstreamModel($route, $request->model);
        $mapped = (new VendorRequestMapper())->map((string) $this->spec['mapper'], $payload, $model);
        $path = (string) $mapped['path'];

        if ($callback !== null && isset($this->spec['callback'])) {
            $target = (string) $this->spec['callback'];
            if (str_starts_with($target, '?')) {
                $path .= (str_contains($path, '?') ? '&' : '?') . substr($target, 1) . '=' . rawurlencode($callback);
            } elseif (isset($mapped['json'])) {
                $mapped['json'] = self::set($mapped['json'], $target, $callback);
            }
        }

        $built = [
            'endpoint' => $this->endpoint($route, $path, (bool) ($mapped['relative'] ?? false)),
            'headers' => [...['Accept' => 'application/json'], ...($mapped['headers'] ?? [])],
        ];
        if (isset($mapped['raw'])) {
            $built['body'] = (string) $mapped['raw'];
            $built['headers']['Content-Type'] ??= 'application/octet-stream';
        } elseif (isset($mapped['multipart'])) {
            $encoded = MultipartUpload::encode($mapped['multipart']);
            $built['body'] = $encoded['body'];
            $built['headers']['Content-Type'] = $encoded['content_type'];
        } else {
            $built['json'] = $mapped['json'] ?? [];
            $built['headers']['Content-Type'] ??= 'application/json';
        }

        return $built;
    }

    public function normalizeResponse(GatewayRequest $request, array $route, array $providerResponse): array
    {
        $error = $this->error($providerResponse);
        if ($error !== null) {
            return ['status' => 'failed', 'error' => $error];
        }
        $usage = $this->vendorUsage($providerResponse);

        if ($this->isAsync()) {
            $taskId = self::first($providerResponse, (string) ($this->spec['task_id'] ?? 'id'));
            if (!is_scalar($taskId) || (string) $taskId === '') {
                return ['status' => 'failed', 'error' => ['code' => 'PROVIDER_TASK_INVALID', 'message' => 'Upstream did not return a task id']];
            }

            return array_filter([
                'provider_task_id' => (string) $taskId,
                'status' => 'processing',
                'vendor_usage' => $usage !== [] ? $usage : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $kind = (string) ($this->spec['result_kind'] ?? ($request->capability === 'asr' ? 'text' : 'url'));
        if ($kind === 'text') {
            $text = self::first($providerResponse, implode('|', (array) ($this->spec['results'] ?? ['text'])));
            $result = ['text' => is_string($text) ? $text : (is_array($text) ? (string) json_encode($text, JSON_UNESCAPED_UNICODE) : '')];
            if (isset($usage['audio_seconds'])) {
                $result['duration'] = $usage['audio_seconds'];
            }

            return [...$result, ...($usage !== [] ? ['vendor_usage' => $usage] : [])];
        }

        if (in_array($request->capability, ['tts', 'music_generation'], true)) {
            if ($kind === 'binary' && is_string($providerResponse['b64_json'] ?? null)) {
                // Raw audio body, already wrapped by the gateway.
                return $providerResponse;
            }
            $audio = $this->audioBytes($providerResponse, $kind);
            if ($audio === null) {
                return ['status' => 'failed', 'error' => ['code' => 'PROVIDER_RESPONSE_INVALID', 'message' => 'Upstream returned no audio']];
            }

            return ['b64_json' => base64_encode($audio['bytes']), 'audio_mime' => $audio['mime'], 'status' => 'succeeded', ...($usage !== [] ? ['vendor_usage' => $usage] : [])];
        }

        // Synchronous images: OpenAI images "data" list.
        $data = [];
        foreach ($this->results($providerResponse) as $value) {
            $data[] = preg_match('#^(https?://|data:)#i', $value) === 1 ? ['url' => $value] : ['b64_json' => $value];
        }
        if ($data === []) {
            return ['status' => 'failed', 'error' => ['code' => 'PROVIDER_RESPONSE_INVALID', 'message' => 'Upstream returned no media']];
        }
        $response = ['created' => time(), 'data' => $data, 'status' => 'succeeded'];
        if (is_array($providerResponse['usage'] ?? null)) {
            // OpenAI-shaped token usage (OpenRouter images) stays measurable.
            $response['usage'] = $providerResponse['usage'];
        }
        if ($usage !== []) {
            $response['vendor_usage'] = $usage;
        }

        return $response;
    }

    public function pollRequest(array $route, string $capability, string $providerTaskId, array $taskResult = []): ?array
    {
        $poll = $this->spec['poll'] ?? null;
        if (!is_array($poll) || $providerTaskId === '') {
            return null;
        }

        return $this->templatedRequest($route, $poll, $providerTaskId);
    }

    /**
     * Cancels a task that has not started (profile "cancel" template).
     *
     * @param array<string, mixed> $route
     * @return array{method: string, endpoint: string, headers?: array<string, string>, json?: array<string, mixed>}|null
     */
    public function cancelRequest(array $route, string $capability, string $providerTaskId): ?array
    {
        $cancel = $this->spec['cancel'] ?? null;

        return is_array($cancel) && $providerTaskId !== '' ? $this->templatedRequest($route, $cancel, $providerTaskId) : null;
    }

    /**
     * Second request once the task succeeded (fal result endpoint).
     *
     * @param array<string, mixed> $route
     * @return array{method: string, endpoint: string, headers?: array<string, string>, json?: array<string, mixed>}|null
     */
    public function resultRequest(array $route, string $providerTaskId): ?array
    {
        $fetch = $this->spec['result_fetch'] ?? null;

        return is_array($fetch) ? $this->templatedRequest($route, $fetch, $providerTaskId) : null;
    }

    public function normalizePollResponse(string $capability, array $route, array $response, string $providerTaskId): array
    {
        $usage = $this->vendorUsage($response);
        $raw = self::first($response, (string) ($this->spec['status'] ?? 'status'));
        $rawKey = is_bool($raw) ? ($raw ? 'true' : 'false') : strtolower(trim(is_scalar($raw) ? (string) $raw : ''));
        $map = array_change_key_case((array) ($this->spec['status_map'] ?? []), CASE_LOWER);
        $status = (string) ($map[$rawKey] ?? ($rawKey === '' ? 'processing' : 'processing'));
        $error = $this->error($response);
        if ($error !== null) {
            $status = 'failed';
        }

        $normalized = ['provider_task_id' => $providerTaskId, 'status' => $status, 'upstream_status' => $rawKey];
        if ($usage !== []) {
            $normalized['vendor_usage'] = $usage;
        }
        if ($status === 'failed') {
            $normalized['error'] = $error ?? ['code' => 'PROVIDER_TASK_FAILED', 'message' => 'Upstream task ' . $rawKey];
            return $normalized;
        }
        if ($status !== 'succeeded') {
            $progress = $response['progress'] ?? null;
            if (is_numeric($progress)) {
                $normalized['progress'] = (int) ((float) $progress <= 1 && str_contains((string) $progress, '.') ? (float) $progress * 100 : $progress);
            }
            return $normalized;
        }

        $assets = $this->assetsFrom($response);
        if ($assets === [] && isset($this->spec['download_fallback'])) {
            $count = max(1, count((array) self::first($response, (string) ($this->spec['download_count'] ?? 'unsigned_urls'))));
            for ($index = 0; $index < $count; $index++) {
                $path = strtr((string) $this->spec['download_fallback'], ['{id}' => rawurlencode($providerTaskId), '{index}' => (string) $index]);
                $assets[] = ['download' => ['method' => 'GET', 'endpoint' => $this->endpoint($route, $path, false)], 'mime_type' => $this->spec['mime'] ?? null];
            }
        }
        if ($assets === [] && isset($this->spec['result_fetch'])) {
            $normalized['status'] = 'processing';
            $normalized['follow_up'] = true;
            return $normalized;
        }
        if ($assets === []) {
            // "Done" without any media is a failed generation, not a success.
            return [...$normalized, 'status' => 'failed', 'error' => ['code' => 'PROVIDER_TASK_EMPTY', 'message' => 'Upstream task finished without media']];
        }
        $normalized['assets'] = $assets;

        return $normalized;
    }

    /**
     * Normalizes the answer of resultRequest() (the task already succeeded).
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function normalizeResultResponse(array $response, string $providerTaskId): array
    {
        $error = $this->error($response);
        if ($error !== null) {
            return ['provider_task_id' => $providerTaskId, 'status' => 'failed', 'error' => $error];
        }

        $assets = $this->assetsFrom($response);
        if ($assets === []) {
            return ['provider_task_id' => $providerTaskId, 'status' => 'failed', 'error' => ['code' => 'PROVIDER_TASK_EMPTY', 'message' => 'Upstream task finished without media']];
        }

        return ['provider_task_id' => $providerTaskId, 'status' => 'succeeded', 'assets' => $assets, ...(($usage = $this->vendorUsage($response)) !== [] ? ['vendor_usage' => $usage] : [])];
    }

    public function normalizeError(int $status, string $body): array
    {
        return ['code' => $status >= 500 ? 'PROVIDER_ERROR' : 'PROVIDER_REJECTED', 'message' => 'Provider request failed'];
    }

    public function usage(GatewayRequest $request, array $response): array
    {
        $vendor = is_array($response['vendor_usage'] ?? null) ? $response['vendor_usage'] : [];
        $estimate = $request->estimatedUsage;
        $usage = [
            'input_tokens' => (int) ($estimate['input_tokens'] ?? 0),
            'output_tokens' => 0,
            'total_tokens' => (int) ($estimate['total_tokens'] ?? $estimate['input_tokens'] ?? 0),
        ];
        foreach (['audio_seconds', 'tts_chars', 'image_count', 'video_seconds'] as $metric) {
            $usage[$metric] = (int) ($vendor[$metric] ?? $estimate[$metric] ?? 0);
        }
        if ($request->capability === 'video_generation' && $usage['video_seconds'] === 0 && isset($this->spec['default_duration'])) {
            $usage['video_seconds'] = (int) $this->spec['default_duration'];
        }
        if ($request->capability === 'image_generation' && is_array($response['data'] ?? null) && !isset($vendor['image_count'])) {
            $usage['image_count'] = count($response['data']);
        }
        if (isset($vendor['upstream_cost'])) {
            $usage['upstream_cost'] = (string) $vendor['upstream_cost'];
        }

        return $usage;
    }

    public function accumulateStreamUsage(GatewayRequest $request, string $chunk, array $state): array
    {
        return $state;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $template
     * @return array{method: string, endpoint: string, headers?: array<string, string>, json?: array<string, mixed>}
     */
    private function templatedRequest(array $route, array $template, string $taskId): array
    {
        $model = $this->upstreamModel($route, '');
        $segments = explode('/', $model);
        $app = implode('/', array_slice($segments, 0, 2));
        $replace = ['{id}' => rawurlencode($taskId), '{model}' => $model, '{app}' => $app];
        $path = strtr((string) ($template['path'] ?? '/'), $replace);
        $request = [
            'method' => strtoupper((string) ($template['method'] ?? 'GET')),
            'endpoint' => $this->endpoint($route, $path, str_starts_with((string) ($template['path'] ?? ''), '/{model}')),
            'headers' => ['Accept' => 'application/json', ...((array) ($template['headers'] ?? []))],
        ];
        if (isset($template['json']) && is_array($template['json'])) {
            array_walk_recursive($template['json'], static function (mixed &$value) use ($taskId): void {
                if ($value === '{id}') {
                    $value = $taskId;
                }
            });
            $request['json'] = $template['json'];
            $request['headers']['Content-Type'] = 'application/json';
        }

        return $request;
    }

    /**
     * Paths under /v1/ go through the endpoint resolver (versioned base URLs);
     * other vendor paths are rooted at the base URL's host; "relative" paths
     * are appended to the base URL (Vertex model resource paths).
     *
     * @param array<string, mixed> $route
     */
    private function endpoint(array $route, string $path, bool $relative): string
    {
        $base = rtrim((string) ($route['base_url'] ?? ''), '/');
        if ($relative) {
            return $base . $path;
        }
        if (str_starts_with($path, '/v1/') || $path === '/v1') {
            return $path;
        }
        $parts = parse_url($base);
        $root = (string) ($parts['scheme'] ?? 'https') . '://' . (string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        // A base path prefix that is not an API version (fake upstreams, proxies) is kept.
        $prefix = (string) ($this->spec['keep_base_path'] ?? false ? ($parts['path'] ?? '') : '');

        return $root . $prefix . $path;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function upstreamModel(array $route, string $fallback): string
    {
        return (string) (($route['external_name'] ?? '') !== '' ? $route['external_name'] : (($route['name'] ?? '') !== '' ? $route['name'] : $fallback));
    }

    /**
     * @param array<string, mixed> $response
     * @return array{code: string, message: string}|null
     */
    private function error(array $response): ?array
    {
        $flagged = false;
        foreach ((array) ($this->spec['error_when'] ?? []) as $path => $rule) {
            $value = self::first($response, (string) $path);
            if ($rule === 'nonzero' && $value !== null && is_numeric($value) && (int) $value !== 0) {
                $flagged = true;
            }
        }
        $message = null;
        foreach ((array) ($this->spec['error'] ?? []) as $path) {
            $value = self::first($response, (string) $path);
            if (is_string($value) && trim($value) !== '' || is_numeric($value)) {
                $message = (string) $value;
                break;
            }
            if (is_array($value) && isset($value['message']) && is_string($value['message'])) {
                $message = $value['message'];
                break;
            }
        }
        if (!$flagged && isset($response['error']) && $response['error'] !== null && $response['error'] !== '' && $response['error'] !== []) {
            $flagged = true;
            $message ??= is_string($response['error']) ? $response['error'] : (string) ($response['error']['message'] ?? 'Upstream error');
        }
        if (!$flagged) {
            return null;
        }

        return ['code' => 'PROVIDER_TASK_FAILED', 'message' => mb_substr($message ?? 'Upstream error', 0, 500)];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, int|string>
     */
    private function vendorUsage(array $response): array
    {
        $usage = [];
        foreach ((array) ($this->spec['usage'] ?? []) as $metric => $source) {
            $path = is_array($source) ? (string) ($source['path'] ?? '') : (string) $source;
            $value = self::first($response, $path);
            if (!is_numeric($value)) {
                continue;
            }
            if ($metric === 'upstream_cost') {
                $scale = is_array($source) ? (string) ($source['scale'] ?? '1') : '1';
                $decimals = is_array($source) ? (int) ($source['decimals'] ?? 0) : 0;
                $amount = $decimals > 0 ? self::shiftDecimal((string) $value, $decimals) : (string) $value;
                $usage[$metric] = Decimal::mulDecimal($amount, $scale);
            } else {
                $usage[$metric] = (int) ceil((float) $value);
            }
        }

        return $usage;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<string>
     */
    private function results(array $response): array
    {
        $values = [];
        foreach ((array) ($this->spec['results'] ?? []) as $path) {
            $found = $path === '*media*' ? self::mediaUrls($response) : self::all($response, (string) $path);
            foreach ($found as $value) {
                if (is_string($value) && $value !== '') {
                    $values[] = $value;
                }
            }
            if ($values !== []) {
                break;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function assetsFrom(array $response): array
    {
        $assets = [];
        foreach ($this->results($response) as $value) {
            if (preg_match('#^https?://#i', $value) === 1 || str_starts_with($value, 'data:')) {
                $assets[] = ['url' => $value, 'mime_type' => $this->spec['mime'] ?? null];
            } elseif (preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $value) === 1 && strlen($value) > 64) {
                $assets[] = ['b64_json' => $value, 'mime_type' => $this->spec['mime'] ?? null];
            }
        }

        return $assets;
    }

    /**
     * @param array<string, mixed> $response
     * @return array{bytes: string, mime: string}|null
     */
    private function audioBytes(array $response, string $kind): ?array
    {
        $mime = (string) ($this->spec['mime'] ?? 'audio/mpeg');
        $value = $this->results($response)[0] ?? null;
        if ($kind === 'hex' && is_string($value) && ctype_xdigit($value)) {
            $bytes = hex2bin($value);
            return $bytes === false ? null : ['bytes' => $bytes, 'mime' => $this->sniffAudio($bytes, $mime)];
        }
        if ($kind === 'url' && is_string($value) && preg_match('#^https?://#i', $value) === 1) {
            $fetched = ($this->fetcher ?? new SafeRemoteFetcher())->get($value, OutboundUrlPolicy::PURPOSE_REMOTE_ASSET);
            if (($fetched['ok'] ?? false) !== true) {
                return null;
            }
            $type = strtolower(trim(explode(';', (string) ($fetched['content_type'] ?? ''))[0]));

            return ['bytes' => (string) $fetched['body'], 'mime' => str_starts_with($type, 'audio/') ? $type : $this->sniffAudio((string) $fetched['body'], $mime)];
        }
        if (is_string($value) && $value !== '') {
            $bytes = base64_decode($value, true);
            return $bytes === false ? null : ['bytes' => $bytes, 'mime' => $this->sniffAudio($bytes, $mime)];
        }

        return null;
    }

    private function sniffAudio(string $bytes, string $fallback): string
    {
        return match (true) {
            str_starts_with($bytes, 'RIFF') => 'audio/wav',
            str_starts_with($bytes, 'fLaC') => 'audio/flac',
            str_starts_with($bytes, 'OggS') => 'audio/ogg',
            str_starts_with($bytes, 'ID3') || (strlen($bytes) > 1 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0) => 'audio/mpeg',
            default => $fallback,
        };
    }

    /**
     * value / 10^places on decimal strings (cost ticks -> USD).
     */
    public static function shiftDecimal(string $value, int $places): string
    {
        $value = ltrim(Decimal::normalize($value), '0');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $digits = str_pad($int, $places + 1, '0', STR_PAD_LEFT);
        $shifted = substr($digits, 0, -$places) . '.' . substr($digits, -$places) . $frac;

        return ltrim($shifted, '0') === '' || str_starts_with(ltrim($shifted, '0'), '.') ? '0' . ltrim($shifted, '0') : ltrim($shifted, '0');
    }

    // ------------------------------------------------------------------ path helpers

    /**
     * First non-null value among "a.b|c.d" alternatives (with * wildcards).
     */
    public static function first(array $data, string $paths): mixed
    {
        foreach (explode('|', $paths) as $path) {
            $values = self::all($data, trim($path));
            if ($values !== []) {
                return $values[0];
            }
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    public static function all(mixed $data, string $path): array
    {
        if ($path === '') {
            return [];
        }
        $current = [$data];
        foreach (explode('.', $path) as $segment) {
            $next = [];
            foreach ($current as $node) {
                if (!is_array($node)) {
                    continue;
                }
                if ($segment === '*') {
                    foreach ($node as $child) {
                        $next[] = $child;
                    }
                } elseif (array_key_exists($segment, $node)) {
                    $next[] = $node[$segment];
                }
            }
            $current = $next;
        }

        return array_values(array_filter($current, static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Media URLs anywhere in a model-specific result (fal, Replicate).
     *
     * @return list<string>
     */
    public static function mediaUrls(mixed $data): array
    {
        $found = [];
        $walk = static function (mixed $node) use (&$walk, &$found): void {
            if (is_string($node)) {
                if (preg_match('#^https?://\S+\.(png|jpe?g|webp|gif|mp4|webm|mov|mp3|wav|flac|ogg|m4a)(\?\S*)?$#i', $node) === 1) {
                    $found[] = $node;
                }
                return;
            }
            if (is_array($node)) {
                foreach ($node as $key => $child) {
                    if ($key === 'url' && is_string($child) && preg_match('#^https?://#i', $child) === 1) {
                        $found[] = $child;
                        continue;
                    }
                    $walk($child);
                }
            }
        };
        $walk($data);

        return array_values(array_unique($found));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function set(array $data, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $ref = &$data;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = $ref[$segment] ?? [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;

        return $data;
    }
}
