<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\SchemaInspector;

/**
 * BIZ-009: retention for high-volume tables.
 *
 * Every purge deletes in batches ("DELETE ... WHERE <time> < cutoff LIMIT n")
 * so a run never holds long row locks or builds a huge undo log, and stops
 * after retention.max_batches per table (the next hourly run continues).
 * Days <= 0 disable a purge. usage_records and audit_logs are the billing and
 * compliance source of truth: archive() first writes each batch to a gzip
 * JSONL file and only then deletes it, and is disabled by default.
 */
final class DataRetentionService
{
    /**
     * table => [config key, time column, extra WHERE (rows that must stay are excluded)]
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const PURGES = [
        'usage_aggregates_minute' => ['usage_aggregates_minute_days', 'bucket_at', ''],
        // Notifications first: they point at alert events.
        'alert_notifications' => ['alert_notifications_days', 'created_at', "status NOT IN ('pending','retrying')"],
        'alert_events' => ['alert_events_days', 'created_at', "status <> 'open'"],
        'external_webhook_deliveries' => ['webhook_deliveries_days', 'created_at', "status NOT IN ('pending')"],
        'dashboard_share_access_logs' => ['dashboard_share_access_logs_days', 'created_at', ''],
        'rag_retrieval_logs' => ['rag_retrieval_logs_days', 'created_at', ''],
    ];

    public function __construct(private readonly ?Database $db = null, private readonly ?array $settings = null)
    {
    }

    /**
     * @return array<string, int> deleted rows per table (-1 = table missing)
     */
    public function purge(?int $now = null): array
    {
        $now ??= time();
        $result = [];
        foreach (self::PURGES as $table => [$key, $column, $keep]) {
            $days = (int) $this->setting($key, 0);
            if ($days <= 0) {
                continue;
            }
            if (!$this->tableHasColumn($table, $column)) {
                $result[$table] = -1;
                continue;
            }
            $where = $column . ' < ?' . ($keep !== '' && $this->keepColumnExists($table) ? ' AND ' . $keep : '');
            $result[$table] = $this->deleteInBatches($table, $where, [gmdate('Y-m-d H:i:s', $now - $days * 86400)]);
        }
        App::logger()->info('worker', 'retention purge completed', ['deleted' => $result]);

        return $result;
    }

    /**
     * Archive-then-delete for usage_records / audit_logs (off unless
     * retention.archive_enabled). Rows are written to
     * <archive_path>/<table>-<Ymd>.jsonl.gz before they are deleted.
     *
     * @return array<string, int>
     */
    public function archive(?int $now = null): array
    {
        if (!$this->enabled($this->setting('archive_enabled', false))) {
            return [];
        }
        $now ??= time();
        $result = [];
        foreach (['usage_records' => 'usage_records_days', 'audit_logs' => 'audit_logs_days'] as $table => $key) {
            $days = (int) $this->setting($key, 0);
            if ($days <= 0 || !$this->tableHasColumn($table, 'created_at')) {
                continue;
            }
            $result[$table] = $this->archiveTable($table, gmdate('Y-m-d H:i:s', $now - $days * 86400), $now);
        }
        App::logger()->info('worker', 'retention archive completed', ['archived' => $result]);

        return $result;
    }

    /**
     * @param list<mixed> $params
     */
    public function deleteInBatches(string $table, string $where, array $params): int
    {
        $batch = max(100, (int) $this->setting('batch_size', 5000));
        $maxBatches = max(1, (int) $this->setting('max_batches', 200));
        $total = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $deleted = $this->db()->execute('DELETE FROM ' . $table . ' WHERE ' . $where . ' LIMIT ' . $batch, $params);
            $total += $deleted;
            if ($deleted < $batch) {
                break;
            }
        }

        return $total;
    }

    private function archiveTable(string $table, string $cutoff, int $now): int
    {
        $batch = max(100, min(5000, (int) $this->setting('batch_size', 5000)));
        $maxBatches = max(1, (int) $this->setting('max_batches', 200));
        $dir = rtrim((string) $this->setting('archive_path', storage_path('archives')), DIRECTORY_SEPARATOR);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Retention archive directory is not writable');
        }
        $file = $dir . DIRECTORY_SEPARATOR . $table . '-' . gmdate('Ymd', $now) . '.jsonl.gz';
        $total = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $rows = $this->db()->select('SELECT * FROM ' . $table . ' WHERE created_at < ? ORDER BY id LIMIT ' . $batch, [$cutoff]);
            if ($rows === []) {
                break;
            }
            $lines = '';
            foreach ($rows as $row) {
                $lines .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            }
            // Each batch is its own gzip member: concatenated members are a
            // valid gzip stream, so a crash never corrupts earlier batches.
            $gz = gzencode($lines, 6);
            if ($gz === false || file_put_contents($file, $gz, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Retention archive write failed; nothing was deleted');
            }
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $total += $this->db()->execute('DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')', $ids);
            if (count($rows) < $batch) {
                break;
            }
        }

        return $total;
    }

    private function keepColumnExists(string $table): bool
    {
        return in_array($table, ['alert_notifications', 'alert_events', 'external_webhook_deliveries'], true)
            ? $this->tableHasColumn($table, 'status')
            : true;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        if ($this->db !== null) {
            return true; // injected test databases define the tables they use
        }
        try {
            return SchemaInspector::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function setting(string $key, mixed $default): mixed
    {
        if ($this->settings !== null) {
            return $this->settings[$key] ?? $default;
        }

        return App::config()->get('retention.' . $key, $default);
    }

    private function enabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function db(): Database
    {
        return $this->db ?? App::db();
    }
}
