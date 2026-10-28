<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Gateway\GatewayUsageRecorder;
use TokenHub\Services\Gateway\ModelRouter;
use TokenHub\Services\Providers\ProviderAuth;
use TokenHub\Services\Providers\ProviderEndpointResolver;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Request;

/**
 * TokenHub side of the realtime WebSocket relay (MM-030, realtime-gateway/).
 *
 * The Go relay holds the WebSocket connections; this service decides who may
 * open one and records what they used:
 *
 *   authorize()      API key (or short-lived client secret) + model -> channel,
 *                    upstream wss URL and auth headers; reserves one request
 *                    and a concurrency slot for the session.
 *   recordUsage()    one usage record per completed response, quota counters
 *                    updated; answers whether the session may continue.
 *   issueClientSecret()  ek_ tokens for browsers (WebRTC/WebSocket clients must
 *                    not hold the real API key).
 *
 * Models use capability "realtime". Upstream URL: provider control
 * "tokenhub_realtime_url" (template, {model} replaced), else the base URL
 * with /v1/realtime?model=<upstream model> on ws(s).
 */
final class RealtimeService
{
    private const SESSION_PREFIX = 'realtime_session:';
    private const SECRET_PREFIX = 'realtime_client_secret:';

    public function internalTokenValid(?string $token): bool
    {
        $expected = (string) env_value('REALTIME_INTERNAL_TOKEN', '');

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * @return array{ok: bool, status?: int, code?: string, message?: string, data?: array<string, mixed>}
     */
    public function authorize(string $token, string $model, string $clientIp): array
    {
        $apiKey = $this->apiKeyFor($token, $model, $clientIp);
        if (!$apiKey['ok']) {
            return $apiKey;
        }
        $key = $apiKey['data'];
        App::context()->setApiKey($key);
        $allowedModelIds = ($key['id'] ?? null) !== null ? (new ApiKeyService())->routableModelIds($key) : null;
        $route = null;
        foreach (['openai', 'gemini'] as $protocol) {
            $request = new GatewayRequest(endpoint: '/v1/realtime', protocol: $protocol, capability: 'realtime', model: $model, stream: true, payload: [], estimatedUsage: []);
            $candidates = (new ModelRouter())->candidates($request, $allowedModelIds);
            if ($candidates !== []) {
                $route = $candidates[0];
                break;
            }
        }
        if ($route === null) {
            return ['ok' => false, 'status' => 404, 'code' => 'MODEL_NOT_FOUND', 'message' => 'No realtime channel for this model'];
        }

        $quota = (new QuotaService())->preflight($key, []);
        if (!($quota['allowed'] ?? false)) {
            return ['ok' => false, 'status' => (int) ($quota['status'] ?? 429), 'code' => (string) ($quota['code'] ?? 'QUOTA_EXCEEDED'), 'message' => (string) ($quota['message'] ?? 'Quota exceeded')];
        }

        // Everything after the reservation must give it back on failure.
        $release = static function () use ($quota): void {
            $service = new QuotaService();
            $service->rollbackReservations($quota['reservations'] ?? []);
            $service->releaseConcurrency($quota['concurrency_keys'] ?? []);
        };
        try {
            $upstreamModel = (string) (($route['external_name'] ?? '') !== '' ? $route['external_name'] : $route['name']);
            $url = $this->upstreamUrl($route, $upstreamModel);
            $headers = [];
            $secret = !empty($route['encrypted_secret']) ? Crypto::decrypt((string) $route['encrypted_secret']) : '';
            if ($secret !== '') {
                $authed = (new ProviderAuth())->apply((new ProviderHttpClient())->authScheme($route), $secret, 'GET', $url, '', [], (string) ($route['protocol'] ?? 'openai'));
                $headers = $authed['headers'];
                $url = $authed['url'];
            }
            $controls = (new ProviderHeaderVault())->revealForUse(is_array($route['default_headers'] ?? null) || is_string($route['default_headers'] ?? null) ? $route['default_headers'] : null);
            foreach ($controls as $name => $value) {
                if (!str_starts_with(strtolower((string) $name), 'tokenhub_') && is_scalar($value)) {
                    $headers[(string) $name] ??= (string) $value;
                }
            }
            $egress = (new ProviderHttpClient())->enforceEgress($route, (string) preg_replace('#^ws#i', 'http', $url));
            if (!isset($egress['url'])) {
                $release();
                return ['ok' => false, 'status' => 502, 'code' => 'OUTBOUND_BLOCKED', 'message' => 'Upstream address is not allowed by the outbound policy'];
            }

            $sessionId = Crypto::token('rt_');
            $maxSeconds = max(60, (int) env_value('REALTIME_MAX_SESSION_SECONDS', 3600));
            App::redis()->set(self::SESSION_PREFIX . $sessionId, [
                'api_key' => $key,
                'client_ip' => $clientIp,
                'model' => $model,
                'route' => array_intersect_key($route, array_flip(['id', 'provider_id', 'name', 'external_name', 'protocol'])),
                'concurrency_keys' => $quota['concurrency_keys'] ?? [],
                'started_at' => time(),
                'responses' => 0,
            ], $maxSeconds + 600);
        } catch (\Throwable $throwable) {
            $release();
            App::logger()->warning('realtime', 'realtime session setup failed', ['error' => $throwable->getMessage()]);

            return ['ok' => false, 'status' => 502, 'code' => 'PROVIDER_AUTH_FAILED', 'message' => 'Realtime channel could not be prepared'];
        }

        return ['ok' => true, 'data' => [
            'session_id' => $sessionId,
            'upstream_url' => $url,
            'upstream_headers' => $headers,
            'usage_protocol' => ($route['protocol'] ?? '') === 'gemini' ? 'gemini' : 'openai',
            'max_session_seconds' => $maxSeconds,
        ]];
    }

    /**
     * @param array<string, mixed> $usage
     * @return array{ok: bool, status?: int, code?: string, message?: string, data?: array<string, mixed>}
     */
    public function recordUsage(string $sessionId, array $usage, string $status, string $errorCode, int $durationMs, bool $final): array
    {
        $session = App::redis()->get(self::SESSION_PREFIX . $sessionId);
        if (!is_array($session)) {
            return ['ok' => false, 'status' => 404, 'code' => 'SESSION_NOT_FOUND', 'message' => 'Unknown realtime session'];
        }
        $apiKey = is_array($session['api_key'] ?? null) ? $session['api_key'] : [];
        $route = is_array($session['route'] ?? null) ? $session['route'] : [];
        $canonical = [
            'input_tokens' => max(0, (int) ($usage['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($usage['output_tokens'] ?? 0)),
            'cache_read_tokens' => max(0, (int) ($usage['cache_read_tokens'] ?? 0)),
            'cache_write_tokens' => 0,
            'audio_input_tokens' => max(0, (int) ($usage['audio_input_tokens'] ?? 0)),
            'audio_output_tokens' => max(0, (int) ($usage['audio_output_tokens'] ?? 0)),
            'image_input_tokens' => max(0, (int) ($usage['image_input_tokens'] ?? 0)),
            'audio_seconds' => max(0, (int) ($usage['audio_seconds'] ?? 0)),
        ];
        $canonical['total_tokens'] = $canonical['input_tokens'] + $canonical['output_tokens'];
        $hasUsage = $canonical['total_tokens'] > 0 || $canonical['audio_seconds'] > 0;

        // Each usage report is its own internal HTTP request, so its request
        // id identifies this usage record.
        $context = App::context();
        $context->setApiKey($apiKey);
        if ($hasUsage || $status !== 'success') {
            $canonical['usage_source'] = 'measured';
            $quota = new QuotaService();
            if ($quota->requiresCostTracking($apiKey) && !empty($route['id'])) {
                $priced = (new PriceService())->priceUsage((int) $route['id'], $canonical);
                if ($priced['cost_amount'] !== null) {
                    $canonical['cost_amount'] = $priced['cost_amount'];
                }
            }
            $quota->recordUsage($apiKey, $canonical);
            $httpRequest = new Request('POST', '/v1/realtime', ['REMOTE_ADDR' => (string) ($session['client_ip'] ?? '127.0.0.1')], [], [], '', $context);
            $gatewayRequest = new GatewayRequest(endpoint: '/v1/realtime', protocol: (string) ($route['protocol'] ?? 'openai'), capability: 'realtime', model: (string) ($session['model'] ?? ''), stream: true, payload: [], estimatedUsage: []);
            (new GatewayUsageRecorder())->record($httpRequest, $gatewayRequest, $status === 'success' ? 'success' : 'failed', $errorCode !== '' ? $errorCode : null, $status === 'success' ? 200 : 502, microtime(true) - $durationMs / 1000, $route, [...$canonical, 'realtime_session_id' => $sessionId]);
        }

        if ($final) {
            (new QuotaService())->releaseConcurrency(array_values(array_filter((array) ($session['concurrency_keys'] ?? []), 'is_string')));
            App::redis()->delete(self::SESSION_PREFIX . $sessionId);

            return ['ok' => true, 'data' => ['allowed' => false, 'closed' => true]];
        }

        return ['ok' => true, 'data' => ['allowed' => $this->withinQuota($apiKey)]];
    }

    /**
     * @param array<string, mixed> $apiKey authenticated key context
     * @return array{value: string, expires_at: int, model: string}
     */
    public function issueClientSecret(array $apiKey, string $model, int $ttlSeconds = 60): array
    {
        $ttl = max(10, min(600, $ttlSeconds));
        $value = 'ek_' . bin2hex(random_bytes(24));
        App::redis()->set(self::SECRET_PREFIX . hash('sha256', $value), ['api_key' => $apiKey, 'model' => $model], $ttl);

        return ['value' => $value, 'expires_at' => time() + $ttl, 'model' => $model];
    }

    /**
     * @return array{ok: bool, status?: int, code?: string, message?: string, data?: array<string, mixed>}
     */
    private function apiKeyFor(string $token, string $model, string $clientIp): array
    {
        if (str_starts_with($token, 'ek_')) {
            $secret = App::redis()->get(self::SECRET_PREFIX . hash('sha256', $token));
            if (!is_array($secret) || !is_array($secret['api_key'] ?? null)) {
                return ['ok' => false, 'status' => 401, 'code' => 'API_KEY_INVALID', 'message' => 'Client secret expired or unknown'];
            }
            if ((string) ($secret['model'] ?? '') !== '' && (string) $secret['model'] !== $model) {
                return ['ok' => false, 'status' => 403, 'code' => 'MODEL_FORBIDDEN', 'message' => 'Client secret was issued for another model'];
            }
            App::redis()->delete(self::SECRET_PREFIX . hash('sha256', $token));

            return ['ok' => true, 'data' => $secret['api_key']];
        }
        $result = (new ApiKeyService())->authenticate($token, $clientIp, $model !== '' ? $model : null);
        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'status' => (int) ($result['status'] ?? 401), 'code' => (string) ($result['code'] ?? 'API_KEY_INVALID'), 'message' => (string) ($result['message'] ?? 'Invalid API key')];
        }

        return ['ok' => true, 'data' => $result['data']];
    }

    /**
     * @param array<string, mixed> $route
     */
    public function upstreamUrl(array $route, string $upstreamModel): string
    {
        $controls = (new ProviderHeaderVault())->decode(is_array($route['default_headers'] ?? null) || is_string($route['default_headers'] ?? null) ? $route['default_headers'] : null);
        $template = (string) ($controls['tokenhub_realtime_url'] ?? '');
        if ($template !== '') {
            return str_replace('{model}', rawurlencode($upstreamModel), $template);
        }
        $resolved = (new ProviderEndpointResolver())->resolve((string) ($route['base_url'] ?? ''), '/v1/realtime', $controls);
        $url = (string) ($resolved['url'] ?? rtrim((string) ($route['base_url'] ?? ''), '/') . '/v1/realtime');
        $url = (string) preg_replace('#^http(s?)://#i', 'ws$1://', $url);

        return $url . (str_contains($url, '?') ? '&' : '?') . 'model=' . rawurlencode($upstreamModel);
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    private function withinQuota(array $apiKey): bool
    {
        $quota = new QuotaService();
        $probe = $quota->preflight($apiKey, ['input_tokens' => 1, 'total_tokens' => 1]);
        if (!($probe['allowed'] ?? false)) {
            // The session itself holds a concurrency slot; that is not a reason to stop it.
            return ($probe['code'] ?? '') === 'CONCURRENCY_EXCEEDED';
        }
        $quota->rollbackReservations($probe['reservations'] ?? []);
        $quota->releaseConcurrency($probe['concurrency_keys'] ?? []);

        return true;
    }
}
