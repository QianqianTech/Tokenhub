<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

use TokenHub\Support\App;

/**
 * Pluggable upstream authentication (MM-011).
 *
 * The scheme comes from the provider control "tokenhub_auth" (default_headers)
 * or from the vendor profile; the credential is always the provider secret
 * (providers.encrypted_secret), never a control value:
 *
 *   {"type":"bearer"}                                   Authorization: Bearer <secret> (default)
 *   {"type":"header","name":"x-key","prefix":""}       any header; prefix "Token " (Vidu), "Key " (fal)
 *   {"type":"query","name":"key"}                       ?key=<secret> (Gemini WebSocket style)
 *   {"type":"jwt_hs256","ttl":1800}                     secret "AK:SK" -> HS256 JWT {iss:AK} (Kling AK/SK)
 *   {"type":"bearer_or_jwt"}                            "AK:SK" -> jwt_hs256, otherwise bearer
 *   {"type":"tc3_hmac","service":"hunyuan","region":"ap-guangzhou","version":"2023-09-01"}
 *                                                       secret "SecretId:SecretKey" (Tencent Cloud API 3.0)
 *   {"type":"google_service_account","scope":"..."}     secret = service account JSON -> OAuth access token
 */
final class ProviderAuth
{
    /** @var null|callable(string, string): array{status: int, body: string} */
    private $tokenTransport;

    /**
     * @param null|callable(string, string): array{status: int, body: string} $tokenTransport POST (url, form body) for OAuth tests
     */
    public function __construct(?callable $tokenTransport = null, private readonly ?int $now = null)
    {
        $this->tokenTransport = $tokenTransport;
    }

    /**
     * @param array<string, mixed> $scheme
     * @param array<string, string> $headers
     * @return array{headers: array<string, string>, url: string}
     */
    public function apply(array $scheme, string $secret, string $method, string $url, string $body, array $headers, string $protocol = 'openai'): array
    {
        $type = strtolower((string) ($scheme['type'] ?? ''));
        if ($type === '') {
            $type = match ($protocol) {
                'anthropic' => 'anthropic',
                'gemini' => 'gemini',
                default => 'bearer',
            };
        }
        if (in_array($type, ['bearer', 'header', 'query', 'anthropic', 'gemini'], true) && str_starts_with(ltrim($secret), '{') && str_contains($secret, 'private_key')) {
            // A service account JSON is never sent as a plain credential.
            throw new \RuntimeException('PROVIDER_AUTH_INVALID: service account JSON needs {"type":"google_service_account"}');
        }
        if ($type === 'bearer_or_jwt') {
            $type = str_contains($secret, ':') ? 'jwt_hs256' : 'bearer';
        }

        switch ($type) {
            case 'anthropic':
                $headers['x-api-key'] = $secret;
                $headers['anthropic-version'] ??= '2023-06-01';
                break;
            case 'gemini':
                $headers['x-goog-api-key'] = $secret;
                break;
            case 'header':
                $name = trim((string) ($scheme['name'] ?? 'Authorization'));
                $headers[$name !== '' ? $name : 'Authorization'] = (string) ($scheme['prefix'] ?? '') . $secret;
                break;
            case 'query':
                $name = (string) ($scheme['name'] ?? 'key');
                $url .= (str_contains($url, '?') ? '&' : '?') . rawurlencode($name) . '=' . rawurlencode($secret);
                break;
            case 'jwt_hs256':
                $headers['Authorization'] = 'Bearer ' . $this->jwtHs256($secret, (int) ($scheme['ttl'] ?? 1800));
                break;
            case 'tc3_hmac':
                $headers = $this->tc3($scheme, $secret, $method, $url, $body, $headers);
                break;
            case 'google_service_account':
                $headers['Authorization'] = 'Bearer ' . $this->googleAccessToken($secret, (string) ($scheme['scope'] ?? 'https://www.googleapis.com/auth/cloud-platform'));
                break;
            default:
                $headers['Authorization'] = 'Bearer ' . $secret;
        }

        return ['headers' => $headers, 'url' => $url];
    }

    /**
     * Kling AK/SK token: HS256 JWT, iss = AccessKey, signed with SecretKey.
     */
    public function jwtHs256(string $secret, int $ttl = 1800): string
    {
        [$accessKey, $secretKey] = array_pad(explode(':', $secret, 2), 2, '');
        $now = $this->now ?? time();
        $header = $this->base64Url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode(['iss' => $accessKey, 'exp' => $now + max(60, $ttl), 'nbf' => $now - 5]));
        $signature = $this->base64Url(hash_hmac('sha256', $header . '.' . $claims, $secretKey, true));

        return $header . '.' . $claims . '.' . $signature;
    }

    /**
     * Tencent Cloud API 3.0 signature (TC3-HMAC-SHA256). The action comes
     * from the X-TC-Action header the adapter sets; version/region from the
     * scheme (or X-TC-Version / X-TC-Region headers).
     *
     * @param array<string, mixed> $scheme
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function tc3(array $scheme, string $secret, string $method, string $url, string $body, array $headers): array
    {
        [$secretId, $secretKey] = array_pad(explode(':', $secret, 2), 2, '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $service = (string) ($scheme['service'] ?? explode('.', $host)[0]);
        $lower = array_change_key_case($headers, CASE_LOWER);
        $action = (string) ($lower['x-tc-action'] ?? $scheme['action'] ?? '');
        $timestamp = $this->now ?? time();
        $date = gmdate('Y-m-d', $timestamp);
        $contentType = 'application/json; charset=utf-8';
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');

        $canonicalHeaders = 'content-type:' . $contentType . "\n" . 'host:' . $host . "\n" . 'x-tc-action:' . strtolower($action) . "\n";
        $signedHeaders = 'content-type;host;x-tc-action';
        $canonicalRequest = strtoupper($method) . "\n" . $path . "\n" . $query . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . hash('sha256', strtoupper($method) === 'GET' ? '' : $body);
        $scope = $date . '/' . $service . '/tc3_request';
        $stringToSign = "TC3-HMAC-SHA256\n" . $timestamp . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
        $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower((string) $name), ['content-type', 'x-tc-action', 'x-tc-timestamp', 'x-tc-version', 'x-tc-region', 'host', 'authorization'], true)) {
                unset($headers[$name]);
            }
        }
        $headers['Content-Type'] = $contentType;
        $headers['Host'] = $host;
        $headers['X-TC-Action'] = $action;
        $headers['X-TC-Timestamp'] = (string) $timestamp;
        $headers['X-TC-Version'] = (string) ($lower['x-tc-version'] ?? $scheme['version'] ?? '');
        $region = (string) ($lower['x-tc-region'] ?? $scheme['region'] ?? '');
        if ($region !== '') {
            $headers['X-TC-Region'] = $region;
        }
        $headers['Authorization'] = 'TC3-HMAC-SHA256 Credential=' . $secretId . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        return $headers;
    }

    /**
     * OAuth 2.0 JWT-bearer grant for a Google service account; the access
     * token is cached until shortly before it expires.
     */
    public function googleAccessToken(string $serviceAccountJson, string $scope): string
    {
        $account = json_decode($serviceAccountJson, true);
        if (!is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
            throw new \RuntimeException('PROVIDER_AUTH_INVALID: service account JSON needs client_email and private_key');
        }
        $tokenUri = (string) ($account['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $cacheKey = 'provider_auth:gsa:' . hash('sha256', $account['client_email'] . '|' . $scope);
        try {
            $cached = App::redis()->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (\Throwable) {
        }

        $now = $this->now ?? time();
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode(['iss' => $account['client_email'], 'scope' => $scope, 'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600]));
        $signature = '';
        if (!openssl_sign($header . '.' . $claims, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('PROVIDER_AUTH_INVALID: cannot sign with the service account key');
        }
        $assertion = $header . '.' . $claims . '.' . $this->base64Url($signature);
        $form = http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
        $response = $this->tokenTransport !== null ? ($this->tokenTransport)($tokenUri, $form) : $this->postForm($tokenUri, $form);
        $decoded = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($decoded) || empty($decoded['access_token'])) {
            throw new \RuntimeException('PROVIDER_AUTH_FAILED: OAuth token request failed');
        }
        try {
            App::redis()->set($cacheKey, (string) $decoded['access_token'], max(60, (int) ($decoded['expires_in'] ?? 3600) - 300));
        } catch (\Throwable) {
        }

        return (string) $decoded['access_token'];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function postForm(string $url, string $form): array
    {
        $check = (new \TokenHub\Services\OutboundUrlPolicy())->check($url, \TokenHub\Services\OutboundUrlPolicy::PURPOSE_PROVIDER);
        if (!($check['ok'] ?? false)) {
            return ['status' => 0, 'body' => ''];
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
            'content' => $form,
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
