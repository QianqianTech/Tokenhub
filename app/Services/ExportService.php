<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ExportService
{
    private const EXPORT_FORMAT = 'csv';

    public function create(string $type, array $filters): array
    {
        if (!$this->exportTypeAllowed($type)) {
            abort_json('ADMIN_PERMISSION_DENIED', 'Export type not allowed', 403);
        }
        $actor = App::context()->adminUser();
        if (!$actor) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }

        $filters = $this->sanitizeFilters($filters, $type);
        $policy = (new ContentRetentionService())->policy();
        if (($filters['include_raw'] ?? false) && !(new ContentArchiveService())->canViewRaw($actor, $policy)) {
            (new AuditService())->write('export.raw_denied', 'export_tasks', null, ['type' => $type]);
            abort_json('ADMIN_PERMISSION_DENIED', 'Raw export permission denied', 403);
        }
        if ($filters['include_raw'] ?? false) {
            (new AuditService())->write('export.raw_create', 'export_tasks', null, ['type' => $type]);
        }

        $filters['_scope'] = $this->actorScope($actor);
        $exportId = Crypto::token('exp_');
        App::db()->execute(
            'INSERT INTO export_tasks (export_id, requested_by, export_type, status, format, filters, expires_at) VALUES (?, ?, ?, "queued", ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))',
            [$exportId, $actor['id'] ?? null, $type, self::EXPORT_FORMAT, json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );
        App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), ['type' => 'export', 'export_id' => $exportId]);
        (new AuditService())->write('export.create', 'export_tasks', $exportId, ['type' => $type, 'filters' => $filters]);
        return ['export_id' => $exportId, 'status' => 'queued'];
    }

    public function exportTypeAllowed(string $type): bool
    {
        return in_array($type, ['usage', 'session_archive', 'logs'], true);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function sanitizeFilters(array $filters, string $type = ''): array
    {
        $normalized = (new ContentArchiveService())->normalizeFilters($filters);
        if ($type !== 'logs') {
            return $normalized;
        }

        foreach (['log_type', 'request_id', 'trace_id', 'status', 'error_code', 'security_event'] as $key) {
            if (isset($filters[$key]) && is_scalar($filters[$key]) && trim((string) $filters[$key]) !== '') {
                $normalized[$key] = trim((string) $filters[$key]);
            }
        }
        foreach (['provider_id', 'model_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $normalized[$key] = (int) $filters[$key];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function sanitizeCsvRow(array $row): array
    {
        foreach (array_keys($row) as $key) {
            $normalized = strtolower(str_replace(['-', '_'], '', (string) $key));
            if (str_contains($normalized, 'authorization') || str_contains($normalized, 'cookie') || str_contains($normalized, 'keyhash') || str_contains($normalized, 'encryptedsecret')) {
                unset($row[$key]);
            }
        }

        return App::logger()->sanitize($row);
    }

    /**
     * @param array<string, mixed>|null $actor
     * @param array<string, mixed> $task
     */
    public function canDownloadTask(?array $actor, array $task): bool
    {
        if (!$actor) {
            return false;
        }
        if (($actor['role'] ?? '') === 'super_admin') {
            return true;
        }

        return (int) ($task['requested_by'] ?? 0) === (int) ($actor['id'] ?? 0);
    }

    public function exportFilePath(string $exportId): string
    {
        $dir = (string) App::config()->get('paths.exports', storage_path('exports'));
        return $dir . DIRECTORY_SEPARATOR . $exportId . '.csv.enc';
    }

    public function encryptCsvForStorage(string $csv): string
    {
        return Crypto::encrypt($csv);
    }

    public function decryptCsvFromStorage(string $encrypted): string
    {
        return Crypto::decrypt($encrypted);
    }

    /**
     * @param array<string, mixed> $actor
     * @return array{0: string, 1: list<mixed>}
     */
    public function logScopeSql(array $actor): array
    {
        if (($actor['role'] ?? '') === 'super_admin') {
            return ['', []];
        }

        if (($actor['role'] ?? '') === 'user') {
            return ['actor_user_id = ?', [(int) ($actor['id'] ?? 0)]];
        }

        $departmentIds = $actor['department_ids'] ?? [];
        $departmentIds = is_array($departmentIds) ? array_values(array_unique(array_map('intval', $departmentIds))) : [];
        if ($departmentIds === []) {
            return ['1 = 0', []];
        }

        $placeholders = implode(', ', array_fill(0, count($departmentIds), '?'));
        return ["actor_user_id IN (SELECT id FROM users WHERE department_id IN ({$placeholders}))", $departmentIds];
    }

    public function downloadUrl(string $exportId): array
    {
        $task = App::db()->first('SELECT export_id, requested_by, status, file_path, expires_at FROM export_tasks WHERE export_id = ?', [$exportId]);
        if (!$this->canDownloadTask(App::context()->adminUser(), $task ?? [])) {
            abort_json('CONTENT_FORBIDDEN', 'Export task is outside admin scope', 403);
        }
        if (!$task || $task['status'] !== 'succeeded' || empty($task['file_path']) || !is_file((string) $task['file_path'])) {
            abort_json('CONTENT_FORBIDDEN', 'Export file is not available', 404);
        }
        if (!empty($task['expires_at']) && strtotime((string) $task['expires_at']) < time()) {
            abort_json('CONTENT_FORBIDDEN', 'Export file expired', 410);
        }

        $token = Crypto::token('expdl_');
        App::redis()->set('download:' . $token, ['export_id' => $exportId, 'file_path' => $task['file_path']], 300);
        App::db()->execute('UPDATE export_tasks SET download_count = COALESCE(download_count, 0) + 1 WHERE export_id = ?', [$exportId]);
        (new AuditService())->write('export.download_url', 'export_tasks', $exportId);

        return ['export_id' => $exportId, 'download_url' => App::config()->get('app.url') . '/download/' . $token, 'expires_in' => 300];
    }

    public function process(string $exportId): void
    {
        $task = App::db()->first('SELECT * FROM export_tasks WHERE export_id = ?', [$exportId]);
        if (!$task || in_array($task['status'], ['succeeded', 'expired'], true)) {
            return;
        }

        App::db()->execute('UPDATE export_tasks SET status = "processing", error_code = NULL WHERE export_id = ?', [$exportId]);
        $dir = (string) App::config()->get('paths.exports', storage_path('exports'));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $this->exportFilePath($exportId);

        try {
            $rows = $this->rowsFor($task);
            $this->writeCsv($path, $rows);
            App::db()->execute('UPDATE export_tasks SET status = "succeeded", file_path = ?, finished_at = NOW() WHERE export_id = ?', [$path, $exportId]);
            App::logger()->info('export', 'export completed', ['export_id' => $exportId, 'export_type' => $task['export_type']]);
        } catch (\Throwable $throwable) {
            App::db()->execute('UPDATE export_tasks SET status = "failed", error_code = "EXPORT_WRITE_FAILED", finished_at = NOW() WHERE export_id = ?', [$exportId]);
            App::logger()->error('export', 'export failed', ['export_id' => $exportId, 'error' => $throwable->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $task
     * @return list<array<string, mixed>>
     */
    private function rowsFor(array $task): array
    {
        $filters = json_decode((string) ($task['filters'] ?? '{}'), true);
        $filters = is_array($filters) ? $filters : [];

        return match ((string) $task['export_type']) {
            'logs' => $this->logRows($filters),
            'session_archive' => $this->sessionArchiveRows($filters),
            default => $this->usageRows($filters),
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function usageRows(array $filters): array
    {
        [$where, $bindings] = $this->recordWhere($filters, 'r');
        $rows = App::db()->select(
            "SELECT r.request_id, r.trace_id, r.user_id, r.department_id, r.api_key_id, r.provider_id, r.model_id, r.model_name, r.capability, r.status, r.http_status, r.error_code, r.input_tokens, r.output_tokens, r.total_tokens, r.cost_amount, r.latency_ms, r.client_ip, r.created_at
             FROM usage_records r
             LEFT JOIN usage_contents c ON c.usage_record_id = r.id
             {$where}
             ORDER BY r.id DESC
             LIMIT 10000",
            $bindings
        );

        return array_map(fn (array $row): array => $this->sanitizeCsvRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function sessionArchiveRows(array $filters): array
    {
        [$where, $bindings] = $this->recordWhere($filters, 'r');
        $rows = App::db()->select(
            "SELECT c.session_id, r.request_id, r.created_at, r.user_id, r.department_id, r.api_key_id, r.provider_id, r.model_id, r.capability AS modality, r.status, r.input_tokens, r.output_tokens, r.total_tokens, r.cost_amount AS cost, c.summary AS prompt_summary, c.summary AS response_summary, c.redacted_input, c.redacted_output, c.encrypted_input, c.encrypted_output, c.retention_mode, c.cleaned_at, r.error_code
             FROM usage_records r
             LEFT JOIN usage_contents c ON c.usage_record_id = r.id
             {$where}
             ORDER BY r.id DESC
             LIMIT 10000",
            $bindings
        );

        $includeRaw = (bool) ($filters['include_raw'] ?? false);
        return array_map(function (array $row) use ($includeRaw): array {
            $row['prompt_content'] = $includeRaw && empty($row['cleaned_at']) ? Crypto::decrypt((string) ($row['encrypted_input'] ?? '')) : ($row['redacted_input'] ?? null);
            $row['response_content'] = $includeRaw && empty($row['cleaned_at']) ? Crypto::decrypt((string) ($row['encrypted_output'] ?? '')) : ($row['redacted_output'] ?? null);
            $row['content_redacted'] = $includeRaw ? '0' : '1';
            unset($row['redacted_input'], $row['redacted_output'], $row['encrypted_input'], $row['encrypted_output']);
            return $this->sanitizeCsvRow($row);
        }, $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function logRows(array $filters): array
    {
        if (isset($filters['log_type']) || isset($filters['request_id']) || isset($filters['trace_id']) || isset($filters['provider_id']) || isset($filters['model_id']) || isset($filters['error_code'])) {
            return array_map(fn (array $row): array => $this->sanitizeCsvRow($row), (new LogService())->query($filters));
        }

        $clauses = [];
        $bindings = [];
        $scope = $filters['_scope'] ?? [];
        if (is_array($scope)) {
            [$scopeSql, $scopeBindings] = $this->logScopeSql($scope);
            if ($scopeSql !== '') {
                $clauses[] = $scopeSql;
                $bindings = [...$bindings, ...$scopeBindings];
            }
        }
        if (isset($filters['from'])) {
            $clauses[] = 'created_at >= ?';
            $bindings[] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $clauses[] = 'created_at <= ?';
            $bindings[] = $filters['to'];
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        $rows = App::db()->select(
            "SELECT id, actor_user_id, actor_type, action, target_type, target_id, request_id, trace_id, client_ip, created_at FROM audit_logs {$where} ORDER BY id DESC LIMIT 10000",
            $bindings
        );

        return array_map(fn (array $row): array => $this->sanitizeCsvRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function recordWhere(array $filters, string $alias): array
    {
        $scope = $filters['_scope'] ?? [];
        $actor = is_array($scope) ? $scope : ['role' => 'user', 'id' => 0];
        return (new ContentArchiveService())->where($actor, $filters, $alias);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Unable to open export buffer');
        }
        if ($rows === []) {
            fwrite($out, "empty\n");
            rewind($out);
            file_put_contents($path, $this->encryptCsvForStorage(stream_get_contents($out) ?: ''));
            fclose($out);
            return;
        }

        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (mixed $value): string => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $row));
        }
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);
        if (file_put_contents($path, $this->encryptCsvForStorage($csv)) === false) {
            throw new \RuntimeException('Unable to write export file');
        }
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function actorScope(array $actor): array
    {
        if (($actor['role'] ?? '') === 'super_admin') {
            return ['role' => 'super_admin', 'id' => (int) ($actor['id'] ?? 0)];
        }

        if (($actor['role'] ?? '') === 'user') {
            return ['role' => 'user', 'id' => (int) ($actor['id'] ?? 0), 'department_id' => (int) ($actor['department_id'] ?? 0)];
        }

        return [
            'role' => 'admin',
            'id' => (int) ($actor['id'] ?? 0),
            'department_id' => (int) ($actor['department_id'] ?? 0),
            'department_ids' => $this->descendantDepartmentIds((int) ($actor['department_id'] ?? 0), json_decode($actor['managed_department_ids'] ?? '[]', true) ?: []),
        ];
    }

    /**
     * @param list<int> $extraManaged
     * @return list<int>
     */
    private function descendantDepartmentIds(int $ownDepartmentId, array $extraManaged): array
    {
        $roots = array_values(array_unique(array_filter(array_map('intval', [...$extraManaged, $ownDepartmentId]))));
        $ids = [];
        foreach ($roots as $root) {
            $department = App::db()->first('SELECT path FROM departments WHERE id = ?', [$root]);
            if (!$department) {
                continue;
            }
            $rows = App::db()->select('SELECT id FROM departments WHERE path LIKE ?', [$department['path'] . '%']);
            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
