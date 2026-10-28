<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\OutboundUrlPolicy;
use TokenHub\Services\SafeRemoteFetcher;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;
use TokenHub\Services\AuditService;
use TokenHub\Services\AuthService;
use TokenHub\Services\ExportService;
use TokenHub\Services\MediaAssetService;
use TokenHub\Services\PermissionService;

final class DownloadController
{
    /** Content-Type reported by the upstream for the last remote asset read. */
    private ?string $remoteContentType = null;

    public function show(Request $request): Response
    {
        $token = (string) $request->params['token'];
        $payload = App::redis()->get('download:' . $token);
        if (!is_array($payload)) {
            return Response::error('CONTENT_FORBIDDEN', 'Download token is invalid or expired', 404);
        }

        if (in_array((string) ($payload['kind'] ?? ''), ['admin_media', 'admin_log_archive'], true)) {
            return $this->adminFileResponse($payload);
        }

        if (!empty($payload['file_path'])) {
            return $this->fileResponse((string) $payload['file_path'], ['export_id' => $payload['export_id'] ?? null], null);
        }

        $asset = $this->asset($payload);
        if ($asset === null || !(new MediaAssetService())->canAccessDownloadPayload($asset, $payload)) {
            return Response::error('CONTENT_FORBIDDEN', 'Download file is not available', 404);
        }

        return $this->fileResponse((string) ($asset['storage_path'] ?? ''), $asset, (string) ($asset['asset_id'] ?? ''));
    }

    /** @param array<string, mixed> $payload */
    private function adminFileResponse(array $payload): Response
    {
        $actor = (new AuthService())->userFromSession((string) ($_COOKIE['tokenhub_admin_session'] ?? ''));
        if ($actor === null || (int) ($actor['id'] ?? 0) !== (int) ($payload['admin_user_id'] ?? 0)) {
            return Response::error('CONTENT_FORBIDDEN', 'Download authorization is invalid', 404);
        }

        if (($payload['kind'] ?? '') === 'admin_media') {
            $asset = App::db()->first('SELECT * FROM media_assets WHERE asset_id = ?', [(string) ($payload['asset_id'] ?? '')]);
            if ($asset === null || !(new PermissionService())->canManage($actor, isset($asset['department_id']) ? (int) $asset['department_id'] : null)) {
                return Response::error('CONTENT_FORBIDDEN', 'Download file is not available', 404);
            }
            return $this->fileResponse((string) ($asset['storage_path'] ?? ''), $asset, (string) $asset['asset_id']);
        }

        if (($actor['role'] ?? '') !== 'super_admin') {
            return Response::error('CONTENT_FORBIDDEN', 'Download file is not available', 404);
        }
        $archive = App::db()->first('SELECT * FROM log_archive_files WHERE id = ? AND deleted_at IS NULL', [(int) ($payload['archive_id'] ?? 0)]);
        if ($archive === null) {
            return Response::error('CONTENT_FORBIDDEN', 'Download file is not available', 404);
        }
        (new AuditService())->write('log_archive.download', 'log_archive_files', (int) $archive['id']);
        return $this->fileResponse((string) $archive['archive_path'], ['media_type' => 'file', 'archive_id' => $archive['id']], null);
    }

    /**
     * Serves an asset the caller is already authorized for
     * (GET /v1/videos/{id}/content).
     *
     * @param array<string, mixed> $asset
     */
    public function serveAsset(array $asset): Response
    {
        return $this->fileResponse((string) ($asset['storage_path'] ?? ''), $asset, (string) ($asset['asset_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function fileResponse(string $path, array $asset, ?string $assetId): Response
    {
        $body = $this->read($path);
        if ($body === false) {
            return Response::error('CONTENT_FORBIDDEN', 'Download file is not readable', 404);
        }
        if (!empty($asset['export_id'])) {
            $body = (new ExportService())->decryptCsvFromStorage($body);
        }

        if ($assetId !== null) {
            (new AuditService())->write('asset.download', 'media_assets', $assetId);
        }

        $mime = !empty($asset['export_id']) ? 'text/csv; charset=utf-8' : $this->mimeType($path, $asset);
        if ($mime === 'application/octet-stream') {
            // Generated media stored by URL have no recorded type: use the
            // upstream's, else sniff the bytes, so <img>/<video> can render.
            $mime = $this->remoteContentType ?? $this->sniff($body) ?? $mime;
        }

        return Response::raw($body, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $this->filename($path, $asset) . '"',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function asset(array $payload): ?array
    {
        if (!empty($payload['asset_id'])) {
            return App::db()->first('SELECT * FROM media_assets WHERE asset_id = ?', [$payload['asset_id']]);
        }

        return null;
    }

    private function read(string $path): string|false
    {
        if ($path === '') {
            return false;
        }

        if (preg_match('/^https?:\/\//i', $path) === 1) {
            // Upstream-returned URL: re-validated per redirect, size and time bounded.
            $fetched = (new SafeRemoteFetcher())->get($path, OutboundUrlPolicy::PURPOSE_REMOTE_ASSET);
            if (!$fetched['ok']) {
                App::logger()->warning('download', 'remote asset fetch refused', ['error' => $fetched['error'] ?? null]);
                return false;
            }

            $type = strtolower(trim(explode(';', (string) ($fetched['content_type'] ?? ''))[0]));
            $this->remoteContentType = preg_match('#^(image|video|audio)/[a-z0-9.+-]+$#', $type) === 1 ? $type : null;

            return (string) $fetched['body'];
        }

        $driver = \TokenHub\Services\Media\MediaStorageManager::forPath($path);

        return $driver !== null ? $driver->read($path) : false;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function mimeType(string $path, array $asset): string
    {
        if (!empty($asset['mime_type'])) {
            return (string) $asset['mime_type'];
        }

        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return 'application/octet-stream';
        }

        $type = function_exists('mime_content_type') ? mime_content_type($path) : false;
        return is_string($type) && $type !== '' ? $type : 'application/octet-stream';
    }

    private function sniff(string $body): ?string
    {
        if ($body === '' || !function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $type = $finfo !== false ? finfo_buffer($finfo, substr($body, 0, 65536)) : false;

        return is_string($type) && preg_match('#^(image|video|audio)/#', $type) === 1 ? $type : null;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function filename(string $path, array $asset): string
    {
        $extension = match ((string) ($asset['media_type'] ?? 'file')) {
            'audio' => '.mp3',
            'image' => '.png',
            'video' => '.mp4',
            default => '.bin',
        };
        if (!empty($asset['export_id'])) {
            return (string) $asset['export_id'] . '.csv';
        }
        $pathForName = preg_match('/^https?:\/\//i', $path) === 1 ? (string) (parse_url($path, PHP_URL_PATH) ?: '') : str_replace('\\', '/', $path);
        $basename = basename($pathForName);
        return $basename !== '' ? $basename : ((string) ($asset['asset_id'] ?? 'asset')) . $extension;
    }
}
