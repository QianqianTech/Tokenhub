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
        'usage:read',
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

    /**
     * True when the key holds at least one management scope and its
     * delegation chain is active (any management key may read its own
     * department profile).
     */
    public function hasAnyScope(int $apiKeyId): bool
    {
        if ($apiKeyId <= 0 || !$this->delegationActive($apiKeyId)) {
            return false;
        }

        return $this->scopesFor($apiKeyId) !== [];
    }

    /**
     * Whether $actorApiKeyId may change $targetApiKeyId through the
     * management API: keys without management scopes, and keys the actor
     * delegated to (directly or transitively). A child key can never act on
     * its parent or on a sibling management key.
     */
    public function canManageKey(int $actorApiKeyId, int $targetApiKeyId): bool
    {
        if ($actorApiKeyId <= 0 || $targetApiKeyId <= 0) {
            return false;
        }
        if ($this->scopesFor($targetApiKeyId) === []) {
            return true;
        }
        $current = $targetApiKeyId;
        $seen = [];
        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;
            $row = App::db()->first('SELECT parent_api_key_id FROM api_key_management_delegations WHERE child_api_key_id = ?', [$current]);
            $current = (int) ($row['parent_api_key_id'] ?? 0);
            if ($current === $actorApiKeyId) {
                return true;
            }
        }

        return false;
    }

    /**
     * After a key's scopes were narrowed, drop from every delegated
     * descendant the scopes its parent no longer holds (write implies read).
     * Returns the number of descendant keys that changed.
     */
    public function pruneDescendants(int $apiKeyId): int
    {
        $changed = 0;
        $queue = [$apiKeyId];
        $seen = [];
        while ($queue !== []) {
            $parentId = array_shift($queue);
            if (isset($seen[$parentId])) {
                continue;
            }
            $seen[$parentId] = true;
            $held = $this->scopesFor($parentId);
            foreach ($held as $scope) {
                if (str_ends_with($scope, ':write')) {
                    $held[] = substr($scope, 0, -strlen(':write')) . ':read';
                }
            }
            foreach (App::db()->select('SELECT child_api_key_id FROM api_key_management_delegations WHERE parent_api_key_id = ?', [$parentId]) as $child) {
                $childId = (int) $child['child_api_key_id'];
                $current = $this->scopesFor($childId);
                $kept = array_values(array_intersect($current, $held));
                if ($kept !== $current) {
                    $this->replace($childId, $kept);
                    $changed++;
                }
                $queue[] = $childId;
            }
        }

        return $changed;
    }

    /**
     * Whether $actorApiKeyId may change user $userId (status, password,
     * name) through the management API. Disabling a user kills all of the
     * user's keys, so the user must not own a management key outside the
     * actor's delegated subtree (in particular not the actor's own key or an
     * ancestor of it).
     */
    public function canManageUser(int $actorApiKeyId, int $userId): bool
    {
        $owned = App::db()->select('SELECT DISTINCT k.id FROM api_keys k JOIN api_key_management_scopes s ON s.api_key_id = k.id WHERE k.user_id = ? AND k.status != \'deleted\'', [$userId]);
        foreach ($owned as $key) {
            if (!$this->canManageKey($actorApiKeyId, (int) $key['id']) || (int) $key['id'] === $actorApiKeyId) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scopes in $scopes that the key's delegation parent does not hold.
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    public function scopesBeyondParent(int $apiKeyId, array $scopes): array
    {
        $row = App::db()->first('SELECT parent_api_key_id FROM api_key_management_delegations WHERE child_api_key_id = ?', [$apiKeyId]);
        if ($row === null) {
            return [];
        }
        $parentId = (int) $row['parent_api_key_id'];

        return array_values(array_filter($scopes, fn (string $scope): bool => !$this->hasScope($parentId, $scope)));
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
            // Every key on the chain must still be able to authenticate: its
            // own status and expiry and its owner user's and department's status
            // (same rules as ApiKeyService::authenticate()).
            $row = App::db()->first('SELECT k.status, k.expires_at, u.status AS user_status, dep.status AS department_status, d.parent_api_key_id FROM api_keys k JOIN users u ON u.id = k.user_id JOIN departments dep ON dep.id = k.department_id LEFT JOIN api_key_management_delegations d ON d.child_api_key_id = k.id WHERE k.id = ?', [$current]);
            if ($row === null || ($row['status'] ?? '') !== 'active' || ($row['user_status'] ?? '') !== 'active' || ($row['department_status'] ?? '') !== 'active') return false;
            // Same expiry rule as ApiKeyService::authenticate().
            if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) return false;
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
