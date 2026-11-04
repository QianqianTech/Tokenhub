<?php

declare(strict_types=1);

namespace TokenHub\Services\Media;

use TokenHub\Support\App;

/**
 * Zip-bomb guard for uploaded ZIP containers (RAG image packages and
 * docx/pptx/xlsx documents), SEC-107. The central directory is inspected with
 * ZipArchive::statIndex() before anything is decompressed: the entry count,
 * every entry's declared uncompressed size and the total are bounded, and a
 * read never returns more bytes than the entry declared.
 *
 * Limits come from config rag.zip (env RAG_ZIP_MAX_ENTRIES,
 * RAG_ZIP_MAX_ENTRY_BYTES, RAG_ZIP_MAX_TOTAL_BYTES).
 */
final class ZipLimits
{
    public const DEFAULT_MAX_ENTRIES = 5000;
    public const DEFAULT_MAX_ENTRY_BYTES = 104857600; // 100 MiB
    public const DEFAULT_MAX_TOTAL_BYTES = 536870912; // 512 MiB

    public function __construct(
        private readonly ?int $maxEntries = null,
        private readonly ?int $maxEntryBytes = null,
        private readonly ?int $maxTotalBytes = null,
    ) {
    }

    public function maxEntries(): int
    {
        return $this->maxEntries ?? max(1, (int) App::config()->get('rag.zip.max_entries', self::DEFAULT_MAX_ENTRIES));
    }

    public function maxEntryBytes(): int
    {
        return $this->maxEntryBytes ?? max(1, (int) App::config()->get('rag.zip.max_entry_bytes', self::DEFAULT_MAX_ENTRY_BYTES));
    }

    public function maxTotalBytes(): int
    {
        return $this->maxTotalBytes ?? max(1, (int) App::config()->get('rag.zip.max_total_bytes', self::DEFAULT_MAX_TOTAL_BYTES));
    }

    /**
     * Error code when the archive exceeds a limit, null when it is acceptable.
     */
    public function violation(\ZipArchive $zip): ?string
    {
        if ($zip->numFiles > $this->maxEntries()) {
            return 'RAG_ZIP_TOO_MANY_ENTRIES';
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                return 'RAG_ZIP_INVALID';
            }
            $size = (int) ($stat['size'] ?? 0);
            if ($size < 0 || $size > $this->maxEntryBytes()) {
                return 'RAG_ZIP_ENTRY_TOO_LARGE';
            }
            $total += $size;
            if ($total > $this->maxTotalBytes()) {
                return 'RAG_ZIP_TOO_LARGE';
            }
        }

        return null;
    }

    /**
     * Throws RuntimeException(code) when the archive exceeds a limit.
     */
    public function assertWithin(\ZipArchive $zip): void
    {
        $violation = $this->violation($zip);
        if ($violation !== null) {
            throw new \RuntimeException($violation);
        }
    }

    /**
     * Reads one entry, never more than its declared size (and the per-entry
     * limit), so a lying central directory cannot inflate past the check.
     */
    public function read(\ZipArchive $zip, string $name): string|false
    {
        $stat = $zip->statName($name);
        if (!is_array($stat)) {
            return false;
        }
        $size = (int) ($stat['size'] ?? 0);
        if ($size > $this->maxEntryBytes()) {
            return false;
        }
        if ($size === 0) {
            return '';
        }
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return false;
        }
        $content = stream_get_contents($stream, $size + 1);
        fclose($stream);
        if (!is_string($content) || strlen($content) > $size) {
            return false;
        }

        return $content;
    }
}
