<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * One access matrix for multimodal tasks, media assets, uploaded files and
 * their download tokens.
 *
 * ASSET_SHARING_SCOPE selects how far an object is visible:
 *   api_key     only the API key that created it
 *   user        any active key of the same user (default)
 *   department  any key of the same department (previous behaviour)
 * Admin access goes through the admin plane (PermissionService), screen
 * shares through their own scope, never through this policy.
 *
 * Always enforced: soft-deleted or expired objects are invisible, and a
 * download token stops working as soon as its issuing key is disabled,
 * deleted or expired (checked at download time, not only at issue time).
 */
final class AssetAccessPolicy
{
    public function scope(): string
    {
        $scope = strtolower((string) env_value('ASSET_SHARING_SCOPE', 'user'));

        return in_array($scope, ['api_key', 'user', 'department'], true) ? $scope : 'user';
    }

    /**
     * @param array<string, mixed> $object task or asset row
     * @param array<string, mixed>|null $apiKey
     */
    public function allows(array $object, ?array $apiKey): bool
    {
        if ($apiKey === null || $apiKey === []) {
            return false;
        }
        if (!empty($object['deleted_at'])) {
            return false;
        }
        if (!empty($object['expires_at']) && strtotime((string) $object['expires_at']) < time()) {
            return false;
        }

        $keyId = (int) ($apiKey['id'] ?? $apiKey['api_key_id'] ?? 0);
        if (($object['api_key_id'] ?? null) !== null && $keyId > 0 && (int) $object['api_key_id'] === $keyId) {
            return true;
        }
        $scope = $this->scope();
        if ($scope === 'api_key') {
            return false;
        }

        $ownerUser = $object['owner_user_id'] ?? $object['user_id'] ?? null;
        if ($ownerUser !== null && (int) $ownerUser > 0 && (int) $ownerUser === (int) ($apiKey['user_id'] ?? 0)) {
            return true;
        }
        if ($scope !== 'department') {
            return false;
        }

        return ($object['department_id'] ?? null) !== null && (int) $object['department_id'] === (int) ($apiKey['department_id'] ?? 0);
    }

    /**
     * Re-validates the key that issued a download token.
     *
     * @param array<string, mixed> $payload download token payload
     */
    public function issuerStillValid(array $payload): bool
    {
        $keyId = (int) ($payload['api_key_id'] ?? $payload['id'] ?? 0);
        if ($keyId <= 0) {
            return false;
        }
        $row = App::db()->first(
            'SELECT k.status, k.expires_at, u.status AS user_status FROM api_keys k JOIN users u ON u.id = k.user_id WHERE k.id = ?',
            [$keyId]
        );

        return $row !== null
            && ($row['status'] ?? '') === 'active'
            && ($row['user_status'] ?? '') === 'active'
            && (empty($row['expires_at']) || strtotime((string) $row['expires_at']) >= time());
    }
}
