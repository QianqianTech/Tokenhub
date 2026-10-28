<?php

declare(strict_types=1);

namespace TokenHub\Services\Media;

use TokenHub\Support\Crypto;

/**
 * Default driver: files under storage/media/YYYY/MM (MEDIA_STORAGE_PATH overrides the root).
 */
final class LocalMediaStorage implements MediaStorage
{
    public function __construct(private readonly ?string $root = null)
    {
    }

    public function put(string $bytes, string $extension, string $mimeType): array
    {
        $dir = rtrim($this->root(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . gmdate('Y') . DIRECTORY_SEPARATOR . gmdate('m');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('MEDIA_STORAGE_UNWRITABLE');
        }
        $extension = $extension === '' || str_starts_with($extension, '.') ? $extension : '.' . $extension;
        $path = $dir . DIRECTORY_SEPARATOR . Crypto::token('media_') . $extension;
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('MEDIA_STORAGE_UNWRITABLE');
        }

        return ['storage_path' => $path, 'size_bytes' => strlen($bytes)];
    }

    public function read(string $storagePath): string|false
    {
        return is_file($storagePath) ? file_get_contents($storagePath) : false;
    }

    public function handles(string $storagePath): bool
    {
        return $storagePath !== '' && preg_match('#^[a-z][a-z0-9+.-]*://#i', $storagePath) !== 1;
    }

    public function delete(string $storagePath): bool
    {
        return is_file($storagePath) && @unlink($storagePath);
    }

    private function root(): string
    {
        $configured = trim((string) ($this->root ?? env_value('MEDIA_STORAGE_PATH', '')));
        if ($configured !== '') {
            return $configured;
        }
        $paths = trim((string) \TokenHub\Support\App::config()->get('paths.media', ''));

        return $paths !== '' ? $paths : storage_path('media');
    }
}
