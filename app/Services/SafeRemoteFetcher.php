<?php

declare(strict_types=1);

namespace TokenHub\Services;

/**
 * Bounded HTTP GET for URLs that came from an upstream response.
 *
 * Every redirect hop is re-validated against OutboundUrlPolicy, the body is
 * read in chunks and abandoned once it exceeds the byte limit, and the socket
 * timeout applies to each read.
 */
final class SafeRemoteFetcher
{
    /** @var null|callable(string, array<string, mixed>): array{status: int, headers: list<string>, body: string|false, truncated: bool} */
    private $transport;

    public function __construct(
        private readonly ?OutboundUrlPolicy $policy = null,
        ?callable $transport = null,
        private readonly int $maxBytes = 0,
        private readonly float $timeoutSeconds = 0.0,
        private readonly int $maxRedirects = 3,
    ) {
        $this->transport = $transport;
    }

    /**
     * @return array{ok: bool, status?: int, body?: string, content_type?: string|null, error?: string}
     */
    public function get(string $url, string $purpose = OutboundUrlPolicy::PURPOSE_REMOTE_ASSET): array
    {
        $policy = $this->policy ?? new OutboundUrlPolicy();
        $maxBytes = $this->maxBytes > 0 ? $this->maxBytes : (int) env_value('OUTBOUND_DOWNLOAD_MAX_BYTES', 104857600);
        $timeout = $this->timeoutSeconds > 0 ? $this->timeoutSeconds : (float) env_value('OUTBOUND_DOWNLOAD_TIMEOUT_SECONDS', 30);

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            $check = $policy->check($url, $purpose);
            if (!$check['ok']) {
                return ['ok' => false, 'error' => 'OUTBOUND_BLOCKED:' . ($check['reason'] ?? 'denied')];
            }

            $result = ($this->transport ?? [$this, 'transport'])($url, ['max_bytes' => $maxBytes, 'timeout' => $timeout]);
            if ($result['truncated']) {
                return ['ok' => false, 'error' => 'OUTBOUND_TOO_LARGE'];
            }
            $status = $result['status'];
            if ($status >= 300 && $status < 400) {
                $location = $this->header($result['headers'], 'location');
                if ($location === null) {
                    return ['ok' => false, 'status' => $status, 'error' => 'OUTBOUND_REDIRECT_WITHOUT_LOCATION'];
                }
                $url = $this->resolveLocation($url, $location);
                continue;
            }
            if ($status === 0 || $result['body'] === false) {
                return ['ok' => false, 'status' => $status, 'error' => 'OUTBOUND_UNAVAILABLE'];
            }
            if ($status >= 400) {
                return ['ok' => false, 'status' => $status, 'error' => 'OUTBOUND_HTTP_ERROR'];
            }

            return ['ok' => true, 'status' => $status, 'body' => $result['body'], 'content_type' => $this->header($result['headers'], 'content-type')];
        }

        return ['ok' => false, 'error' => 'OUTBOUND_TOO_MANY_REDIRECTS'];
    }

    /**
     * @param array{max_bytes: int, timeout: float} $options
     * @return array{status: int, headers: list<string>, body: string|false, truncated: bool}
     */
    private function transport(string $url, array $options): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $options['timeout'],
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
            ],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        if (!is_resource($stream)) {
            return ['status' => $status, 'headers' => $headers, 'body' => false, 'truncated' => false];
        }
        $body = '';
        $truncated = false;
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $options['max_bytes']) {
                $truncated = true;
                break;
            }
            $meta = stream_get_meta_data($stream);
            if (($meta['timed_out'] ?? false) === true) {
                fclose($stream);
                return ['status' => 0, 'headers' => $headers, 'body' => false, 'truncated' => false];
            }
        }
        fclose($stream);

        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'truncated' => $truncated];
    }

    /**
     * @param list<string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $value = null;
        foreach ($headers as $line) {
            if (stripos($line, $name . ':') === 0) {
                $value = trim(substr($line, strlen($name) + 1));
            }
        }

        return $value;
    }

    private function resolveLocation(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($current);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname((string) ($parts['path'] ?? '/')), '/');

        return $origin . $dir . '/' . $location;
    }
}
