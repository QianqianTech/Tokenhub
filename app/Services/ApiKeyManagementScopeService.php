<?php

declare(strict_types=1);

namespace TokenHub\Services;

use InvalidArgumentException;
use TokenHub\Support\App;

final class ApiKeyManagementScopeService
{
    /** @var list<string> */
    private const SCOPES = [
        'users:read',
        'users:write',
        'api_keys:read',
        'api_keys:write',
        'rag:read',
        'rag:write',
    ];

    /**
     * @return list<string>
     */
    public function normalize(mixed $scopes): array
    {
        if (!is_array($scopes)) {
            throw new InvalidArgumentException('Management scopes must be an array.');
        }

        $requested = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !self::isValidScope($scope)) {
                throw new InvalidArgumentException('Invalid management scope.');
            }
            $requested[$scope] = true;
        }

        return array_values(array_filter(
            self::SCOPES,
            static fn (string $scope): bool => isset($requested[$scope])
        ));
    }

    public static function isValidScope(string $scope): bool
    {
        return in_array($scope, self::SCOPES, true);
    }

    /**
     * @return list<string>
     */
    public function scopesFor(int $apiKeyId): array
    {
        if ($apiKeyId <= 0) {
            return [];
        }

        $rows = App::db()->select(
            'SELECT scope FROM api_key_management_scopes WHERE api_key_id = ?',
            [$apiKeyId]
        );

        return $this->normalize(array_map(static fn (array $row): string => (string) $row['scope'], $rows));
    }

    /**
     * @param list<string> $scopes
     */
    public function replace(int $apiKeyId, array $scopes): void
    {
        if ($apiKeyId <= 0) {
            throw new InvalidArgumentException('API key ID must be positive.');
        }

        $scopes = $this->normalize($scopes);
        App::db()->transaction(function () use ($apiKeyId, $scopes): void {
            App::db()->execute('DELETE FROM api_key_management_scopes WHERE api_key_id = ?', [$apiKeyId]);
            foreach ($scopes as $scope) {
                App::db()->execute(
                    'INSERT INTO api_key_management_scopes (api_key_id, scope) VALUES (?, ?)',
                    [$apiKeyId, $scope]
                );
            }
        });
    }

    public function hasScope(int $apiKeyId, string $requiredScope): bool
    {
        if ($apiKeyId <= 0 || !self::isValidScope($requiredScope) || !$this->delegationActive($apiKeyId)) {
            return false;
        }

        $acceptedScopes = [$requiredScope];
        if (str_ends_with($requiredScope, ':read')) {
            $acceptedScopes[] = substr($requiredScope, 0, -strlen(':read')) . ':write';
        }

        $placeholders = implode(', ', array_fill(0, count($acceptedScopes), '?'));
        return App::db()->first(
            "SELECT id FROM api_key_management_scopes WHERE api_key_id = ? AND scope IN ({$placeholders}) LIMIT 1",
            [$apiKeyId, ...$acceptedScopes]
        ) !== null;
    }

    public function delegate(int $childApiKeyId, int $parentApiKeyId): void
    {
        if ($childApiKeyId <= 0 || $parentApiKeyId <= 0 || $childApiKeyId === $parentApiKeyId) {
            throw new InvalidArgumentException('Invalid management API key delegation.');
        }
        $sameDepartment = App::db()->first('SELECT child.id FROM api_keys child JOIN api_keys parent ON parent.id = ? WHERE child.id = ? AND child.department_id = parent.department_id', [$parentApiKeyId, $childApiKeyId]);
        if ($sameDepartment === null) {
            throw new InvalidArgumentException('Delegated API keys must belong to one department.');
        }

        foreach ($this->scopesFor($childApiKeyId) as $scope) {
            if (!$this->hasScope($parentApiKeyId, $scope)) {
                throw new InvalidArgumentException('Child management scopes must be held by the parent API key.');
            }
        }
        if ($this->wouldCreateCycle($childApiKeyId, $parentApiKeyId)) {
            throw new InvalidArgumentException('Management API key delegation cannot contain a cycle.');
        }

        App::db()->execute('INSERT INTO api_key_management_delegations (child_api_key_id, parent_api_key_id) VALUES (?, ?)', [$childApiKeyId, $parentApiKeyId]);
    }

    private function delegationActive(int $apiKeyId): bool
    {
        $current = $apiKeyId;
        $seen = [];
        while ($current > 0) {
            if (isset($seen[$current])) return false;
            $seen[$current] = true;
            $row = App::db()->first('SELECT k.status, d.parent_api_key_id FROM api_keys k LEFT JOIN api_key_management_delegations d ON d.child_api_key_id = k.id WHERE k.id = ?', [$current]);
            if ($row === null || ($row['status'] ?? '') !== 'active') return false;
            $current = (int) ($row['parent_api_key_id'] ?? 0);
        }
        return true;
    }

    private function wouldCreateCycle(int $childApiKeyId, int $parentApiKeyId): bool
    {
        $current = $parentApiKeyId;
        $seen = [];
        while ($current > 0) {
            if ($current === $childApiKeyId || isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $row = App::db()->first('SELECT parent_api_key_id FROM api_key_management_delegations WHERE child_api_key_id = ?', [$current]);
            $current = (int) ($row['parent_api_key_id'] ?? 0);
        }
        return false;
    }
}
