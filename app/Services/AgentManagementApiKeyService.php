<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class AgentManagementApiKeyService
{
    public function list(array $apiKey, array $query): array
    {
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $departmentId = $this->departmentId($apiKey);
        $rows = App::db()->select('SELECT id, user_id, department_id, name, key_prefix, key_suffix, status, expires_at, last_used_at, created_at FROM api_keys WHERE department_id = ? ORDER BY id DESC LIMIT ? OFFSET ?', [$departmentId, $limit, $offset]);
        $total = (int) (App::db()->first('SELECT COUNT(*) AS total FROM api_keys WHERE department_id = ?', [$departmentId])['total'] ?? 0);
        return ['data' => $rows, 'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total]];
    }

    public function create(array $actorKey, array $data): array
    {
        $departmentId = $this->departmentId($actorKey);
        $userId = (int) ($data['user_id'] ?? 0);
        $user = App::db()->first('SELECT id, role FROM users WHERE id = ? AND department_id = ?', [$userId, $departmentId]);
        if ($user === null || ($user['role'] ?? '') !== 'user') {
            abort_json('MANAGEMENT_RESOURCE_NOT_FOUND', 'Target user not found in API key department', 404);
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
        $created = (new ApiKeyService())->create([
            ...$data,
            'user_id' => $userId,
            'department_id' => $departmentId,
            'management_scopes' => $requestedScopes,
        ], (int) ($actorKey['user_id'] ?? 0));
        if ($requestedScopes !== []) {
            $scopeService->delegate((int) $created['id'], (int) ($actorKey['id'] ?? 0));
        }
        return $created;
    }

    public function replaceModels(int $id, array $apiKey, array $modelIds): array
    {
        $this->key($id, $apiKey);
        $modelIds = array_values(array_unique(array_map('intval', $modelIds)));
        if ($modelIds === [] || array_filter($modelIds, static fn (int $modelId): bool => $modelId <= 0) !== []) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'model_ids must be a non-empty array of positive integers', 422);
        }

        $placeholders = implode(', ', array_fill(0, count($modelIds), '?'));
        $activeModels = App::db()->select("SELECT id FROM models WHERE status = \"active\" AND id IN ({$placeholders})", $modelIds);
        if (count($activeModels) !== count($modelIds)) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'model_ids must identify active models', 422);
        }

        return (new ApiKeyService())->replaceModels($id, $modelIds);
    }

    public function delete(int $id, array $apiKey): array
    {
        $this->key($id, $apiKey);
        return (new ApiKeyService())->softDelete($id);
    }

    private function key(int $id, array $apiKey): array
    {
        $row = App::db()->first('SELECT id FROM api_keys WHERE id = ? AND department_id = ?', [$id, $this->departmentId($apiKey)]);
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
