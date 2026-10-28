<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class MediaAssetService
{
    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    public function registerResponseAssets(array $task, GatewayRequest $request, array $response): array
    {
        $created = [];
        foreach ($this->extractAssets($request, $response) as $asset) {
            $created[] = $this->create([
                ...$asset,
                'task_id' => $task['id'] ?? null,
                'owner_user_id' => $task['user_id'] ?? null,
                'department_id' => $task['department_id'] ?? null,
                'api_key_id' => $task['api_key_id'] ?? null,
            ]);
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        $assetId = $attributes['asset_id'] ?? Crypto::token('asset_');
        App::db()->execute(
            'INSERT INTO media_assets (asset_id, task_id, owner_user_id, department_id, api_key_id, access_scope, media_type, storage_path, mime_type, size_bytes, duration_seconds, width, height, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $assetId,
                $attributes['task_id'] ?? null,
                $attributes['owner_user_id'] ?? null,
                $attributes['department_id'] ?? null,
                $attributes['api_key_id'] ?? null,
                $attributes['access_scope'] ?? 'department',
                $attributes['media_type'] ?? 'file',
                $attributes['storage_path'] ?? '',
                $attributes['mime_type'] ?? null,
                $attributes['size_bytes'] ?? null,
                $attributes['duration_seconds'] ?? null,
                $attributes['width'] ?? null,
                $attributes['height'] ?? null,
                $attributes['expires_at'] ?? gmdate('Y-m-d H:i:s', time() + 86400),
            ]
        );

        return $this->find((string) $assetId) ?? ['asset_id' => $assetId];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $assetId): ?array
    {
        return App::db()->first('SELECT * FROM media_assets WHERE asset_id = ?', [$assetId]);
    }

    /**
     * @param array<string, mixed>|null $apiKey
     * @return array<string, mixed>|null
     */
    public function findAuthorized(string $assetId, ?array $apiKey): ?array
    {
        $asset = $this->find($assetId);
        if (!$asset || !$this->canAccess($asset, $apiKey)) {
            return null;
        }

        return $asset;
    }

    /**
     * @param array<string, mixed>|null $screenSession
     * @return array<string, mixed>|null
     */
    public function findAuthorizedForScreen(string $assetId, ?array $screenSession): ?array
    {
        $asset = $this->find($assetId);
        if (!$asset || !$this->canAccessScreen($asset, $screenSession)) {
            return null;
        }

        return $asset;
    }

    /**
     * @param array<string, mixed> $asset
     * @return array{asset_id: string, download_url: string, expires_in: int}
     */
    public function createDownloadUrl(array $asset, int $ttlSeconds = 300): array
    {
        $apiKey = App::context()->apiKey() ?? [];
        $token = Crypto::token('dl_');
        App::redis()->set('download:' . $token, [
            'asset_id' => $asset['asset_id'],
            'id' => $apiKey['id'] ?? null,
            'api_key_id' => $apiKey['id'] ?? null,
            'user_id' => $apiKey['user_id'] ?? null,
            'department_id' => $apiKey['department_id'] ?? null,
        ], $ttlSeconds);

        (new AuditService())->write('asset.download_url', 'media_assets', $asset['asset_id']);

        return [
            'asset_id' => (string) $asset['asset_id'],
            'download_url' => rtrim((string) App::config()->get('app.url'), '/') . '/download/' . $token,
            'expires_in' => $ttlSeconds,
        ];
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, mixed> $screenSession
     * @return array{asset_id: string, download_url: string, expires_in: int}
     */
    public function createScreenDownloadUrl(array $asset, array $screenSession, int $ttlSeconds = 300): array
    {
        $token = Crypto::token('dl_');
        App::redis()->set('download:' . $token, [
            'asset_id' => $asset['asset_id'],
            'screen' => true,
            'scope_type' => $screenSession['scope_type'] ?? 'global',
            'scope_ids' => $screenSession['scope_ids'] ?? null,
        ], $ttlSeconds);

        (new AuditService())->write('asset.download_url', 'media_assets', $asset['asset_id'], ['actor' => 'screen']);

        return [
            'asset_id' => (string) $asset['asset_id'],
            'download_url' => rtrim((string) App::config()->get('app.url'), '/') . '/download/' . $token,
            'expires_in' => $ttlSeconds,
        ];
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, mixed> $payload
     */
    public function canAccessDownloadPayload(array $asset, array $payload): bool
    {
        if (($payload['screen'] ?? false) === true) {
            return $this->canAccessScreen($asset, $payload);
        }

        return $this->canAccess($asset, $payload);
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, mixed>|null $apiKey
     */
    public function canAccess(array $asset, ?array $apiKey): bool
    {
        if ($apiKey === null) {
            return false;
        }

        if (!empty($asset['expires_at']) && strtotime((string) $asset['expires_at']) < time()) {
            return false;
        }

        $apiKeyId = (int) ($apiKey['id'] ?? $apiKey['api_key_id'] ?? 0);
        if (($asset['api_key_id'] ?? null) !== null && (int) $asset['api_key_id'] === $apiKeyId) {
            return true;
        }

        if (($asset['owner_user_id'] ?? null) !== null && (int) $asset['owner_user_id'] === (int) ($apiKey['user_id'] ?? 0)) {
            return true;
        }

        if (($asset['department_id'] ?? null) !== null && (int) $asset['department_id'] === (int) ($apiKey['department_id'] ?? 0)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, mixed>|null $screenSession
     */
    public function canAccessScreen(array $asset, ?array $screenSession): bool
    {
        if ($screenSession === null) {
            return false;
        }

        if (!empty($asset['expires_at']) && strtotime((string) $asset['expires_at']) < time()) {
            return false;
        }

        $scopeType = (string) ($screenSession['scope_type'] ?? 'global');
        $scopeIds = $screenSession['scope_ids'] ?? [];
        if (is_string($scopeIds)) {
            $decoded = json_decode($scopeIds, true);
            $scopeIds = is_array($decoded) ? $decoded : [];
        }
        $scopeIds = array_map('intval', is_array($scopeIds) ? $scopeIds : []);

        return match ($scopeType) {
            'global' => true,
            'department' => in_array((int) ($asset['department_id'] ?? 0), $scopeIds, true),
            'user' => in_array((int) ($asset['owner_user_id'] ?? 0), $scopeIds, true),
            'api_key' => in_array((int) ($asset['api_key_id'] ?? 0), $scopeIds, true),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    public function publicAsset(array $asset): array
    {
        return array_filter([
            'asset_id' => $asset['asset_id'] ?? null,
            'media_type' => $asset['media_type'] ?? null,
            'mime_type' => $asset['mime_type'] ?? null,
            'size_bytes' => isset($asset['size_bytes']) ? (int) $asset['size_bytes'] : null,
            'duration_seconds' => isset($asset['duration_seconds']) ? (int) $asset['duration_seconds'] : null,
            'width' => isset($asset['width']) ? (int) $asset['width'] : null,
            'height' => isset($asset['height']) ? (int) $asset['height'] : null,
            'expires_at' => $asset['expires_at'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function extractAssets(GatewayRequest $request, array $response): array
    {
        $assets = [];
        foreach (($response['data'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['b64_json']) && is_string($item['b64_json'])) {
                $asset = $this->base64Asset($request, $item['b64_json']);
                if ($asset !== null) {
                    $assets[] = $asset;
                }
            } elseif (!empty($item['url']) && is_string($item['url'])) {
                $assets[] = $this->urlAsset($request, $item['url']);
            }
        }

        foreach (($response['assets'] ?? []) as $item) {
            if (is_array($item)) {
                $asset = $this->assetFromArray($request, $item);
                if ($asset !== null) {
                    $assets[] = $asset;
                }
            }
        }

        if (!empty($response['asset_url']) && is_string($response['asset_url'])) {
            $assets[] = $this->urlAsset($request, $response['asset_url']);
        }

        if (!empty($response['b64_json']) && is_string($response['b64_json'])) {
            $asset = $this->base64Asset($request, $response['b64_json']);
            if ($asset !== null) {
                $assets[] = $asset;
            }
        }

        return $assets;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assetFromArray(GatewayRequest $request, array $item): ?array
    {
        if (!empty($item['b64_json']) && is_string($item['b64_json'])) {
            $asset = $this->base64Asset($request, $item['b64_json'], $item['mime_type'] ?? null);
            if ($asset === null) {
                return null;
            }
        } else {
            $path = (string) ($item['url'] ?? $item['storage_path'] ?? '');
            if ($path === '') {
                return null;
            }
            $asset = $this->urlAsset($request, $path, $item['mime_type'] ?? null);
        }

        return [
            ...$asset,
            'size_bytes' => $item['size_bytes'] ?? $asset['size_bytes'] ?? null,
            'duration_seconds' => $item['duration_seconds'] ?? $item['duration'] ?? null,
            'width' => $item['width'] ?? null,
            'height' => $item['height'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function urlAsset(GatewayRequest $request, string $url, ?string $mimeType = null): array
    {
        return [
            'media_type' => $this->mediaTypeForCapability($request->capability),
            'storage_path' => $url,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function base64Asset(GatewayRequest $request, string $base64, ?string $mimeType = null): ?array
    {
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            return null;
        }

        $mimeType ??= $this->defaultMimeType($request->capability);
        $dir = storage_path('media');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . Crypto::token('media_') . $this->extensionForMime($mimeType);
        file_put_contents($path, $binary);

        return [
            'media_type' => $this->mediaTypeForCapability($request->capability),
            'storage_path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($binary),
        ];
    }

    private function mediaTypeForCapability(string $capability): string
    {
        return match ($capability) {
            'tts', 'asr' => 'audio',
            'video_generation' => 'video',
            'image_generation', 'vision_understanding' => 'image',
            default => 'file',
        };
    }

    private function defaultMimeType(string $capability): string
    {
        return match ($capability) {
            'tts', 'asr' => 'audio/mpeg',
            'video_generation' => 'video/mp4',
            'image_generation', 'vision_understanding' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    private function extensionForMime(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/wav', 'audio/x-wav' => '.wav',
            'audio/mpeg' => '.mp3',
            'video/mp4' => '.mp4',
            'image/jpeg' => '.jpg',
            'image/webp' => '.webp',
            'image/png' => '.png',
            default => '.bin',
        };
    }
}
