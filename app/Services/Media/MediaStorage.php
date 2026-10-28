<?php

declare(strict_types=1);

namespace TokenHub\Services\Media;

/**
 * Storage backend for generated media (images, video, audio, music).
 *
 * The returned "storage_path" is what media_assets.storage_path keeps and
 * what DownloadController reads back. The local driver returns an absolute
 * file path; object-storage drivers return "<scheme>://<key>" and must be able
 * to read it back through read().
 */
interface MediaStorage
{
    /**
     * @return array{storage_path: string, size_bytes: int}
     */
    public function put(string $bytes, string $extension, string $mimeType): array;

    public function read(string $storagePath): string|false;

    public function handles(string $storagePath): bool;

    public function delete(string $storagePath): bool;
}
