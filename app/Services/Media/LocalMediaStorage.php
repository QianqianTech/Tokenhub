<?php

declare(strict_types=1);

namespace TokenHub\Services\Media;

use TokenHub\Support\Crypto;

/**
 * Default driver: files under storage/media/YYYY/MM (MEDIA_STORAGE_PATH overrides the root).
 *
 * Reads and deletes are confined to the storage root(s) (SEC-102): a stored
 * path is resolved with realpath() and refused unless it lies inside the
 * configured media root, paths.media, storage/media or an explicitly passed
 * extra root, so a tampered storage_path cannot reach .env or other files.
 */
final class LocalMediaStorage implements MediaStorage
{
    /**
     * @param list<string>|null $allowedRoots roots that reads/deletes may touch; null = the media roots
     */
    public function __construct(private readonly ?string $root = null, private readonly ?array $allowedRoots = null)
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
        $real = PathConfinement::resolve($storagePath, $this->roots());

        return $real !== null ? file_get_contents($real) : false;
    }

    public function handles(string $storagePath): bool
    {
        return $storagePath !== '' && preg_match('#^[a-z][a-z0-9+.-]*://#i', $storagePath) !== 1;
    }

    public function delete(string $storagePath): bool
    {
        $real = PathConfinement::resolve($storagePath, $this->roots());

        return $real !== null && @unlink($real);
    }

    /**
     * Whether a stored local path resolves inside this driver's roots.
     */
    public function contains(string $storagePath): bool
    {
        return PathConfinement::contains($storagePath, $this->roots());
    }

    /** @return list<string> */
    public function roots(): array
    {
        if ($this->allowedRoots !== null) {
            return array_values($this->allowedRoots);
        }

        return array_values(array_unique([
            $this->root(),
            trim((string) \TokenHub\Support\App::config()->get('paths.media', '')),
            storage_path('media'),
        ]));
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
