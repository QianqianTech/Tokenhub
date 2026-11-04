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
     * @return array{healthy: bool, services: array<string, array{healthy: bool, required: bool}>}
     */
    public function readiness(): array
    {
        $checks = $this->check();
        $services = $checks['services'];
        $services['queue-worker'] = ['healthy' => $this->heartbeat('operations:worker:queue-worker')];
        // BIZ-017: the scheduler enqueues every maintenance job; without it
        // retention, alerts and cleanups silently stop.
        $services['scheduler'] = ['healthy' => $this->heartbeat('operations:worker:scheduler')];
        $services['schema'] = ['healthy' => $this->schemaUpToDate()];
        $required = $this->requiredServices();
        if ($this->qdrantConfigured()) {
            $services['qdrant'] = ['healthy' => $this->qdrantHealthy()];
        }
        $services['rag-worker'] = ['healthy' => $this->heartbeat('operations:worker:rag-worker')];
        foreach ($services as $name => $service) {
            $services[$name]['required'] = in_array($name, ['php', 'mysql', 'redis', 'schema'], true) || in_array($name, $required, true);
        }

        return [
            'healthy' => !in_array(false, array_map(
                static fn (array $service): bool => !$service['required'] || (bool) ($service['healthy'] ?? false),
                $services,
            ), true),
            'services' => $services,
        ];
    }

    /**
     * Components that make /ready fail when down (READINESS_REQUIRE, comma
     * list). qdrant and rag-worker are reported but optional by default: a
     * stopped RAG worker must not take the whole gateway out of rotation.
     *
     * @return list<string>
     */
    public function requiredServices(): array
    {
        $value = (string) env_value('READINESS_REQUIRE', 'queue-worker,scheduler');

        return array_values(array_filter(array_map(static fn (string $item): string => strtolower(trim($item)), explode(',', $value))));
    }

    private function heartbeat(string $key): bool
    {
        try {
            return $this->redis->get($key) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function qdrantConfigured(): bool
    {
        try {
            return strtolower((string) \TokenHub\Support\App::config()->get('rag.vector_store.primary', 'qdrant')) === 'qdrant';
        } catch (\Throwable) {
            return false;
        }
    }

    private function qdrantHealthy(): bool
    {
        try {
            return (new QdrantVectorStore())->healthy();
        } catch (\Throwable) {
            return false;
        }
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
