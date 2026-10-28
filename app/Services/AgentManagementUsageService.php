<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * GET /v1/management/usage/summary and /trends: usage of the management
 * key's department, optionally narrowed to one user or one API key of that
 * department. Same query parameters and response shape as /v1/usage/*.
 */
final class AgentManagementUsageService
{
    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function summary(array $apiKey, array $query): array
    {
        [$subject, $scope] = $this->subject($apiKey, $query);

        return (new ExternalUsageService())->summaryFor($subject, $scope, $query);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function trends(array $apiKey, array $query): array
    {
        [$subject, $scope] = $this->subject($apiKey, $query);

        return (new ExternalUsageService())->trendsFor($subject, $scope, $query);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array{0: array<string, int>, 1: array<string, int|null>}
     */
    private function subject(array $apiKey, array $query): array
    {
        $departmentId = (int) ($apiKey['department_id'] ?? 0);
        if ($departmentId <= 0) {
            abort_json('MANAGEMENT_FORBIDDEN', 'API key has no management department', 403);
        }
        foreach (['from', 'to'] as $field) {
            if (isset($query[$field]) && (string) $query[$field] !== '' && strtotime((string) $query[$field]) === false) {
                abort_json('MANAGEMENT_INVALID_PAYLOAD', $field . ' must be a date or ISO 8601 timestamp', 422);
            }
        }
        $subject = ['department_id' => $departmentId];
        $scope = ['department_id' => $departmentId, 'user_id' => null, 'api_key_id' => null];
        $userId = $this->optionalId($query, 'user_id');
        if ($userId !== null) {
            if (App::db()->first('SELECT id FROM users WHERE id = ? AND department_id = ?', [$userId, $departmentId]) === null) {
                abort_json('MANAGEMENT_RESOURCE_NOT_FOUND', 'User not found in API key department', 404);
            }
            $subject['user_id'] = $scope['user_id'] = $userId;
        }
        $apiKeyId = $this->optionalId($query, 'api_key_id');
        if ($apiKeyId !== null) {
            if (App::db()->first('SELECT id FROM api_keys WHERE id = ? AND department_id = ?', [$apiKeyId, $departmentId]) === null) {
                abort_json('MANAGEMENT_RESOURCE_NOT_FOUND', 'API key not found in API key department', 404);
            }
            $subject['api_key_id'] = $scope['api_key_id'] = $apiKeyId;
        }

        return [$subject, $scope];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function optionalId(array $query, string $field): ?int
    {
        if (!isset($query[$field]) || (string) $query[$field] === '') {
            return null;
        }
        $value = (string) $query[$field];
        if (!ctype_digit($value) || (int) $value <= 0) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', $field . ' must be a positive integer', 422);
        }

        return (int) $value;
    }
}
