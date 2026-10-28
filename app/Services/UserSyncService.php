<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class UserSyncService
{
    private const MAX_BATCH_SIZE = 200;
    private const MAX_PAYLOAD_BYTES = 262144;

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function registerDevice(array $apiKey, array $data): array
    {
        $deviceId = $this->normalizeDeviceId((string) ($data['device_id'] ?? ''));
        $deviceName = $this->shortText($data['device_name'] ?? null, 160);
        $platform = $this->shortText($data['platform'] ?? null, 80);
        $clientVersion = $this->shortText($data['client_version'] ?? null, 80);

        App::db()->execute(
            'INSERT INTO user_sync_devices (device_id, user_id, department_id, api_key_id, device_name, platform, client_version, last_seen_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), "active")
             ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), platform = VALUES(platform), client_version = VALUES(client_version), last_seen_at = NOW(), updated_at = NOW()',
            [$deviceId, $apiKey['user_id'], $apiKey['department_id'], $apiKey['id'] ?? null, $deviceName, $platform, $clientVersion]
        );

        $row = App::db()->first('SELECT * FROM user_sync_devices WHERE device_id = ? AND user_id = ?', [$deviceId, $apiKey['user_id']]);
        $this->event($apiKey, $deviceId, 'device.register', null, 1, 'success');

        return $this->publicDevice($row ?? ['device_id' => $deviceId, 'user_id' => $apiKey['user_id'], 'department_id' => $apiKey['department_id'], 'status' => 'active']);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsert(array $apiKey, string $namespace, string $clientRecordId, array $data, bool $abortOnConflict = true): array
    {
        $namespace = $this->normalizeNamespace($namespace);
        $clientRecordId = $this->normalizeClientRecordId($clientRecordId);
        $this->assertValidRecordKey($namespace, $clientRecordId);
        $payload = $this->payloadFrom($data);
        $payloadHash = $this->payloadHash($payload);
        $deviceId = $this->optionalDeviceId($data['device_id'] ?? null);
        $this->assertActiveDevice($apiKey, $deviceId);
        $baseVersion = isset($data['base_sync_version']) ? max(0, (int) $data['base_sync_version']) : null;

        $existing = App::db()->first(
            'SELECT * FROM user_sync_records WHERE user_id = ? AND namespace = ? AND client_record_id = ?',
            [$apiKey['user_id'], $namespace, $clientRecordId]
        );
        if ($this->hasConflict($existing, $baseVersion, $payloadHash)) {
            $this->event($apiKey, $deviceId, 'record.upsert', $namespace, 1, 'conflict');
            if (!$abortOnConflict) {
                return [
                    'namespace' => $namespace,
                    'client_record_id' => $clientRecordId,
                    'sync_version' => (int) ($existing['sync_version'] ?? 0),
                    'conflict' => true,
                ];
            }
            abort_json('SYNC_CONFLICT', 'Record has been modified on server', 409);
        }

        if ($existing === null) {
            $recordId = Crypto::token('rec_');
            $nextVersion = $this->nextVersion((int) $apiKey['user_id']);
            $updated = App::db()->execute(
                'INSERT INTO user_sync_records (record_id, user_id, department_id, api_key_id, device_id, namespace, client_record_id, payload_json, payload_hash, sync_version, client_updated_at, deleted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)',
                [
                    $recordId,
                    $apiKey['user_id'],
                    $apiKey['department_id'],
                    $apiKey['id'] ?? null,
                    $deviceId,
                    $namespace,
                    $clientRecordId,
                    $this->encodePayload($payload),
                    $payloadHash,
                    $nextVersion,
                    $this->timestampOrNull($data['client_updated_at'] ?? null),
                ]
            );
        } else {
            $recordId = (string) $existing['record_id'];
            $nextVersion = $this->nextVersion((int) $apiKey['user_id']);
            App::db()->execute(
                'UPDATE user_sync_records
                 SET api_key_id = ?, device_id = ?, payload_json = ?, payload_hash = ?, sync_version = ?, client_updated_at = ?, deleted_at = NULL, updated_at = NOW()
                 WHERE id = ? AND sync_version = ?',
                [
                    $apiKey['id'] ?? null,
                    $deviceId,
                    $this->encodePayload($payload),
                    $payloadHash,
                    $nextVersion,
                    $this->timestampOrNull($data['client_updated_at'] ?? null),
                    $existing['id'],
                    $existing['sync_version'],
                ]
            );
            if ($updated <= 0) {
                abort_json('SYNC_CONFLICT', 'Record has been modified on server', 409);
            }
        }

        $row = App::db()->first('SELECT * FROM user_sync_records WHERE record_id = ?', [$recordId]);
        $this->touchDevice($apiKey, $deviceId);
        $this->event($apiKey, $deviceId, 'record.upsert', $namespace, 1, 'success');

        return $this->publicRecordSummary($row ?? []);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function batchUpsert(array $apiKey, array $data): array
    {
        $records = $data['records'] ?? null;
        if (!is_array($records)) {
            abort_json('SYNC_INVALID_PAYLOAD', 'records must be an array', 422);
        }
        if (count($records) > self::MAX_BATCH_SIZE) {
            abort_json('SYNC_PAYLOAD_TOO_LARGE', 'Too many records in one batch', 413);
        }

        $accepted = 0;
        $conflicted = 0;
        $results = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                abort_json('SYNC_INVALID_PAYLOAD', 'Each record must be an object', 422);
            }
            $namespace = (string) ($record['namespace'] ?? '');
            $clientRecordId = (string) ($record['client_record_id'] ?? '');
            try {
                $result = $this->upsert($apiKey, $namespace, $clientRecordId, ['device_id' => $data['device_id'] ?? null, ...$record], false);
                if (($result['conflict'] ?? false) === true) {
                    $conflicted++;
                    $results[] = [...$result, 'status' => 'conflict'];
                    continue;
                }
                $accepted++;
                $results[] = [...$result, 'status' => 'updated'];
            } catch (\Throwable $throwable) {
                $conflicted++;
                $results[] = ['namespace' => $namespace, 'client_record_id' => $clientRecordId, 'status' => 'conflict'];
            }
        }

        return [
            'accepted' => $accepted,
            'conflicted' => $conflicted,
            'records' => $results,
            'next_cursor' => 'ver_' . $this->latestVersion((int) $apiKey['user_id']),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(array $apiKey, array $query): array
    {
        $limit = min(500, max(1, (int) ($query['limit'] ?? 100)));
        $fromVersion = $this->versionFromCursor(isset($query['cursor']) ? (string) $query['cursor'] : null);
        $includeDeleted = !array_key_exists('include_deleted', $query) || filter_var($query['include_deleted'], FILTER_VALIDATE_BOOLEAN);
        $clauses = ['user_id = ?', 'sync_version > ?'];
        $bindings = [$apiKey['user_id'], $fromVersion];

        if (isset($query['namespace']) && (string) $query['namespace'] !== '') {
            $namespace = $this->normalizeNamespace((string) $query['namespace']);
            if (!$this->isValidNamespace($namespace)) {
                abort_json('SYNC_INVALID_PAYLOAD', 'Invalid namespace', 422);
            }
            $clauses[] = 'namespace = ?';
            $bindings[] = $namespace;
        }
        if (isset($query['updated_after']) && (string) $query['updated_after'] !== '') {
            $clauses[] = 'updated_at >= ?';
            $bindings[] = $this->timestampOrNull($query['updated_after']) ?? (string) $query['updated_after'];
        }
        if (!$includeDeleted) {
            $clauses[] = 'deleted_at IS NULL';
        }

        $rows = App::db()->select(
            'SELECT * FROM user_sync_records WHERE ' . implode(' AND ', $clauses) . ' ORDER BY sync_version ASC LIMIT ' . ($limit + 1),
            $bindings
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $nextVersion = $rows === [] ? $fromVersion : (int) end($rows)['sync_version'];
        $this->event($apiKey, null, 'records.pull', isset($query['namespace']) ? (string) $query['namespace'] : null, count($rows), 'success');

        return [
            'records' => array_map(fn (array $row): array => $this->publicRecord($row), $rows),
            'next_cursor' => 'ver_' . $nextVersion,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function manifest(array $apiKey): array
    {
        $latest = $this->latestVersion((int) $apiKey['user_id']);
        $rows = App::db()->select(
            'SELECT namespace, COUNT(*) AS record_count, MAX(sync_version) AS latest_sync_version, MAX(updated_at) AS updated_at
             FROM user_sync_records
             WHERE user_id = ? AND deleted_at IS NULL
             GROUP BY namespace
             ORDER BY namespace ASC',
            [$apiKey['user_id']]
        );

        return [
            'user_id' => (int) $apiKey['user_id'],
            'department_id' => (int) $apiKey['department_id'],
            'latest_sync_version' => $latest,
            'namespaces' => array_map(static fn (array $row): array => [
                'namespace' => (string) $row['namespace'],
                'record_count' => (int) $row['record_count'],
                'latest_sync_version' => (int) $row['latest_sync_version'],
                'updated_at' => $row['updated_at'],
            ], $rows),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function devices(array $apiKey): array
    {
        $rows = App::db()->select(
            'SELECT * FROM user_sync_devices WHERE user_id = ? ORDER BY last_seen_at DESC, id DESC',
            [$apiKey['user_id']]
        );

        return [
            'devices' => array_map(fn (array $row): array => $this->publicDevice($row), $rows),
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function disableDevice(array $apiKey, string $deviceId): array
    {
        $deviceId = $this->normalizeDeviceId($deviceId);
        $count = App::db()->execute(
            'UPDATE user_sync_devices SET status = "disabled", updated_at = NOW() WHERE device_id = ? AND user_id = ?',
            [$deviceId, $apiKey['user_id']]
        );
        if ($count <= 0) {
            abort_json('SYNC_DEVICE_NOT_FOUND', 'Device not found', 404);
        }
        $this->event($apiKey, $deviceId, 'device.disable', null, 1, 'success');

        return ['device_id' => $deviceId, 'disabled' => true, 'disabled_at' => gmdate('Y-m-d H:i:s')];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function namespaces(array $apiKey): array
    {
        $manifest = $this->manifest($apiKey);
        return [
            'latest_sync_version' => $manifest['latest_sync_version'],
            'namespaces' => $manifest['namespaces'],
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function record(array $apiKey, string $namespace, string $clientRecordId): array
    {
        $namespace = $this->normalizeNamespace($namespace);
        $clientRecordId = $this->normalizeClientRecordId($clientRecordId);
        $this->assertValidRecordKey($namespace, $clientRecordId);
        $row = App::db()->first(
            'SELECT * FROM user_sync_records WHERE user_id = ? AND namespace = ? AND client_record_id = ?',
            [$apiKey['user_id'], $namespace, $clientRecordId]
        );
        if ($row === null) {
            abort_json('SYNC_RECORD_NOT_FOUND', 'Record not found', 404);
        }

        return $this->publicRecord($row);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function resolveConflict(array $apiKey, string $namespace, string $clientRecordId, array $data): array
    {
        $strategy = (string) ($data['strategy'] ?? 'server_wins');
        if (!in_array($strategy, ['client_wins', 'server_wins'], true)) {
            abort_json('SYNC_INVALID_PAYLOAD', 'strategy must be client_wins or server_wins', 422);
        }
        if ($strategy === 'server_wins') {
            return ['strategy' => 'server_wins', 'record' => $this->record($apiKey, $namespace, $clientRecordId)];
        }

        $current = $this->record($apiKey, $namespace, $clientRecordId);
        $result = $this->upsert($apiKey, $namespace, $clientRecordId, [
            ...$data,
            'base_sync_version' => (int) $current['sync_version'],
        ]);
        return ['strategy' => 'client_wins', 'record' => $result];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function delete(array $apiKey, string $namespace, string $clientRecordId, array $data): array
    {
        $namespace = $this->normalizeNamespace($namespace);
        $clientRecordId = $this->normalizeClientRecordId($clientRecordId);
        $this->assertValidRecordKey($namespace, $clientRecordId);
        $deviceId = $this->optionalDeviceId($data['device_id'] ?? null);
        $this->assertActiveDevice($apiKey, $deviceId);
        $existing = App::db()->first(
            'SELECT * FROM user_sync_records WHERE user_id = ? AND namespace = ? AND client_record_id = ?',
            [$apiKey['user_id'], $namespace, $clientRecordId]
        );
        if ($existing === null) {
            abort_json('SYNC_RECORD_NOT_FOUND', 'Record not found', 404);
        }
        $baseVersion = isset($data['base_sync_version']) ? max(0, (int) $data['base_sync_version']) : null;
        if ($baseVersion === null || $baseVersion !== (int) $existing['sync_version']) {
            abort_json('SYNC_CONFLICT', 'Record has been modified on server', 409);
        }

        $nextVersion = $this->nextVersion((int) $apiKey['user_id']);
        $updated = App::db()->execute(
            'UPDATE user_sync_records SET api_key_id = ?, device_id = ?, sync_version = ?, deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND sync_version = ?',
            [$apiKey['id'] ?? null, $deviceId, $nextVersion, $existing['id'], $existing['sync_version']]
        );
        if ($updated <= 0) {
            abort_json('SYNC_CONFLICT', 'Record has been modified on server', 409);
        }
        $this->touchDevice($apiKey, $deviceId);
        $this->event($apiKey, $deviceId, 'record.delete', $namespace, 1, 'success');

        return [
            'namespace' => $namespace,
            'client_record_id' => $clientRecordId,
            'sync_version' => $nextVersion,
            'deleted' => true,
            'deleted_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed>|null $current
     */
    public function hasConflict(?array $current, ?int $baseSyncVersion, string $incomingPayloadHash): bool
    {
        if ($current === null || $baseSyncVersion === null) {
            return false;
        }

        return $baseSyncVersion < (int) ($current['sync_version'] ?? 0)
            && hash_equals((string) ($current['payload_hash'] ?? ''), $incomingPayloadHash) === false;
    }

    public function versionFromCursor(?string $cursor): int
    {
        if ($cursor === null || !preg_match('/^ver_(\d+)$/', $cursor, $matches)) {
            return 0;
        }

        return (int) $matches[1];
    }

    public function normalizeNamespace(string $namespace): string
    {
        return trim($namespace);
    }

    public function isValidNamespace(string $namespace): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}$/', $namespace) === 1
            && !str_contains($namespace, '..');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function payloadHash(array $payload): string
    {
        $normalized = $this->sortKeys($payload);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payloadFrom(array $data): array
    {
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            abort_json('SYNC_INVALID_PAYLOAD', 'payload must be an object', 422);
        }
        if (strlen($this->encodePayload($payload)) > self::MAX_PAYLOAD_BYTES) {
            abort_json('SYNC_PAYLOAD_TOO_LARGE', 'payload is too large', 413);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalizeClientRecordId(string $clientRecordId): string
    {
        return trim($clientRecordId);
    }

    private function assertValidRecordKey(string $namespace, string $clientRecordId): void
    {
        if (!$this->isValidNamespace($namespace) || $clientRecordId === '' || strlen($clientRecordId) > 190 || str_contains($clientRecordId, '..')) {
            abort_json('SYNC_INVALID_PAYLOAD', 'Invalid record key', 422);
        }
    }

    private function normalizeDeviceId(string $deviceId): string
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            return Crypto::token('dev_');
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $deviceId) !== 1 || str_contains($deviceId, '..')) {
            abort_json('SYNC_INVALID_PAYLOAD', 'Invalid device_id', 422);
        }

        return $deviceId;
    }

    private function optionalDeviceId(mixed $deviceId): ?string
    {
        if ($deviceId === null || trim((string) $deviceId) === '') {
            return null;
        }

        return $this->normalizeDeviceId((string) $deviceId);
    }

    private function timestampOrNull(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        $time = strtotime((string) $value);
        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }

    private function shortText(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : substr($value, 0, $max);
    }

    private function latestVersion(int $userId): int
    {
        $row = App::db()->first('SELECT MAX(sync_version) AS version FROM user_sync_records WHERE user_id = ?', [$userId]);
        return (int) ($row['version'] ?? 0);
    }

    private function nextVersion(int $userId): int
    {
        App::db()->execute(
            'INSERT INTO user_sync_sequences (user_id, latest_sync_version) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE latest_sync_version = LAST_INSERT_ID(latest_sync_version + 1)',
            [$userId]
        );
        return (int) App::db()->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $apiKey */
    private function assertActiveDevice(array $apiKey, ?string $deviceId): void
    {
        if ($deviceId === null) {
            return;
        }
        $device = App::db()->first('SELECT id FROM user_sync_devices WHERE device_id = ? AND user_id = ? AND status = "active"', [$deviceId, $apiKey['user_id']]);
        if ($device === null) {
            abort_json('SYNC_DEVICE_FORBIDDEN', 'Device is not registered or active', 403);
        }
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    private function touchDevice(array $apiKey, ?string $deviceId): void
    {
        if ($deviceId === null) {
            return;
        }
        App::db()->execute('UPDATE user_sync_devices SET last_seen_at = NOW(), updated_at = NOW() WHERE device_id = ? AND user_id = ?', [$deviceId, $apiKey['user_id']]);
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    private function event(array $apiKey, ?string $deviceId, string $action, ?string $namespace, int $recordCount, string $status): void
    {
        App::db()->execute(
            'INSERT INTO user_sync_events (request_id, user_id, department_id, api_key_id, device_id, action, namespace, record_count, status, summary)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                App::context()->requestId(),
                $apiKey['user_id'],
                $apiKey['department_id'],
                $apiKey['id'] ?? null,
                $deviceId,
                $action,
                $namespace,
                $recordCount,
                $status,
                json_encode(['action' => $action, 'record_count' => $recordCount], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
        (new ExternalEventOutboxService())->append((int) ($apiKey['id'] ?? 0), 'sync.' . $action, 'sync', App::context()->requestId(), $status, ['namespace' => $namespace, 'record_count' => $recordCount]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicDevice(array $row): array
    {
        return [
            'device_id' => (string) $row['device_id'],
            'user_id' => (int) $row['user_id'],
            'department_id' => (int) $row['department_id'],
            'status' => (string) ($row['status'] ?? 'active'),
            'last_seen_at' => $row['last_seen_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRecordSummary(array $row): array
    {
        return [
            'record_id' => (string) ($row['record_id'] ?? ''),
            'namespace' => (string) ($row['namespace'] ?? ''),
            'client_record_id' => (string) ($row['client_record_id'] ?? ''),
            'sync_version' => (int) ($row['sync_version'] ?? 0),
            'updated_at' => $row['updated_at'] ?? null,
            'conflict' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRecord(array $row): array
    {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        return [
            'record_id' => (string) $row['record_id'],
            'namespace' => (string) $row['namespace'],
            'client_record_id' => (string) $row['client_record_id'],
            'payload' => is_array($payload) ? $payload : [],
            'sync_version' => (int) $row['sync_version'],
            'client_updated_at' => $row['client_updated_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'deleted_at' => $row['deleted_at'] ?? null,
        ];
    }

    private function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $child) {
            $value[$key] = $this->sortKeys($child);
        }

        return $value;
    }
}
