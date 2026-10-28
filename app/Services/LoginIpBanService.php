<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class LoginIpBanService
{
    private const DEFAULT_FAILURE_THRESHOLD = 5;
    private const CACHE_TTL_SECONDS = 30;

    /**
     * @return array{client_ip: string, failure_count: int, banned: bool}
     */
    public function recordFailure(string $clientIp, string $reason): array
    {
        $clientIp = $this->normalizeIp($clientIp);
        if ($clientIp === '') {
            return ['client_ip' => $clientIp, 'failure_count' => 0, 'banned' => false];
        }

        $result = App::db()->transaction(function () use ($clientIp, $reason): array {
            $row = App::db()->first('SELECT * FROM system_ip_bans WHERE client_ip = ? FOR UPDATE', [$clientIp]);
            $threshold = $this->failureThreshold();
            if ($row !== null && $row['banned_at'] !== null) {
                return $this->result($row);
            }

            $failureCount = (int) ($row['failure_count'] ?? 0) + 1;
            if ($row === null) {
                App::db()->execute(
                    'INSERT INTO system_ip_bans (client_ip, failure_count, reason, last_failed_at, banned_at) VALUES (?, ?, ?, NOW(), IF(? >= ?, NOW(), NULL))',
                    [$clientIp, $failureCount, $reason, $failureCount, $threshold]
                );
            } else {
                App::db()->execute(
                    'UPDATE system_ip_bans SET failure_count = ?, reason = ?, last_failed_at = NOW(), banned_at = IF(? >= ?, COALESCE(banned_at, NOW()), banned_at) WHERE client_ip = ?',
                    [$failureCount, $reason, $failureCount, $threshold, $clientIp]
                );
            }

            $updated = App::db()->first('SELECT * FROM system_ip_bans WHERE client_ip = ?', [$clientIp]);
            return $this->result($updated ?? ['client_ip' => $clientIp, 'failure_count' => $failureCount, 'banned_at' => null]);
        });
        App::redis()->set($this->cacheKey($clientIp), $result['banned'] ? '1' : '0', self::CACHE_TTL_SECONDS);

        return $result;
    }

    public function reset(string $clientIp): void
    {
        $clientIp = $this->normalizeIp($clientIp);
        if ($clientIp === '') {
            return;
        }

        App::db()->execute('DELETE FROM system_ip_bans WHERE client_ip = ?', [$clientIp]);
        App::redis()->delete($this->cacheKey($clientIp));
    }

    /**
     * @return array{client_ip: string, failure_count: int, banned: bool}
     */
    public function ban(string $clientIp, string $reason): array
    {
        $clientIp = $this->normalizeIp($clientIp);
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            abort_json('WAF_IP_INVALID', 'A valid IP address is required', 422);
        }

        App::db()->execute(
            'INSERT INTO system_ip_bans (client_ip, failure_count, reason, last_failed_at, banned_at)
             VALUES (?, 1, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                failure_count = GREATEST(failure_count, 1),
                reason = VALUES(reason),
                last_failed_at = NOW(),
                banned_at = NOW()',
            [$clientIp, substr(trim($reason), 0, 120)]
        );
        App::redis()->set($this->cacheKey($clientIp), '1', self::CACHE_TTL_SECONDS);

        return $this->result(App::db()->first('SELECT * FROM system_ip_bans WHERE client_ip = ?', [$clientIp]) ?: []);
    }

    /**
     * @return array{records: list<array<string, mixed>>, pagination: array{limit: int, total: int}}
     */
    public function listBans(array $query = []): array
    {
        $limit = min(200, max(1, (int) ($query['limit'] ?? 50)));
        $rows = App::db()->select(
            "SELECT id, client_ip, failure_count, reason, last_failed_at, banned_at
             FROM system_ip_bans
             WHERE banned_at IS NOT NULL
             ORDER BY banned_at DESC, id DESC
             LIMIT {$limit}"
        );

        return [
            'records' => $rows,
            'pagination' => ['limit' => $limit, 'total' => count($rows)],
        ];
    }

    public function isBanned(string $clientIp): bool
    {
        $clientIp = $this->normalizeIp($clientIp);
        if ($clientIp === '') {
            return false;
        }

        $cacheKey = $this->cacheKey($clientIp);
        $cached = App::redis()->get($cacheKey);
        if ($cached !== null) {
            return (string) $cached === '1';
        }

        $banned = App::db()->first('SELECT id FROM system_ip_bans WHERE client_ip = ? AND banned_at IS NOT NULL LIMIT 1', [$clientIp]) !== null;
        App::redis()->set($cacheKey, $banned ? '1' : '0', self::CACHE_TTL_SECONDS);

        return $banned;
    }

    public function cacheKey(string $clientIp): string
    {
        return 'system_ip_ban:' . $this->normalizeIp($clientIp);
    }

    /**
     * @return list<string>
     */
    public function activeBannedIps(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['client_ip'],
            App::db()->select('SELECT client_ip FROM system_ip_bans WHERE banned_at IS NOT NULL ORDER BY id ASC')
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{client_ip: string, failure_count: int, banned: bool}
     */
    private function result(array $row): array
    {
        return [
            'client_ip' => (string) ($row['client_ip'] ?? ''),
            'failure_count' => (int) ($row['failure_count'] ?? 0),
            'banned' => ($row['banned_at'] ?? null) !== null,
        ];
    }

    private function normalizeIp(string $clientIp): string
    {
        $clientIp = trim(explode(',', $clientIp)[0] ?? $clientIp);
        return $clientIp === 'unknown' ? '' : substr($clientIp, 0, 64);
    }

    private function failureThreshold(): int
    {
        try {
            $settings = (new SystemSettingsService())->loadSection('firewall_settings');
            return max(1, (int) ($settings['login_failure_threshold'] ?? self::DEFAULT_FAILURE_THRESHOLD));
        } catch (\Throwable) {
            return self::DEFAULT_FAILURE_THRESHOLD;
        }
    }
}
