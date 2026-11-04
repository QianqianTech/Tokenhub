<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Support\App;
use TokenHub\Support\PrometheusWriter;
use TokenHub\Support\QueueRouter;
use TokenHub\Support\Request;
use TokenHub\Support\Response;
use Throwable;

/**
 * BIZ-024: Prometheus text exposition at GET /metrics.
 *
 * Disabled (404) unless METRICS_TOKEN is set; scrapers send
 * "Authorization: Bearer <METRICS_TOKEN>". The body is cached in Redis for
 * METRICS_CACHE_SECONDS (default 10) so several scrapers cost one set of
 * queries.
 *
 * Series
 *   tokenhub_up                                         1 while PHP answers
 *   tokenhub_dependency_up{dependency}                  mysql / redis
 *   tokenhub_worker_up{name}                            heartbeat present (queue-worker, scheduler, rag-worker)
 *   tokenhub_queue_length{queue}                        ready jobs per queue
 *   tokenhub_queue_processing{queue}                    reserved jobs
 *   tokenhub_queue_delayed{queue}                       jobs waiting for a retry back-off
 *   tokenhub_queue_dead_letter                          jobs in the dead-letter list
 *   tokenhub_queue_dead_letter_total                    counter: jobs dead-lettered since Redis start
 *   tokenhub_provider_requests_total{provider}          counter (daily aggregates)
 *   tokenhub_provider_failures_total{provider}          counter
 *   tokenhub_provider_latency_ms_bucket{provider,le}    histogram (cumulative)
 *   tokenhub_provider_latency_ms_sum / _count
 *   tokenhub_provider_error_ratio_5m{provider}          gauge, last 5 minutes
 */
final class MetricsController
{
    /** Upper bounds of the aggregate latency buckets, in ms (see UsageService::latencyBuckets). */
    private const BUCKETS = [
        100 => 'latency_bucket_100',
        300 => 'latency_bucket_300',
        1000 => 'latency_bucket_1000',
        3000 => 'latency_bucket_3000',
        10000 => 'latency_bucket_10000',
        30000 => 'latency_bucket_30000',
        120000 => 'latency_bucket_120000',
    ];

    public function metrics(Request $request): Response
    {
        $token = self::token();
        if ($token === '') {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }
        $header = (string) ($request->header('authorization', '') ?? '');
        $presented = preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1 ? trim($m[1]) : '';
        if ($presented === '' || !hash_equals($token, $presented)) {
            return Response::error('UNAUTHORIZED', 'Metrics token required', 401)->withHeader('WWW-Authenticate', 'Bearer');
        }

        return Response::raw($this->body(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function token(): string
    {
        return trim((string) env_value('METRICS_TOKEN', ''));
    }

    public function body(): string
    {
        $ttl = max(0, (int) env_value('METRICS_CACHE_SECONDS', 10));
        if ($ttl > 0) {
            try {
                $cached = App::redis()->connection()->get('metrics:prometheus');
                if (is_string($cached) && $cached !== '') {
                    return $cached;
                }
            } catch (Throwable) {
            }
        }
        $body = $this->render();
        if ($ttl > 0) {
            try {
                App::redis()->connection()->setex('metrics:prometheus', $ttl, $body);
            } catch (Throwable) {
            }
        }

        return $body;
    }

    public function render(): string
    {
        $out = new PrometheusWriter();
        $out->gauge('tokenhub_up', 'TokenHub PHP is serving requests.', [[[], 1]]);
        $out->gauge('tokenhub_dependency_up', 'Dependency reachable from PHP.', [
            [['dependency' => 'mysql'], App::db()->isHealthy() ? 1 : 0],
            [['dependency' => 'redis'], App::redis()->isHealthy() ? 1 : 0],
        ]);
        $this->queues($out);
        $this->providers($out);

        return $out->render();
    }

    private function queues(PrometheusWriter $out): void
    {
        try {
            $redis = App::redis();
            $workers = [];
            foreach (['queue-worker', 'scheduler', 'rag-worker'] as $name) {
                $workers[] = [['name' => $name], $redis->get('operations:worker:' . $name) !== null ? 1 : 0];
            }
            $out->gauge('tokenhub_worker_up', 'Background process heartbeat present.', $workers);

            $length = $processing = $delayed = [];
            foreach ((new QueueRouter(App::config()))->allQueues() as $queue) {
                $length[] = [['queue' => $queue], $redis->length($queue)];
                $processing[] = [['queue' => $queue], $redis->length(QueueRouter::processing($queue))];
                $delayed[] = [['queue' => $queue], $redis->delayedLength($queue)];
            }
            $out->gauge('tokenhub_queue_length', 'Jobs ready to run.', $length);
            $out->gauge('tokenhub_queue_processing', 'Jobs reserved by a worker.', $processing);
            $out->gauge('tokenhub_queue_delayed', 'Jobs waiting for their retry back-off.', $delayed);
            $failed = (string) App::config()->get('queue.failed_name', 'tokenhub:failed-jobs');
            $out->gauge('tokenhub_queue_dead_letter', 'Jobs in the dead-letter list.', [[[], $redis->length($failed)]]);
            $out->counter('tokenhub_queue_dead_letter_total', 'Jobs moved to the dead-letter list.', [[[], (int) ($redis->get('queue:dead-letter:total') ?? 0)]]);
        } catch (Throwable) {
            $out->gauge('tokenhub_queue_metrics_up', 'Queue metrics could be read.', [[[], 0]]);
        }
    }

    private function providers(PrometheusWriter $out): void
    {
        try {
            $bucketColumns = [];
            foreach (self::BUCKETS as $column) {
                if (\TokenHub\Support\SchemaInspector::hasColumn('usage_aggregates_day', $column)) {
                    $bucketColumns[] = $column;
                }
            }
            $select = implode(', ', array_map(static fn (string $column): string => "SUM({$column}) {$column}", $bucketColumns));
            $rows = App::db()->select(
                'SELECT a.provider_id, COALESCE(p.name, CONCAT("provider-", a.provider_id)) provider, SUM(a.request_count) requests, SUM(a.failed_count) failures, SUM(a.latency_ms_sum) latency_sum' . ($select !== '' ? ', ' . $select : '') . '
                 FROM usage_aggregates_day a LEFT JOIN providers p ON p.id = a.provider_id
                 WHERE a.provider_id IS NOT NULL AND a.provider_id > 0
                 GROUP BY a.provider_id, p.name'
            );
            $requests = $failures = $buckets = $sums = $counts = [];
            foreach ($rows as $row) {
                $labels = ['provider' => (string) $row['provider']];
                $requests[] = [$labels, (int) $row['requests']];
                $failures[] = [$labels, (int) $row['failures']];
                $cumulative = 0;
                foreach (self::BUCKETS as $le => $column) {
                    if (!in_array($column, $bucketColumns, true)) {
                        continue;
                    }
                    $cumulative += (int) ($row[$column] ?? 0);
                    $buckets[] = [[...$labels, 'le' => (string) $le], $cumulative];
                }
                $buckets[] = [[...$labels, 'le' => '+Inf'], (int) $row['requests']];
                $sums[] = [$labels, (int) $row['latency_sum']];
                $counts[] = [$labels, (int) $row['requests']];
            }
            $out->counter('tokenhub_provider_requests_total', 'Requests routed to the provider.', $requests);
            $out->counter('tokenhub_provider_failures_total', 'Requests to the provider that failed.', $failures);
            $out->histogram('tokenhub_provider_latency_ms', 'Request latency per provider in milliseconds.', $buckets, $sums, $counts);

            $recent = App::db()->select(
                'SELECT a.provider_id, COALESCE(p.name, CONCAT("provider-", a.provider_id)) provider, SUM(a.request_count) requests, SUM(a.failed_count) failures
                 FROM usage_aggregates_minute a LEFT JOIN providers p ON p.id = a.provider_id
                 WHERE a.bucket_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) AND a.provider_id IS NOT NULL AND a.provider_id > 0
                 GROUP BY a.provider_id, p.name'
            );
            $ratios = [];
            foreach ($recent as $row) {
                $ratios[] = [['provider' => (string) $row['provider']], (int) $row['requests'] > 0 ? round((int) $row['failures'] / (int) $row['requests'], 4) : 0];
            }
            $out->gauge('tokenhub_provider_error_ratio_5m', 'Failed / total requests per provider over the last 5 minutes.', $ratios);
        } catch (Throwable) {
            $out->gauge('tokenhub_usage_metrics_up', 'Usage metrics could be read.', [[[], 0]]);
        }
    }
}
