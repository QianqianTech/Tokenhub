<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\Database;
use TokenHub\Support\RedisClient;

final class HealthCheckService
{
    public function __construct(
        private readonly Database $database,
        private readonly RedisClient $redis,
    ) {
    }

    /**
     * @return array{healthy: bool, services: array<string, array{healthy: bool}>}
     */
    public function check(): array
    {
        $services = [
            'php' => ['healthy' => PHP_VERSION_ID >= 80200],
            'mysql' => ['healthy' => $this->database->isHealthy()],
            'redis' => ['healthy' => $this->redis->isHealthy()],
        ];

        return [
            'healthy' => $services['php']['healthy'] && $services['mysql']['healthy'] && $services['redis']['healthy'],
            'services' => $services,
        ];
    }

    /**
     * @return array{healthy: bool, services: array<string, array{healthy: bool}>}
     */
    public function readiness(): array
    {
        $checks = $this->check();
        $services = $checks['services'];
        try {
            $services['queue-worker'] = ['healthy' => $this->redis->get('operations:worker:queue-worker') !== null];
        } catch (\Throwable) {
            $services['queue-worker'] = ['healthy' => false];
        }
        $services['schema'] = ['healthy' => $this->schemaUpToDate()];

        return [
            'healthy' => !in_array(false, array_map(static fn (array $service): bool => (bool) ($service['healthy'] ?? false), $services), true),
            'services' => $services,
        ];
    }

    private function schemaUpToDate(): bool
    {
        try {
            $applied = array_map('strval', array_column($this->database->select('SELECT migration FROM migrations'), 'migration'));

            return \TokenHub\Console\MigrationRunner::pendingFrom(
                \TokenHub\Console\MigrationRunner::discover(base_path('database/migrations')),
                $applied
            ) === [];
        } catch (\Throwable) {
            return false;
        }
    }
}
