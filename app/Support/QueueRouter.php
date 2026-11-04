<?php

declare(strict_types=1);

namespace TokenHub\Support;

/**
 * PERF-015: job type -> queue.
 *
 * Every producer pushes to the configured main queue (queue.name); when
 * routing is on, RedisClient::push() redirects known job types to
 * "<main>:<group>" (usage, tasks, export, maintenance). Unknown types stay on
 * the main queue. A worker consumes the groups in queue.worker_queues;
 * "all" (the default) means every group plus the main queue, so a deployment
 * with one kind of worker keeps processing everything.
 */
final class QueueRouter
{
    public const GROUPS = ['usage', 'tasks', 'export', 'maintenance'];

    public function __construct(private readonly Config $config)
    {
    }

    public function mainQueue(): string
    {
        return (string) $this->config->get('queue.name', 'tokenhub:jobs');
    }

    public function routingEnabled(): bool
    {
        $value = $this->config->get('queue.routing', true);
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no', ''], true);
    }

    public function groupFor(string $type): ?string
    {
        if ($type === '') {
            return null;
        }
        foreach ($this->groups() as $group => $patterns) {
            foreach ($patterns as $pattern) {
                if ($pattern === $type || (str_contains($pattern, '*') && fnmatch($pattern, $type))) {
                    return $group;
                }
            }
        }

        return null;
    }

    /** The queue a job of this type is pushed to. */
    public function queueFor(string $type): string
    {
        $main = $this->mainQueue();
        if (!$this->routingEnabled()) {
            return $main;
        }
        $group = $this->groupFor($type);

        return $group === null ? $main : $main . ':' . $group;
    }

    /**
     * Rewrites a push to the main queue into the routed sub-queue.
     */
    public function route(string $queue, array $payload): string
    {
        if ($queue !== $this->mainQueue() || !$this->routingEnabled()) {
            return $queue;
        }

        return $this->queueFor((string) ($payload['type'] ?? ''));
    }

    /**
     * Queues this worker consumes, in priority order (usage first so billing
     * rows never wait behind exports or maintenance).
     *
     * @return list<string>
     */
    public function consumedQueues(?string $spec = null): array
    {
        $spec ??= (string) $this->config->get('queue.worker_queues', 'all');
        $main = $this->mainQueue();
        $names = array_values(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', $spec),
        ), static fn (string $part): bool => $part !== ''));
        if ($names === [] || in_array('all', $names, true) || in_array('*', $names, true)) {
            return $this->allQueues();
        }
        $queues = [];
        foreach ($names as $name) {
            if (in_array($name, ['default', 'main', 'jobs'], true)) {
                $queues[] = $main;
            } elseif (array_key_exists($name, $this->groups())) {
                $queues[] = $main . ':' . $name;
            }
        }

        return $queues === [] ? $this->allQueues() : array_values(array_unique($queues));
    }

    /** @return list<string> */
    public function allQueues(): array
    {
        $main = $this->mainQueue();
        $queues = [];
        $groups = array_keys($this->groups());
        if (in_array('usage', $groups, true)) {
            $queues[] = $main . ':usage';
        }
        $queues[] = $main;
        foreach ($groups as $group) {
            if ($group !== 'usage') {
                $queues[] = $main . ':' . $group;
            }
        }

        return $queues;
    }

    public static function processing(string $queue): string
    {
        return $queue . ':processing';
    }

    public static function delayed(string $queue): string
    {
        return $queue . ':delayed';
    }

    public static function notify(string $queue): string
    {
        return $queue . ':notify';
    }

    /** @return array<string, list<string>> */
    private function groups(): array
    {
        $groups = $this->config->get('queue.groups');
        if (!is_array($groups) || $groups === []) {
            $groups = [
                'usage' => ['usage.write'],
                'tasks' => ['tasks.poll', 'tasks.expire', 'tasks.settle', 'tasks.cancel', 'openrouter.reconcile'],
                'export' => ['export'],
                'maintenance' => ['cleanup.*', 'logs.*', 'alerts.*', 'retention.*', 'usage.reconcile', 'catalog.*', 'rag.cleanup', 'rag.reconcile', 'rag.publish-versions'],
            ];
        }
        $out = [];
        foreach ($groups as $group => $patterns) {
            $group = strtolower((string) $group);
            if (preg_match('/^[a-z0-9_-]+$/', $group) !== 1 || !is_array($patterns)) {
                continue;
            }
            $out[$group] = array_values(array_map('strval', $patterns));
        }

        return $out;
    }
}
