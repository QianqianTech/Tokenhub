<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class AdminDownloadService
{
    /** @return array{asset_id: string, download_url: string, expires_in: int} */
    public function createMediaDownload(string $assetId): array
    {
        $asset = (new MediaAssetService())->find($assetId);
        if ($asset === null) { abort_json('MEDIA_ASSET_NOT_FOUND', 'Media asset not found', 404); }
        $issued = $this->issue('admin_media', ['asset_id' => $asset['asset_id'], 'department_id' => $asset['department_id'] ?? null]);
        return ['asset_id' => (string) $asset['asset_id'], ...$issued];
    }

    /** @return array{archive_id: int, download_url: string, expires_in: int} */
    public function createArchiveDownload(int $id): array
    {
        $archive = App::db()->first('SELECT id FROM log_archive_files WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($archive === null) { abort_json('LOG_ARCHIVE_NOT_FOUND', 'Log archive not found', 404); }
        $issued = $this->issue('admin_log_archive', ['archive_id' => (int) $archive['id']]);
        return ['archive_id' => (int) $archive['id'], ...$issued];
    }

    /** @param array<string, mixed> $object @return array{download_url: string, expires_in: int} */
    private function issue(string $kind, array $object): array
    {
        $actor = App::context()->adminUser() ?? [];
        if (($actor['id'] ?? null) === null) { abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401); }
        $token = Crypto::token('admindl_');
        App::redis()->set('download:' . $token, ['kind' => $kind, 'admin_user_id' => (int) $actor['id'], ...$object], 300);
        return ['download_url' => rtrim((string) App::config()->get('app.url'), '/') . '/download/' . $token, 'expires_in' => 300];
    }
}
