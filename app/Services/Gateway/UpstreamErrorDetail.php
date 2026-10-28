<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * Client-safe details of an upstream 4xx rejection.
 *
 * Clients depend on the upstream's error type and message to react
 * correctly: Claude Code compacts the conversation on "prompt is too long",
 * SDKs truncate on context_length_exceeded, tools fix an invalid parameter.
 * Replacing them with a generic text makes TokenHub less capable than a
 * direct connection, so request-level rejections are passed through.
 *
 * What is never passed through:
 * - anything that is not a request problem (401/403/429/5xx stay generic:
 *   they describe TokenHub's upstream account, not the caller's request);
 * - messages about the upstream account (billing, credit, quota, keys,
 *   organizations, permissions);
 * - secrets, URLs, organization/project ids and e-mail addresses inside the
 *   message, and the upstream model id (replaced by the public model name).
 */
final class UpstreamErrorDetail
{
    /** Gateway statuses that describe the caller's own request. */
    public const PASS_THROUGH_STATUSES = [400, 404, 413, 422];

    private const MAX_MESSAGE_LENGTH = 1000;

    private const ACCOUNT_PATTERN = '/credit balance|billing|insufficient[_ ]quota|\bquota\b|payment|\baccount\b|organi[sz]ation|api[ _-]?key|authenticat|permission|unauthori[sz]ed|access denied|forbidden/i';

    /**
     * @param array<string, mixed>|string|null $body upstream error body (decoded JSON or raw text)
     * @param array<string, mixed> $route
     * @return array{message: string, type?: string, code?: string, param?: string}|null
     */
    public static function extract(array|string|null $body, int $gatewayStatus, array $route = []): ?array
    {
        if (!in_array($gatewayStatus, self::PASS_THROUGH_STATUSES, true)) {
            return null;
        }
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;
        $message = null;
        $type = null;
        $code = null;
        $param = null;
        if (is_array($error)) {
            // OpenAI {"error":{message,type,param,code}}, Anthropic
            // {"type":"error","error":{type,message}}, Google
            // {"error":{code,message,status}}.
            $message = $error['message'] ?? null;
            $type = $error['type'] ?? ($error['status'] ?? null);
            $code = $error['code'] ?? null;
            $param = $error['param'] ?? null;
        } elseif (is_string($error)) {
            $message = $error;
        }
        $message ??= $body['message'] ?? ($body['detail'] ?? null);
        if (!is_string($message) || trim($message) === '') {
            return null;
        }
        if (preg_match(self::ACCOUNT_PATTERN, $message) === 1) {
            return null;
        }

        $detail = ['message' => self::sanitize($message, $route)];
        if (is_string($type) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $type) === 1) {
            $detail['type'] = $type;
        }
        // Numeric codes (Google repeats the HTTP status) carry no extra meaning.
        if (is_string($code) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $code) === 1) {
            $detail['code'] = $code;
        }
        if (is_string($param) && preg_match('/^[A-Za-z0-9_.\[\]-]{1,128}$/', $param) === 1) {
            $detail['param'] = $param;
        }

        return $detail;
    }

    /**
     * @param array<string, mixed> $route
     */
    public static function sanitize(string $message, array $route = []): string
    {
        $message = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message);
        $message = (string) preg_replace('/\s{2,}/u', ' ', trim($message));

        $external = trim((string) ($route['external_name'] ?? ''));
        $public = trim((string) ($route['name'] ?? ''));
        if ($external !== '' && $public !== '' && strcasecmp($external, $public) !== 0) {
            $message = str_ireplace($external, $public, $message);
        }

        $message = (string) preg_replace([
            '/\b(?:sk|pk|rk)-[A-Za-z0-9_\-]{6,}/',
            '/\b(?:org|proj|acct|user)[-_][A-Za-z0-9]{6,}/i',
            '/\breq_[A-Za-z0-9]{8,}/',
            '/https?:\/\/[^\s"\'<>)]+/i',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        ], ['[redacted]', '[redacted]', '[redacted]', '[url]', '[email]', '[ip]'], $message);

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . '…';
        }

        return $message;
    }

    /**
     * Anthropic error type for a gateway status (used when the upstream did
     * not provide one, e.g. an OpenAI-format error behind the Kimi bridge).
     */
    public static function anthropicType(int $status, ?string $upstreamType = null): string
    {
        $known = ['invalid_request_error', 'authentication_error', 'permission_error', 'not_found_error', 'request_too_large', 'rate_limit_error', 'api_error', 'overloaded_error'];
        if ($upstreamType !== null && in_array($upstreamType, $known, true)) {
            return $upstreamType;
        }

        return match (true) {
            $status === 404 => 'not_found_error',
            $status === 413 => 'request_too_large',
            $status === 429 => 'rate_limit_error',
            $status === 529 => 'overloaded_error',
            $status >= 500 => 'api_error',
            default => 'invalid_request_error',
        };
    }
}
