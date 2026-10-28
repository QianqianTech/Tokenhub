<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\SchemaInspector;

/**
 * API keys managed by an agent through /v1/management/api-keys. Everything
 * is limited to the management key's own department. Write operations on a
 * key that itself holds management scopes are only allowed for keys the
 * actor delegated to, so a child key can never disable, rotate or re-grant
 * its parent or a sibling management key.
 */
final class AgentManagementApiKeyService
{
    private const PUBLIC_COLUMNS = 'id, user_id, department_id, name, key_prefix, key_suffix, status, allowed_ip_cidrs, expires_at, last_used_at, created_at';
    private const UPDATABLE = ['name', 'status', 'expires_at', 'allowed_ip_cidrs'];
    private const MAX_GRACE_MINUTES = 10080;

    public function list(array $apiKey, array $query): array
    {
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $departmentId = $this->departmentId($apiKey);
        $conditions = ['department_id = ?'];
        $bindings = [$departmentId];
        if (isset($query['user_id']) && (string) $query['user_id'] !== '') {
            $conditions[] = 'user_id = ?';
            $bindings[] = (int) $query['user_id'];
        }
        if (isset($query['status']) && (string) $query['status'] !== '') {
            if (!in_array((string) $query['status'], ['active', 'disabled', 'deleted'], true)) {
                abort_json('MANAGEMENT_INVALID_PAYLOAD', 'status must be active, disabled or deleted', 422);
            }
            $conditions[] = 'status = ?';
            $bindings[] = (string) $query['status'];
        }
        $where = implode(' AND ', $conditions);
        $rows = App::db()->select('SELECT ' . self::PUBLIC_COLUMNS . " FROM api_keys WHERE {$where} ORDER BY id DESC LIMIT ? OFFSET ?", [...$bindings, $limit, $offset]);
        $total = (int) (App::db()->first("SELECT COUNT(*) AS total FROM api_keys WHERE {$where}", $bindings)['total'] ?? 0);

        return ['data' => array_map(fn (array $row): array => $this->present($row), $rows), 'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total]];
    }

    public function show(int $id, array $apiKey): array
    {
        $row = $this->key($id, $apiKey);
        $scopes = new ApiKeyManagementScopeService();
        $models = App::db()->select('SELECT model_id FROM api_key_model_permissions WHERE api_key_id = ? ORDER BY model_id', [$id]);

        return [
            ...$this->present($row),
            'model_ids' => array_map(static fn (array $model): int => (int) $model['model_id'], $models),
            'management_scopes' => $scopes->scopesFor($id),
            // PATCH / models / delete: descendants and scopeless keys, never itself.
            'manageable' => (int) ($apiKey['id'] ?? 0) !== $id && $scopes->canManageKey((int) ($apiKey['id'] ?? 0), $id),
            // :rotate: itself and its delegated descendants only.
            'rotatable' => $this->canRotate($id, $apiKey),
            'disabled_by' => ($row['status'] ?? '') === 'disabled' ? ($this->disabledBy($id) ?? 'admin') : null,
        ];
    }

    public function create(array $actorKey, array $data): array
    {
        $departmentId = $this->departmentId($actorKey);
        $userId = (int) ($data['user_id'] ?? 0);
        $user = App::db()->first('SELECT id, role FROM users WHERE id = ? AND department_id = ?', [$userId, $departmentId]);
        if ($user === null || ($user['role'] ?? '') !== 'user') {
            abort_json('MANAGEMENT_RESOURCE_NOT_FOUND', 'Target user not found in API key department', 404);
        }
        $unknown = array_diff(array_keys($data), ['user_id', 'name', 'expires_at', 'allowed_ip_cidrs', 'model_ids', 'management_scopes']);
        if ($unknown !== []) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Unknown field(s): ' . implode(', ', $unknown), 422);
        }
        $requestedScopes = $data['management_scopes'] ?? [];
        if (!is_array($requestedScopes)) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'management_scopes must be an array', 422);
        }
        $scopeService = new ApiKeyManagementScopeService();
        try {
            $requestedScopes = $scopeService->normalize($requestedScopes);
        } catch (\InvalidArgumentException) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Invalid management scopes', 422);
        }
        foreach ($requestedScopes as $scope) {
            if (!$scopeService->hasScope((int) ($actorKey['id'] ?? 0), $scope)) {
                abort_json('MANAGEMENT_SCOPE_DELEGATION_DENIED', 'API key cannot delegate an unheld management scope', 403);
            }
        }
        $fields = $this->validatedFields($data, ['name', 'expires_at', 'allowed_ip_cidrs']);
        $modelIds = array_key_exists('model_ids', $data) ? $this->grantableModelIds($data['model_ids'], true, $actorKey) : [];
        $created = (new ApiKeyService())->create([
            'name' => $fields['name'] ?? 'API Key',
            'expires_at' => $fields['expires_at'] ?? null,
            'allowed_ip_cidrs' => $fields['allowed_ip_cidrs'] ?? [],
            'model_ids' => $modelIds,
            'user_id' => $userId,
            'department_id' => $departmentId,
            'management_scopes' => $requestedScopes,
        ], (int) ($actorKey['user_id'] ?? 0));
        if ($requestedScopes !== []) {
            $scopeService->delegate((int) $created['id'], (int) ($actorKey['id'] ?? 0));
        }
        return $created;
    }

    /**
     * PATCH /v1/management/api-keys/{id}: name, status (active|disabled),
     * expires_at (ISO 8601 or null) and allowed_ip_cidrs.
     */
    public function update(int $id, array $apiKey, array $data): array
    {
        $row = $this->key($id, $apiKey);
        $this->assertManageable($row, $apiKey);
        if ($data === []) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Nothing to update', 422);
        }
        $unknown = array_diff(array_keys($data), self::UPDATABLE);
        if ($unknown !== []) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Unknown field(s): ' . implode(', ', $unknown), 422);
        }
        if (($row['status'] ?? '') === 'deleted') {
            abort_json('MANAGEMENT_KEY_DELETED', 'Deleted API keys cannot be changed', 409);
        }
        if (self::expired($row)) {
            // Includes the old secret left behind by a rotation: reviving it
            // would defeat the rotation. Issue a new key instead.
            abort_json('MANAGEMENT_KEY_EXPIRED', 'Expired API keys cannot be changed; create or rotate a key instead', 409);
        }
        $fields = $this->validatedFields($data, self::UPDATABLE);
        if (($fields['status'] ?? null) === 'active' && ($row['status'] ?? '') === 'disabled' && $this->disabledBy($id) !== 'management') {
            // Only keys an agent switched off may be switched back on by an
            // agent. Keys disabled in the console (or before disabled_by was
            // recorded) stay off until an administrator re-enables them.
            abort_json('MANAGEMENT_KEY_DISABLED_BY_ADMIN', 'This API key was disabled by an administrator; only an administrator can re-enable it', 409);
        }
        (new ApiKeyService())->update($id, $fields, 'management');

        return $this->present($this->key($id, $apiKey));
    }

    /**
     * POST /v1/management/api-keys/{id}:rotate. The old key keeps working
     * for grace_minutes (default 60, max 7 days) so callers can switch over.
     */
    public function rotate(int $id, array $apiKey, array $data): array
    {
        $row = $this->key($id, $apiKey);
        if (!$this->canRotate($id, $apiKey)) {
            // Rotating someone else's ordinary key would hand the caller a
            // working secret carrying that key's RAG bindings and quotas.
            abort_json('MANAGEMENT_TARGET_FORBIDDEN', 'Management API keys can only rotate themselves and keys they delegated to', 403);
        }
        if (($row['status'] ?? '') !== 'active' || self::expired($row)) {
            abort_json('MANAGEMENT_KEY_NOT_ACTIVE', 'Only active API keys can be rotated', 409);
        }
        $unknown = array_diff(array_keys($data), ['grace_minutes']);
        if ($unknown !== []) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Unknown field(s): ' . implode(', ', $unknown), 422);
        }
        $grace = $data['grace_minutes'] ?? 60;
        if (!is_int($grace) || $grace < 0 || $grace > self::MAX_GRACE_MINUTES) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'grace_minutes must be an integer between 0 and ' . self::MAX_GRACE_MINUTES, 422);
        }

        return (new ApiKeyService())->rotate($id, $grace, (int) ($apiKey['user_id'] ?? 0));
    }

    /** GET /v1/management/api-keys/{id}/quota: same shape as GET /v1/quota. */
    public function quota(int $id, array $apiKey): array
    {
        $row = $this->key($id, $apiKey);

        return (new ExternalQuotaService())->current(['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'department_id' => (int) $row['department_id']]);
    }

    public function replaceModels(int $id, array $apiKey, array $modelIds): array
    {
        $row = $this->key($id, $apiKey);
        $this->assertManageable($row, $apiKey);

        return (new ApiKeyService())->replaceModels($id, $this->grantableModelIds($modelIds, false, $apiKey));
    }

    public function delete(int $id, array $apiKey): array
    {
        $row = $this->key($id, $apiKey);
        $this->assertManageable($row, $apiKey);
        return (new ApiKeyService())->softDelete($id);
    }

    /**
     * Active models that the calling key may itself use: an agent can never
     * hand out access to models it was not granted.
     *
     * @return list<int>
     */
    private function grantableModelIds(mixed $modelIds, bool $allowEmpty, array $actorKey): array
    {
        $modelIds = $this->activeModelIds($modelIds, $allowEmpty);
        $held = (new ApiKeyService())->routableModelIds(['id' => (int) ($actorKey['id'] ?? 0)]) ?? [];
        $beyond = array_values(array_diff($modelIds, $held));
        if ($beyond !== []) {
            abort_json('MANAGEMENT_MODEL_DELEGATION_DENIED', 'API key cannot grant models it is not allowed to use: ' . implode(', ', $beyond), 403);
        }

        return $modelIds;
    }

    /**
     * @return list<int>
     */
    private function activeModelIds(mixed $modelIds, bool $allowEmpty): array
    {
        if (!is_array($modelIds) || (!$allowEmpty && $modelIds === []) || array_filter($modelIds, static fn (mixed $id): bool => (!is_int($id) && !(is_string($id) && ctype_digit($id))) || ((int) $id) <= 0) !== []) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'model_ids must be ' . ($allowEmpty ? 'an array' : 'a non-empty array') . ' of positive integers', 422);
        }
        $modelIds = array_values(array_unique(array_map('intval', $modelIds)));
        if ($modelIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($modelIds), '?'));
        $activeModels = App::db()->select("SELECT id FROM models WHERE status = 'active' AND id IN ({$placeholders})", $modelIds);
        if (count($activeModels) !== count($modelIds)) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'model_ids must identify active models', 422);
        }

        return $modelIds;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private function validatedFields(array $data, array $allowed): array
    {
        $fields = [];
        if (in_array('name', $allowed, true) && array_key_exists('name', $data)) {
            $name = is_string($data['name']) ? trim($data['name']) : '';
            if ($name === '' || mb_strlen($name) > 160) {
                abort_json('MANAGEMENT_INVALID_PAYLOAD', 'name must be a non-empty string of at most 160 characters', 422);
            }
            $fields['name'] = $name;
        }
        if (in_array('status', $allowed, true) && array_key_exists('status', $data)) {
            if (!in_array($data['status'], ['active', 'disabled'], true)) {
                abort_json('MANAGEMENT_INVALID_PAYLOAD', 'status must be active or disabled', 422);
            }
            $fields['status'] = $data['status'];
        }
        if (in_array('expires_at', $allowed, true) && array_key_exists('expires_at', $data)) {
            if ($data['expires_at'] === null) {
                $fields['expires_at'] = null;
            } else {
                $time = is_string($data['expires_at']) && trim($data['expires_at']) !== '' ? strtotime($data['expires_at']) : false;
                if ($time === false) {
                    abort_json('MANAGEMENT_INVALID_PAYLOAD', 'expires_at must be an ISO 8601 timestamp or null', 422);
                }
                if ($time <= time()) {
                    abort_json('MANAGEMENT_INVALID_PAYLOAD', 'expires_at must be in the future; disable the key instead', 422);
                }
                $fields['expires_at'] = gmdate('Y-m-d H:i:s', $time);
            }
        }
        if (in_array('allowed_ip_cidrs', $allowed, true) && array_key_exists('allowed_ip_cidrs', $data)) {
            $cidrs = $data['allowed_ip_cidrs'];
            if (!is_array($cidrs) || !array_is_list($cidrs) || count($cidrs) > 100) {
                abort_json('MANAGEMENT_INVALID_PAYLOAD', 'allowed_ip_cidrs must be an array of at most 100 IPs or CIDR ranges', 422);
            }
            foreach ($cidrs as $cidr) {
                if (!is_string($cidr) || !self::validCidr($cidr)) {
                    abort_json('MANAGEMENT_INVALID_PAYLOAD', 'allowed_ip_cidrs contains an invalid IPv4 address or CIDR range', 422);
                }
            }
            $fields['allowed_ip_cidrs'] = array_values(array_unique(array_map('trim', $cidrs)));
        }

        return $fields;
    }

    /**
     * IPv4 address or CIDR range. IPv6 ranges are rejected because the
     * allowlist matcher (ApiKeyService::cidrMatch) only handles IPv4; a stored
     * IPv6 range would silently never match.
     */
    public static function validCidr(string $value): bool
    {
        $value = trim($value);
        [$ip, $bits] = str_contains($value, '/') ? explode('/', $value, 2) : [$value, null];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return $bits === null || (ctype_digit($bits) && (int) $bits <= 32);
    }

    /**
     * 'admin', 'management' or null (not recorded: column missing or key
     * disabled before it existed; callers treat that as 'admin').
     */
    private function disabledBy(int $id): ?string
    {
        if (!SchemaInspector::hasColumn('api_keys', 'disabled_by')) {
            return null;
        }
        $value = App::db()->first('SELECT disabled_by FROM api_keys WHERE id = ?', [$id])['disabled_by'] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function canRotate(int $targetId, array $actorKey): bool
    {
        $actorId = (int) ($actorKey['id'] ?? 0);
        if ($actorId === $targetId) {
            return true;
        }
        $scopes = new ApiKeyManagementScopeService();

        return $scopes->scopesFor($targetId) !== [] && $scopes->canManageKey($actorId, $targetId);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function expired(array $row): bool
    {
        return !empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time();
    }

    private function assertManageable(array $target, array $actorKey): void
    {
        $actorId = (int) ($actorKey['id'] ?? 0);
        $targetId = (int) ($target['id'] ?? 0);
        if ($actorId === $targetId || !(new ApiKeyManagementScopeService())->canManageKey($actorId, $targetId)) {
            abort_json('MANAGEMENT_TARGET_FORBIDDEN', 'Management API keys can only change keys they delegated to, or keys without management scopes', 403);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        if (array_key_exists('allowed_ip_cidrs', $row)) {
            $decoded = is_string($row['allowed_ip_cidrs']) ? json_decode($row['allowed_ip_cidrs'], true) : $row['allowed_ip_cidrs'];
            $row['allowed_ip_cidrs'] = is_array($decoded) ? array_values($decoded) : [];
        }
        foreach (['id', 'user_id', 'department_id'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (int) $row[$field];
            }
        }

        return $row;
    }

    private function key(int $id, array $apiKey): array
    {
        $row = App::db()->first('SELECT ' . self::PUBLIC_COLUMNS . ' FROM api_keys WHERE id = ? AND department_id = ?', [$id, $this->departmentId($apiKey)]);
        if ($row === null) {
            abort_json('MANAGEMENT_RESOURCE_NOT_FOUND', 'API key not found in API key department', 404);
        }
        return $row;
    }

    private function departmentId(array $apiKey): int
    {
        $departmentId = (int) ($apiKey['department_id'] ?? 0);
        if ($departmentId <= 0) {
            abort_json('MANAGEMENT_FORBIDDEN', 'API key has no management department', 403);
        }
        return $departmentId;
    }
}
