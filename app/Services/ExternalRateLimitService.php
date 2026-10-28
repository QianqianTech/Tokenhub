<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ExternalRateLimitService
{
    /**
     * @return array{allowed: bool, count: int, limit: int, retry_after: int}
     */
    public function attempt(string $scope, string|int $identity, int $limit, int $ttlSeconds = 60): array
    {
        if ($limit <= 0) {
            return ['allowed' => true, 'count' => 0, 'limit' => $limit, 'retry_after' => 0];
        }

        $ttlSeconds = max(1, $ttlSeconds);
        $result = App::redis()->increment($this->counterKey($scope, (string) $identity, $ttlSeconds, $limit), $limit, $ttlSeconds);
        $allowed = is_array($result) && (bool) ($result['allowed'] ?? false);

        return [
            'allowed' => $allowed,
            'count' => is_array($result) ? (int) ($result['count'] ?? 0) : 0,
            'limit' => $limit,
            'retry_after' => $allowed ? 0 : max(1, $ttlSeconds - (time() % $ttlSeconds)),
        ];
    }

    public function counterKey(string $scope, string|int $identity, int $ttlSeconds, int $limit): string
    {
        $bucket = intdiv(time(), max(1, $ttlSeconds));
        return 'external_rate:' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $scope) . ':' . hash('sha256', (string) $identity) . ':' . $bucket;
    }
}
