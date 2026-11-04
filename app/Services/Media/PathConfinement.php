<?php

declare(strict_types=1);

namespace TokenHub\Services\Media;

/**
 * Resolves a stored file path and accepts it only when the real path (after
 * symlinks and "..") lies inside one of the given storage roots (SEC-102).
 * A database column such as media_assets.storage_path or
 * rag_uploads.storage_path is therefore never enough to read or delete an
 * arbitrary server file such as .env.
 */
final class PathConfinement
{
    /**
     * @param list<string> $roots
     * @return string|null the resolved real path, or null when the file is missing or outside every root
     */
    public static function resolve(string $path, array $roots): ?string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            return null;
        }
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            return null;
        }
        foreach (self::realRoots($roots) as $root) {
            if (str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * @param list<string> $roots
     */
    public static function contains(string $path, array $roots): bool
    {
        return self::resolve($path, $roots) !== null;
    }

    /**
     * Storage root for RAG upload files (RagUploadService writes them there).
     *
     * @return list<string>
     */
    public static function ragUploadRoots(): array
    {
        return [storage_path('rag' . DIRECTORY_SEPARATOR . 'uploads')];
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private static function realRoots(array $roots): array
    {
        $resolved = [];
        foreach ($roots as $root) {
            $root = trim($root);
            if ($root === '') {
                continue;
            }
            $real = realpath($root);
            if ($real === false || !is_dir($real) || $real === DIRECTORY_SEPARATOR) {
                // A missing root holds no files; "/" would confine nothing.
                continue;
            }
            $resolved[$real] = rtrim($real, DIRECTORY_SEPARATOR);
        }

        return array_values($resolved);
    }
}
