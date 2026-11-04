<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use Throwable;
use TokenHub\Support\App;
use TokenHub\Support\ConfigVersion;
use TokenHub\Support\Crypto;
use TokenHub\Support\Request;

/**
 * Hands the usage record of a gateway request to the queue (usage.write).
 *
 * QUAL-002: the request was already charged when this runs, so a Redis
 * failure must not turn it into a 500. When the push fails the job is
 * appended to a local spool file (storage/spool/usage-*.jsonl, one JSON job
 * per line) and the client gets its normal response; the spool is pushed
 * back to the queue by the next request that reaches Redis again (one
 * worker at a time, under an exclusive lock). Retained prompt/response
 * content is trimmed before it is queued: nothing when the retention policy
 * is "none" everywhere, otherwise bounded to CONTENT_RETAIN_MAX_BYTES (what
 * the consumer would keep anyway), so multi-megabyte payloads no longer sit
 * in Redis.
 */
final class GatewayUsageRecorder
{
    private const SPOOL_DIR = 'spool';
    private const SPOOL_PREFIX = 'usage-';
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

        if ($content !== null && self::retainsContent()) {
            // Prompt/response bodies never sit in Redis in clear text: the
            // queue carries ciphertext, the consumer applies the retention
            // policy (none/summary/redacted/raw) before anything is stored.
            $content['input'] = self::bounded(MultipartUpload::describe(is_array($content['input'] ?? null) ? $content['input'] : []));
            $content['output'] = self::bounded(is_array($content['output'] ?? null) ? $content['output'] : []);
            $record['content_encrypted'] = Crypto::encrypt((string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        self::enqueue(['type' => 'usage.write', 'record' => $record]);
        $logRecord = $record;
        unset($logRecord['content'], $logRecord['content_encrypted']);
        try {
            App::logger()->info('gateway-access', 'gateway request', $logRecord);
        } catch (Throwable) {
        }
    }

    /**
     * Pushes a queue job; on failure spools it locally instead of failing the
     * (already charged) request. Returns false when the job was spooled.
     *
     * @param array<string, mixed> $job
     */
    public static function enqueue(array $job): bool
    {
        $queue = (string) App::config()->get('queue.name', 'tokenhub:jobs');
        try {
            App::redis()->push($queue, $job);
        } catch (Throwable $throwable) {
            self::spool($job, $throwable->getMessage());
            return false;
        }
        self::drainSpool($queue);

        return true;
    }

    public static function spoolDirectory(): string
    {
        $configured = trim((string) env_value('GATEWAY_USAGE_SPOOL_DIR', ''));

        return $configured !== '' ? rtrim($configured, DIRECTORY_SEPARATOR) : storage_path(self::SPOOL_DIR);
    }

    /**
     * Re-queues spooled jobs. Returns the number of jobs pushed.
     */
    public static function drainSpool(?string $queue = null, int $maxFiles = 5): int
    {
        $directory = self::spoolDirectory();
        $files = is_dir($directory) ? (glob($directory . DIRECTORY_SEPARATOR . self::SPOOL_PREFIX . '*.jsonl') ?: []) : [];
        if ($files === []) {
            return 0;
        }
        $queue ??= (string) App::config()->get('queue.name', 'tokenhub:jobs');
        $lock = @fopen($directory . DIRECTORY_SEPARATOR . '.drain.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }
        $pushed = 0;
        try {
            sort($files);
            foreach (array_slice($files, 0, $maxFiles) as $file) {
                // Rename first so concurrent spoolers append to a new file.
                $claimed = $file . '.draining';
                if (!@rename($file, $claimed)) {
                    continue;
                }
                $lines = @file($claimed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $left = [];
                foreach ($lines as $index => $line) {
                    $job = json_decode($line, true);
                    if (!is_array($job)) {
                        continue;
                    }
                    try {
                        App::redis()->push($queue, $job);
                        $pushed++;
                    } catch (Throwable) {
                        $left = array_slice($lines, $index);
                        break;
                    }
                }
                if ($left !== []) {
                    @file_put_contents($file, implode("\n", $left) . "\n", FILE_APPEND | LOCK_EX);
                }
                @unlink($claimed);
                if ($left !== []) {
                    break;
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($pushed > 0) {
            try {
                App::logger()->warning('gateway', 'spooled usage records re-queued', ['count' => $pushed]);
            } catch (Throwable) {
            }
        }

        return $pushed;
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function spool(array $job, string $reason): void
    {
        $directory = self::spoolDirectory();
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        $file = $directory . DIRECTORY_SEPARATOR . self::SPOOL_PREFIX . gmdate('YmdH') . '.jsonl';
        $written = @file_put_contents($file, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        try {
            App::logger()->error('usage record queue push failed; spooled locally', [
                'request_id' => $job['record']['request_id'] ?? null,
                'spooled' => $written !== false,
                'error' => $reason,
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * False when the retention policy stores no content for anyone (global
     * mode "none" and no scoped override), so nothing is queued. Cached with
     * the config generation.
     */
    private static function retainsContent(): bool
    {
        $key = 'content_retention:retains' . ConfigVersion::cacheSuffix();
        try {
            $cached = App::redis()->get($key);
            if ($cached === '1' || $cached === 1 || $cached === true) {
                return true;
            }
            if ($cached === '0' || $cached === 0 || $cached === false) {
                return false;
            }
            $policy = (new \TokenHub\Services\ContentRetentionService())->policy();
            $retains = ($policy['mode'] ?? 'summary') !== 'none';
            foreach (['departments', 'users', 'api_keys'] as $scope) {
                foreach ((array) ($policy[$scope] ?? []) as $entry) {
                    $retains = $retains || (is_array($entry) && ($entry['mode'] ?? 'none') !== 'none');
                }
            }
            App::redis()->set($key, $retains ? '1' : '0', 60);

            return $retains;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Same bound as ContentRetentionService::bounded(), applied before the
     * queue. The preview is cut to half the limit so its JSON-escaped form
     * still fits and the consumer keeps it unchanged.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private static function bounded(array $content): array
    {
        $max = max(1024, (int) env_value('CONTENT_RETAIN_MAX_BYTES', 262144));
        $encoded = (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) <= $max) {
            return $content;
        }

        return [
            'truncated' => true,
            'original_bytes' => strlen($encoded),
            'preview' => mb_strcut($encoded, 0, intdiv($max, 2) - 64),
        ];
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
