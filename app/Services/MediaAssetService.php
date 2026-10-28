<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Services\Gateway\GatewayRequest;
use TokenHub\Services\Media\MediaStorage;
use TokenHub\Services\Media\MediaStorageManager;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class MediaAssetService
{
    public function __construct(
        private readonly ?MediaStorage $storage = null,
        private readonly ?SafeRemoteFetcher $fetcher = null,
    ) {
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    public function registerResponseAssets(array $task, GatewayRequest $request, array $response): array
    {
        $created = [];
        foreach ($this->extractAssets($request, $response) as $asset) {
            $row = $this->create([
                ...$asset,
                'task_id' => $task['id'] ?? null,
                'owner_user_id' => $task['user_id'] ?? null,
                'department_id' => $task['department_id'] ?? null,
                'api_key_id' => $task['api_key_id'] ?? null,
            ]);
            // Position in the upstream "data" list, so the gateway can put
            // each asset's link back on the item it came from.
            if (isset($asset['data_index'])) {
                $row['data_index'] = $asset['data_index'];
            }
            $created[] = $row;
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
        $policy = new AssetAccessPolicy();

        // Token holders lose access as soon as the issuing key is revoked.
        return $policy->allows($asset, $payload) && $policy->issuerStillValid($payload);
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, mixed>|null $apiKey
     */
    public function canAccess(array $asset, ?array $apiKey): bool
    {
        return (new AssetAccessPolicy())->allows($asset, $apiKey);
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
        foreach (array_values(is_array($response['data'] ?? null) ? $response['data'] : []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['b64_json']) && is_string($item['b64_json'])) {
                $asset = $this->base64Asset($request, $item['b64_json']);
                if ($asset !== null) {
                    $assets[] = [...$asset, 'data_index' => $index];
                }
            } elseif (!empty($item['url']) && is_string($item['url'])) {
                $assets[] = [...$this->urlAsset($request, $item['url']), 'data_index' => $index];
            }
        }

        // Vendors that answer "images" instead of "data" (SiliconFlow) or put
        // generated images on the chat message (OpenRouter image output).
        if (!is_array($response['data'] ?? null)) {
            $extra = is_array($response['images'] ?? null) ? $response['images'] : (is_array($response['choices'][0]['message']['images'] ?? null) ? $response['choices'][0]['message']['images'] : []);
            foreach (array_values($extra) as $item) {
                $url = is_array($item) ? ($item['url'] ?? $item['image_url']['url'] ?? null) : null;
                if (is_array($item) && !empty($item['b64_json']) && is_string($item['b64_json'])) {
                    $asset = $this->base64Asset($request, $item['b64_json']);
                    if ($asset !== null) {
                        $assets[] = $asset;
                    }
                } elseif (is_string($url) && $url !== '') {
                    $assets[] = $this->urlAsset($request, $url);
                }
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
     * Upstream media URLs expire (10 minutes to 24 hours). With
     * MEDIA_PERSIST_REMOTE (default on) the file is downloaded right away
     * through the outbound policy and kept in media storage; when the copy
     * fails the URL is kept so the download endpoint can still try it.
     *
     * @return array<string, mixed>
     */
    private function urlAsset(GatewayRequest $request, string $url, ?string $mimeType = null): array
    {
        if (str_starts_with($url, 'data:')) {
            $inline = $this->dataUrlAsset($request, $url);
            if ($inline !== null) {
                return $inline;
            }
        }
        if (filter_var(env_value('MEDIA_PERSIST_REMOTE', true), FILTER_VALIDATE_BOOL) && preg_match('#^https?://#i', $url) === 1) {
            $fetched = ($this->fetcher ?? new SafeRemoteFetcher())->get($url, OutboundUrlPolicy::PURPOSE_REMOTE_ASSET);
            if (($fetched['ok'] ?? false) === true && (string) ($fetched['body'] ?? '') !== '') {
                $type = strtolower(trim(explode(';', (string) ($fetched['content_type'] ?? ''))[0]));
                $resolvedMime = $mimeType ?? (preg_match('#^(image|video|audio)/[a-z0-9.+-]+$#', $type) === 1 ? $type : $this->sniffMime((string) $fetched['body'], $request->capability));
                try {
                    $stored = $this->storage()->put((string) $fetched['body'], $this->extensionForMime($resolvedMime), $resolvedMime);

                    return [
                        'media_type' => $this->mediaTypeForMime($resolvedMime, $request->capability),
                        'storage_path' => $stored['storage_path'],
                        'mime_type' => $resolvedMime,
                        'size_bytes' => $stored['size_bytes'],
                    ];
                } catch (\Throwable $throwable) {
                    App::logger()->warning('media', 'media persist failed', ['error' => $throwable->getMessage()]);
                }
            } else {
                App::logger()->warning('media', 'remote media copy failed, keeping upstream url', ['error' => $fetched['error'] ?? null]);
            }
        }

        return [
            'media_type' => $this->mediaTypeForCapability($request->capability),
            'storage_path' => $url,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dataUrlAsset(GatewayRequest $request, string $url): ?array
    {
        if (preg_match('#^data:([a-z0-9.+/-]+)?(;[^,]*)?,(.*)$#is', $url, $m) !== 1 || !str_contains(strtolower((string) $m[2]), 'base64')) {
            return null;
        }

        return $this->base64Asset($request, $m[3], $m[1] !== '' ? strtolower($m[1]) : null);
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

        $mimeType ??= $this->sniffMime($binary, $request->capability);
        $stored = $this->storage()->put($binary, $this->extensionForMime($mimeType), $mimeType);

        return [
            'media_type' => $this->mediaTypeForMime($mimeType, $request->capability),
            'storage_path' => $stored['storage_path'],
            'mime_type' => $mimeType,
            'size_bytes' => $stored['size_bytes'],
        ];
    }

    /**
     * Bytes stored as an asset by an adapter that downloaded them itself
     * (authenticated content endpoints, hex/base64 audio, ...).
     *
     * @return array<string, mixed>
     */
    public function bytesAsset(GatewayRequest $request, string $bytes, ?string $mimeType = null): array
    {
        $mimeType ??= $this->sniffMime($bytes, $request->capability);
        $stored = $this->storage()->put($bytes, $this->extensionForMime($mimeType), $mimeType);

        return [
            'media_type' => $this->mediaTypeForMime($mimeType, $request->capability),
            'storage_path' => $stored['storage_path'],
            'mime_type' => $mimeType,
            'size_bytes' => $stored['size_bytes'],
        ];
    }

    private function storage(): MediaStorage
    {
        return $this->storage ?? MediaStorageManager::driver();
    }

    private function sniffMime(string $bytes, string $capability): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type = $finfo !== false ? finfo_buffer($finfo, substr($bytes, 0, 65536)) : false;
            if (is_string($type) && preg_match('#^(image|video|audio)/#', $type) === 1) {
                return $type === 'audio/x-wav' ? 'audio/wav' : $type;
            }
        }

        return $this->defaultMimeType($capability);
    }

    private function mediaTypeForMime(?string $mimeType, string $capability): string
    {
        $prefix = strtolower(explode('/', (string) $mimeType)[0]);

        return in_array($prefix, ['image', 'video', 'audio'], true) ? $prefix : $this->mediaTypeForCapability($capability);
    }

    private function mediaTypeForCapability(string $capability): string
    {
        return match ($capability) {
            'tts', 'asr', 'music_generation' => 'audio',
            'video_generation' => 'video',
            'image_generation', 'vision_understanding' => 'image',
            default => 'file',
        };
    }

    private function defaultMimeType(string $capability): string
    {
        return match ($capability) {
            'tts', 'asr', 'music_generation' => 'audio/mpeg',
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
            'image/gif' => '.gif',
            'video/webm' => '.webm',
            'video/quicktime' => '.mov',
            'audio/ogg', 'audio/opus' => '.ogg',
            'audio/flac', 'audio/x-flac' => '.flac',
            'audio/aac' => '.aac',
            'audio/pcm', 'audio/l16' => '.pcm',
            default => '.bin',
        };
    }
}
