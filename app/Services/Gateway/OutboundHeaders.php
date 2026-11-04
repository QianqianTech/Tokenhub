<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Support\App;
use TokenHub\Support\Request;

/**
 * Headers TokenHub adds to upstream requests.
 *
 * PROTO-001 client header forwarding: a small allowlist of protocol headers
 * the official SDKs and Claude Code send is passed through to channels of the
 * same protocol. Without anthropic-beta the 1M-context and interleaved
 * thinking betas silently turn off; OpenAI-Beta selects Assistants/Realtime
 * API versions; x-stainless-* are the SDK's retry/timeout/runtime hints.
 * Anything else the client sends is never forwarded (credentials, cookies,
 * forwarding headers). GATEWAY_FORWARD_CLIENT_HEADERS=false turns this off;
 * GATEWAY_FORWARD_CLIENT_HEADERS_EXTRA adds comma-separated header names.
 *
 * BIZ-008 trace headers: every upstream request carries X-Request-Id (the
 * gateway request id) and a W3C traceparent whose trace id continues the
 * client's traceparent when one was sent, otherwise is derived from the
 * request's trace id. GATEWAY_UPSTREAM_TRACE_HEADERS=false turns this off.
 */
final class OutboundHeaders
{
    /** protocol => exact header names (lower case) */
    private const FORWARD = [
        'anthropic' => ['anthropic-beta', 'anthropic-version'],
        'openai' => ['openai-beta'],
    ];

    /** Prefixes forwarded to openai and anthropic channels. */
    private const FORWARD_PREFIXES = ['x-stainless-'];

    private const MAX_VALUE_BYTES = 1024;

    /**
     * Allowlisted headers from the client request, keyed by lower-case name.
     *
     * @return array<string, string>
     */
    public static function fromClient(Request $request): array
    {
        if (!filter_var(env_value('GATEWAY_FORWARD_CLIENT_HEADERS', true), FILTER_VALIDATE_BOOL)) {
            return [];
        }
        $exact = array_merge(...array_values(self::FORWARD));
        foreach (explode(',', (string) env_value('GATEWAY_FORWARD_CLIENT_HEADERS_EXTRA', '')) as $extra) {
            $extra = strtolower(trim($extra));
            if ($extra !== '' && !self::forbidden($extra)) {
                $exact[] = $extra;
            }
        }
        $out = [];
        foreach ($request->server as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'HTTP_') || !is_scalar($value)) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $allowed = in_array($name, $exact, true);
            foreach (self::FORWARD_PREFIXES as $prefix) {
                $allowed = $allowed || str_starts_with($name, $prefix);
            }
            $value = (string) $value;
            if (!$allowed || $value === '' || strlen($value) > self::MAX_VALUE_BYTES || preg_match('/[\r\n\0]/', $value) === 1) {
                continue;
            }
            $out[$name] = $value;
        }
        ksort($out);

        return $out;
    }

    /**
     * Client headers that apply to an upstream of $protocol.
     *
     * @param array<string, string> $forward
     * @return array<string, string>
     */
    public static function forProtocol(array $forward, string $protocol): array
    {
        if ($forward === [] || !in_array($protocol, ['openai', 'anthropic'], true)) {
            return [];
        }
        $foreign = $protocol === 'anthropic' ? self::FORWARD['openai'] : self::FORWARD['anthropic'];
        $out = [];
        foreach ($forward as $name => $value) {
            if (in_array($name, $foreign, true) || self::forbidden($name)) {
                continue;
            }
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * X-Request-Id and traceparent for one upstream request.
     *
     * @return array<string, string>
     */
    public static function trace(): array
    {
        if (!filter_var(env_value('GATEWAY_UPSTREAM_TRACE_HEADERS', true), FILTER_VALIDATE_BOOL)) {
            return [];
        }
        try {
            $context = App::context();
            $requestId = $context->requestId();
            $traceId = $context->traceId();
        } catch (\Throwable) {
            return [];
        }

        return [
            'X-Request-Id' => $requestId,
            'traceparent' => self::traceparent($traceId, $_SERVER['HTTP_TRACEPARENT'] ?? null),
        ];
    }

    /**
     * W3C trace context: 00-<32 hex trace id>-<16 hex span id>-01.
     */
    public static function traceparent(string $traceId, mixed $incoming = null): string
    {
        $trace = null;
        if (is_string($incoming) && preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/', strtolower(trim($incoming)), $match) === 1
            && $match[1] !== str_repeat('0', 32)) {
            $trace = $match[1];
        }
        if ($trace === null) {
            $hex = strtolower((string) preg_replace('/[^0-9a-f]/i', '', (string) preg_replace('/^[a-z]+_/i', '', $traceId)));
            $trace = strlen($hex) >= 32 ? substr($hex, 0, 32) : substr(hash('sha256', $traceId), 0, 32);
        }

        return '00-' . $trace . '-' . bin2hex(random_bytes(8)) . '-01';
    }

    private static function forbidden(string $name): bool
    {
        return in_array($name, ['authorization', 'x-api-key', 'x-goog-api-key', 'cookie', 'host', 'content-length', 'content-type', 'transfer-encoding', 'connection', 'proxy-authorization', 'forwarded', 'x-forwarded-for', 'x-real-ip'], true);
    }
}
