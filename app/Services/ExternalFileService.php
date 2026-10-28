<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ExternalFileService
{
    private const MAX_BYTES = 10485760;

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upload(array $apiKey, array $data): array
    {
        $fileName = $this->safeName((string) ($data['file_name'] ?? $data['name'] ?? 'file.bin'));
        $mimeType = $this->safeMime((string) ($data['mime_type'] ?? 'application/octet-stream'));
        $base64 = (string) ($data['b64_data'] ?? $data['base64'] ?? '');
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            abort_json('FILE_INVALID_BASE64', 'b64_data must be valid base64', 422);
        }
        if (strlen($binary) > self::MAX_BYTES) {
            abort_json('FILE_TOO_LARGE', 'File exceeds 10 MB limit', 413);
        }

        $dir = storage_path('media' . DIRECTORY_SEPARATOR . 'external');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . Crypto::token('file_') . '_' . $fileName;
        file_put_contents($path, $binary);

        $asset = (new MediaAssetService())->create([
            'owner_user_id' => $apiKey['user_id'] ?? null,
            'department_id' => $apiKey['department_id'] ?? null,
            'api_key_id' => $apiKey['id'] ?? null,
            'access_scope' => 'api_key',
            'media_type' => 'file',
            'storage_path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($binary),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 315360000),
        ]);

        return [
            'file_id' => (string) $asset['asset_id'],
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($binary),
            'created_at' => $asset['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function downloadUrl(array $apiKey, string $fileId): array
    {
        $asset = $this->file($apiKey, $fileId);
        return (new MediaAssetService())->createDownloadUrl($asset);
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(array $apiKey, array $query = []): array
    {
        $limit = max(1, min(100, (int) ($query['limit'] ?? 50)));
        $mediaType = trim((string) ($query['type'] ?? $query['media_type'] ?? ''));
        // Same visibility matrix as tasks/assets (AssetAccessPolicy).
        [$ownerClause, $ownerParam] = match ((new AssetAccessPolicy())->scope()) {
            'api_key' => ['api_key_id = ?', $apiKey['id'] ?? 0],
            'department' => ['(api_key_id = ? OR department_id = ?)', null],
            default => ['(api_key_id = ? OR owner_user_id = ?)', null],
        };
        $clauses = [$ownerClause, 'deleted_at IS NULL'];
        $params = $ownerParam !== null
            ? [$ownerParam]
            : [$apiKey['id'] ?? 0, (new AssetAccessPolicy())->scope() === 'department' ? ($apiKey['department_id'] ?? 0) : ($apiKey['user_id'] ?? 0)];
        $cursorId = $this->cursorId(isset($query['cursor']) ? (string) $query['cursor'] : null);
        if ($cursorId !== null) {
            $clauses[] = 'id < ?';
            $params[] = $cursorId;
        }
        if ($mediaType !== '') {
            $clauses[] = 'media_type = ?';
            $params[] = $mediaType;
        }

        $rows = App::db()->select(
            'SELECT id, asset_id, media_type, mime_type, size_bytes, duration_seconds, width, height, expires_at, created_at
             FROM media_assets
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY id DESC
              LIMIT ' . ($limit + 1),
            $params
        );

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = end($rows);

        return [
            'files' => array_map(static fn (array $row): array => [
                'file_id' => (string) $row['asset_id'],
                'asset_id' => (string) $row['asset_id'],
                'media_type' => (string) $row['media_type'],
                'mime_type' => $row['mime_type'] ?? null,
                'size_bytes' => isset($row['size_bytes']) ? (int) $row['size_bytes'] : null,
                'duration_seconds' => isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
                'width' => isset($row['width']) ? (int) $row['width'] : null,
                'height' => isset($row['height']) ? (int) $row['height'] : null,
                'expires_at' => $row['expires_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ], $rows),
            'next_cursor' => $hasMore && is_array($last) ? 'id_' . (int) ($last['id'] ?? 0) : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    public function delete(array $apiKey, string $fileId): array
    {
        $asset = $this->file($apiKey, $fileId);
        App::db()->execute('UPDATE media_assets SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL', [(int) $asset['id']]);
        (new AuditService())->write('asset.delete', 'media_assets', (string) $asset['asset_id'], ['api_key_id' => $apiKey['id'] ?? null]);
        $this->deleteOwnedLocalFile((string) ($asset['storage_path'] ?? ''));
        return ['file_id' => $fileId, 'deleted' => true, 'deleted_at' => gmdate('Y-m-d H:i:s')];
    }

    public function cursorId(?string $cursor): ?int
    {
        return $cursor !== null && preg_match('/^id_(\d+)$/', $cursor, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * @param array<string, mixed> $apiKey
     * @return array<string, mixed>
     */
    private function file(array $apiKey, string $fileId): array
    {
        $asset = App::db()->first(
            'SELECT * FROM media_assets WHERE asset_id = ? AND media_type = "file" AND deleted_at IS NULL',
            [$fileId]
        );
        // Unauthorized and missing look identical: no object existence leak.
        if ($asset === null || !(new AssetAccessPolicy())->allows($asset, $apiKey)) {
            abort_json('FILE_NOT_FOUND', 'File not found', 404);
        }
        return $asset;
    }

    private function safeName(string $name): string
    {
        $name = trim(basename(str_replace('\\', '/', $name)));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file.bin';
        return substr($name, 0, 160);
    }

    private function safeMime(string $mimeType): string
    {
        return preg_match('/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+$/', $mimeType) === 1 ? $mimeType : 'application/octet-stream';
    }

    private function deleteOwnedLocalFile(string $path): void
    {
        $root = realpath(storage_path('media' . DIRECTORY_SEPARATOR . 'external'));
        $file = realpath($path);
        if ($root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
            unlink($file);
        }
    }
}
