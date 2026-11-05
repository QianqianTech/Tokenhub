<?php

declare(strict_types=1);

namespace TokenHub\Services;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use TokenHub\Support\Crypto;

/**
 * File storage for conversation log content (会话记录目录).
 *
 * The database keeps the index (key, session, time, tokens, cost); questions,
 * answers and system prompts are written here, one file per API key per day:
 *
 *   {root}/YYYY/MM/DD/key-{api_key_id}.jsonl          today's file, one line per call:
 *                                                    {"id":<row id>,"d":"<encrypted JSON>"}
 *   {root}/YYYY/MM/DD/key-{api_key_id}.jsonl.gz.enc  earlier days: the day's lines as
 *                                                    plain JSON, gzip-compressed, then
 *                                                    encrypted as a whole (APP_KEY)
 *
 * The day follows the configured cleanup timezone. compressDue() turns every
 * file of an earlier day into the compressed form (the daily cleanup run does
 * it). A late write for an already compressed day starts a new .jsonl next to
 * it; readers merge both and the next compression folds it in. prune() rewrites
 * a file without deleted rows and removes it when none remain.
 *
 * root: CONVERSATION_LOG_PATH, default storage/conversation-logs (the storage
 * volume in Docker, included in deploy/docker/backup.sh).
 *
 * Cloud storage (腾讯云 COS, useRemote()): the same names are kept as objects
 * under a prefix. Today's file is still appended locally and uploaded as a
 * snapshot whenever it changed (sync(), every scheduler run); compressed
 * files are uploaded and then removed locally after keep_local_days. Reads
 * fall back to COS when a file is not on disk; prune()/compress() rewrite the
 * object too. A ".cosmd5" marker next to a local file records what was uploaded.
 */
final class ConversationLogStore
{
    public const ACTIVE = '.jsonl';
    public const PACKED = '.jsonl.gz.enc';

    private string $root;

    public const MARKER = '.cosmd5';

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $cache = [];

    private ?TencentCosClient $remote = null;
    private string $prefix = '';
    private string $storageClass = '';

    public function __construct(?string $root = null)
    {
        $configured = $root ?? trim((string) env_value('CONVERSATION_LOG_PATH', ''));
        $this->root = rtrim($configured !== '' ? $configured : storage_path('conversation-logs'), DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Reads (and rewrites) fall back to COS; sync() uploads.
     */
    public function useRemote(?TencentCosClient $remote, string $prefix = '', string $storageClass = ''): self
    {
        $this->remote = $remote;
        $this->prefix = trim($prefix, '/');
        $this->storageClass = $storageClass;
        $this->cache = [];

        return $this;
    }

    public function hasRemote(): bool
    {
        return $this->remote !== null;
    }

    public function objectKey(string $relative, string $suffix): string
    {
        $this->path($relative);

        return ($this->prefix === '' ? '' : $this->prefix . '/') . $relative . $suffix;
    }

    public function remotePrefix(): string
    {
        return $this->prefix === '' ? '' : $this->prefix . '/';
    }

    /**
     * Relative base path (without extension) of the day file for a call.
     */
    public function relativePath(string $createdAtUtc, string $timezone, ?int $apiKeyId): string
    {
        try {
            $local = (new DateTimeImmutable($createdAtUtc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));
        } catch (Throwable) {
            $local = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return $local->format('Y/m/d') . '/key-' . max(0, (int) $apiKeyId);
    }

    /**
     * @param array<string, mixed> $payload system, messages, reply
     */
    public function append(string $relative, int $id, array $payload): void
    {
        $path = $this->path($relative) . self::ACTIVE;
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Conversation log directory is not writable: ' . $directory);
        }
        $line = json_encode(['id' => $id, 'd' => Crypto::encrypt($this->encode($payload))], JSON_UNESCAPED_SLASHES) . "\n";
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                throw new RuntimeException('Conversation log file is not writable: ' . $path);
            }
            try {
                flock($handle, LOCK_EX);
                // compress()/prune() may have replaced the file while we waited
                // for the lock: write to the file that is at the path now.
                clearstatcache(true, $path);
                $current = @stat($path);
                $opened = fstat($handle);
                if ($current === false || $opened === false || $current['ino'] !== $opened['ino']) {
                    continue;
                }
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new RuntimeException('Conversation log write failed: ' . $path);
                }
                fflush($handle);
                unset($this->cache[$relative]);
                return;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        throw new RuntimeException('Conversation log file kept changing: ' . $path);
    }

    /**
     * Payloads of the given rows of one file.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function read(string $relative, array $ids): array
    {
        $entries = $this->cache[$relative] ??= $this->load($relative);

        return array_intersect_key($entries, array_flip($ids));
    }

    /**
     * Compresses every active file of a day before $todayLocal (Y-m-d).
     *
     * @return array{files: int, bytes_before: int, bytes_after: int}
     */
    public function compressDue(string $todayLocal): array
    {
        $result = ['files' => 0, 'bytes_before' => 0, 'bytes_after' => 0];
        foreach ($this->files() as $relative => $variants) {
            if (!isset($variants['active']) || $this->dayOf($relative) >= $todayLocal) {
                continue;
            }
            $stat = $this->compress($relative);
            $result['files']++;
            $result['bytes_before'] += $stat['bytes_before'];
            $result['bytes_after'] += $stat['bytes_after'];
        }

        return $result;
    }

    /**
     * Folds the active file (and any earlier compressed part) into one
     * compressed, encrypted file.
     *
     * @return array{bytes_before: int, bytes_after: int}
     */
    public function compress(string $relative): array
    {
        $active = $this->path($relative) . self::ACTIVE;
        $packed = $this->path($relative) . self::PACKED;
        $before = (is_file($active) ? (int) filesize($active) : 0) + (is_file($packed) ? (int) filesize($packed) : 0);
        $handle = @fopen($active, 'c+b');
        if ($handle === false) {
            return ['bytes_before' => $before, 'bytes_after' => $before];
        }
        try {
            flock($handle, LOCK_EX);
            $entries = $this->loadPacked($relative) + $this->remoteActive($relative, true) + $this->parseActive((string) stream_get_contents($handle));
            ksort($entries);
            $this->writePacked($relative, $entries);
            @unlink($active);
            @unlink($active . self::MARKER);
            @unlink($packed . self::MARKER);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if ($this->remote !== null) {
            // Today's snapshot object is folded into the archive.
            $this->remote->delete($this->objectKey($relative, self::ACTIVE));
        }
        unset($this->cache[$relative]);
        clearstatcache();

        return ['bytes_before' => $before, 'bytes_after' => is_file($packed) ? (int) filesize($packed) : 0];
    }

    /**
     * Keeps only $keepIds in the file (both forms); removes it when empty.
     *
     * @param list<int> $keepIds
     */
    public function prune(string $relative, array $keepIds): void
    {
        $active = $this->path($relative) . self::ACTIVE;
        $packed = $this->path($relative) . self::PACKED;
        if (!is_file($active) && !is_file($packed) && $this->remote === null) {
            return;
        }
        if ($keepIds === []) {
            foreach ([$active, $packed, $active . self::MARKER, $packed . self::MARKER] as $file) {
                @unlink($file);
            }
            if ($this->remote !== null) {
                $this->remote->delete($this->objectKey($relative, self::ACTIVE));
                $this->remote->delete($this->objectKey($relative, self::PACKED));
            }
            $this->removeEmptyDirs(dirname($this->path($relative)));
            unset($this->cache[$relative]);
            return;
        }
        $handle = is_file($active) ? @fopen($active, 'c+b') : false;
        try {
            if ($handle !== false) {
                flock($handle, LOCK_EX);
            }
            $entries = $this->loadPacked($relative) + $this->remoteActive($relative, true) + ($handle !== false ? $this->parseActive((string) stream_get_contents($handle)) : []);
            $entries = array_intersect_key($entries, array_flip($keepIds));
            ksort($entries);
            if ($entries === []) {
                @unlink($packed);
            } else {
                $this->writePacked($relative, $entries);
            }
            @unlink($packed . self::MARKER);
            if ($handle !== false) {
                @unlink($active);
                @unlink($active . self::MARKER);
            }
        } finally {
            if ($handle !== false) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        if ($this->remote !== null) {
            // The cloud copy follows at once: deleted calls must not stay in COS.
            $this->remote->delete($this->objectKey($relative, self::ACTIVE));
            if ($entries === []) {
                $this->remote->delete($this->objectKey($relative, self::PACKED));
            } else {
                $this->upload($packed, $this->objectKey($relative, self::PACKED));
            }
        }
        unset($this->cache[$relative]);
    }

    /**
     * Relative base paths present on disk, with which forms exist.
     *
     * @return array<string, array{active?: int, packed?: int}> sizes in bytes
     */
    public function files(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($this->root))), '/');
            if (str_ends_with($relative, self::PACKED)) {
                $files[substr($relative, 0, -strlen(self::PACKED))]['packed'] = (int) $file->getSize();
            } elseif (str_ends_with($relative, self::ACTIVE)) {
                $files[substr($relative, 0, -strlen(self::ACTIVE))]['active'] = (int) $file->getSize();
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return array{files: int, days: int, active_bytes: int, packed_bytes: int, total_bytes: int, oldest_day: ?string}
     */
    public function stats(): array
    {
        $stats = ['files' => 0, 'days' => 0, 'active_bytes' => 0, 'packed_bytes' => 0, 'total_bytes' => 0, 'oldest_day' => null];
        $days = [];
        foreach ($this->files() as $relative => $variants) {
            $stats['files']++;
            $days[$this->dayOf($relative)] = true;
            $stats['active_bytes'] += $variants['active'] ?? 0;
            $stats['packed_bytes'] += $variants['packed'] ?? 0;
        }
        ksort($days);
        $stats['days'] = count($days);
        $stats['oldest_day'] = array_key_first($days);
        $stats['total_bytes'] = $stats['active_bytes'] + $stats['packed_bytes'];

        return $stats;
    }

    /**
     * Removes every file (清空全部).
     */
    public function deleteAll(): int
    {
        $removed = 0;
        foreach (array_keys($this->files()) as $relative) {
            foreach ([self::ACTIVE, self::PACKED] as $suffix) {
                @unlink($this->path($relative) . $suffix . self::MARKER);
                if (@unlink($this->path($relative) . $suffix)) {
                    $removed++;
                }
            }
            $this->removeEmptyDirs(dirname($this->path($relative)));
        }
        if ($this->remote !== null) {
            foreach (array_keys($this->remote->list($this->remotePrefix())) as $key) {
                $this->remote->delete($key);
                $removed++;
            }
        }
        $this->cache = [];

        return $removed;
    }

    /**
     * Uploads every local file whose content changed since the last upload,
     * then removes compressed files older than $keepLocalDays from disk
     * (they are read from COS from then on).
     *
     * @return array{uploaded: int, bytes: int, removed_local: int, errors: list<string>}
     */
    public function sync(string $todayLocal, int $keepLocalDays): array
    {
        $result = ['uploaded' => 0, 'bytes' => 0, 'removed_local' => 0, 'errors' => []];
        if ($this->remote === null) {
            return $result;
        }
        $cutoff = (new DateTimeImmutable($todayLocal))->modify('-' . max(0, $keepLocalDays) . ' days')->format('Y-m-d');
        foreach ($this->files() as $relative => $variants) {
            foreach (['active' => self::ACTIVE, 'packed' => self::PACKED] as $variant => $suffix) {
                if (!isset($variants[$variant])) {
                    continue;
                }
                $file = $this->path($relative) . $suffix;
                try {
                    if ($this->upload($file, $this->objectKey($relative, $suffix))) {
                        $result['uploaded']++;
                        $result['bytes'] += (int) @filesize($file);
                    }
                    if ($variant === 'packed' && $this->dayOf($relative) < $cutoff && !isset($variants['active'])) {
                        @unlink($file);
                        @unlink($file . self::MARKER);
                        $this->removeEmptyDirs(dirname($file));
                        $result['removed_local']++;
                    }
                } catch (Throwable $throwable) {
                    $result['errors'][] = $relative . $suffix . '：' . $throwable->getMessage();
                }
            }
        }

        return $result;
    }

    /**
     * Files in COS (relative base path => sizes), without index backups.
     *
     * @return array<string, array{active?: int, packed?: int}>
     */
    public function remoteFiles(): array
    {
        if ($this->remote === null) {
            return [];
        }
        $files = [];
        $prefix = $this->remotePrefix();
        foreach ($this->remote->list($prefix) as $key => $size) {
            $relative = substr($key, strlen($prefix));
            if (preg_match('#^(\d{4}/\d{2}/\d{2}/key-\d+)(\.jsonl\.gz\.enc|\.jsonl)$#', $relative, $m) !== 1) {
                continue;
            }
            $files[$m[1]][$m[2] === self::PACKED ? 'packed' : 'active'] = (int) $size;
        }
        ksort($files);

        return $files;
    }

    /**
     * Brings every COS file back to disk (switching back to local storage)
     * and removes it from COS.
     *
     * @return array{files: int, errors: list<string>}
     */
    public function pullAll(): array
    {
        $result = ['files' => 0, 'errors' => []];
        if ($this->remote === null) {
            return $result;
        }
        foreach (array_keys($this->remoteFiles()) as $relative) {
            try {
                $active = $this->path($relative) . self::ACTIVE;
                if (is_file($active)) {
                    // Today's file: the local copy is the newest; drop the snapshot.
                    $entries = $this->loadPacked($relative);
                    if ($entries !== [] && !is_file($this->path($relative) . self::PACKED)) {
                        $this->writePacked($relative, $entries);
                    }
                } else {
                    $entries = $this->loadPacked($relative) + $this->remoteActive($relative, true);
                    ksort($entries);
                    if ($entries !== []) {
                        $this->writePacked($relative, $entries);
                    }
                }
                @unlink($active . self::MARKER);
                @unlink($this->path($relative) . self::PACKED . self::MARKER);
                $this->remote->delete($this->objectKey($relative, self::ACTIVE));
                $this->remote->delete($this->objectKey($relative, self::PACKED));
                unset($this->cache[$relative]);
                $result['files']++;
            } catch (Throwable $throwable) {
                $result['errors'][] = $relative . '：' . $throwable->getMessage();
            }
        }

        return $result;
    }

    public function putObject(string $key, string $body): void
    {
        if ($this->remote === null) {
            throw new RuntimeException('COS is not configured');
        }
        $this->remote->put($key, $body, $this->storageClass);
    }

    /**
     * Uploads a local file unless the marker says this content is already in COS.
     */
    private function upload(string $file, string $key): bool
    {
        if ($this->remote === null || !is_file($file)) {
            return false;
        }
        $body = (string) @file_get_contents($file);
        $md5 = md5($body);
        if (is_file($file . self::MARKER) && trim((string) @file_get_contents($file . self::MARKER)) === $md5) {
            return false;
        }
        $this->remote->put($key, $body, $this->storageClass);
        @file_put_contents($file . self::MARKER, $md5);

        return true;
    }

    /**
     * Entries of today's snapshot in COS, when the local file is not here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function remoteActive(string $relative, bool $onlyIfNoLocal): array
    {
        if ($this->remote === null || ($onlyIfNoLocal && is_file($this->path($relative) . self::ACTIVE))) {
            return [];
        }
        $content = $this->remote->get($this->objectKey($relative, self::ACTIVE));

        return $content === null ? [] : $this->parseActive($content);
    }

    public function dayOf(string $relative): string
    {
        return preg_match('#^(\d{4})/(\d{2})/(\d{2})/#', $relative, $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : '0000-00-00';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function load(string $relative): array
    {
        $active = $this->path($relative) . self::ACTIVE;

        return $this->loadPacked($relative) + (is_file($active) ? $this->parseActive((string) @file_get_contents($active)) : $this->remoteActive($relative, true));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPacked(string $relative): array
    {
        $packed = $this->path($relative) . self::PACKED;
        if (is_file($packed)) {
            $data = (string) @file_get_contents($packed);
        } elseif ($this->remote !== null) {
            $data = $this->remote->get($this->objectKey($relative, self::PACKED));
            if ($data === null) {
                return [];
            }
        } else {
            return [];
        }
        $plain = Crypto::decrypt(trim($data));
        $json = $plain === '' ? false : @gzdecode($plain);
        if ($json === false) {
            throw new RuntimeException('Conversation log archive cannot be read (wrong APP_KEY?): ' . $relative . self::PACKED);
        }
        $entries = [];
        foreach (explode("\n", $json) as $line) {
            $row = $line === '' ? null : json_decode($line, true);
            if (is_array($row) && isset($row['id']) && is_array($row['p'] ?? null)) {
                $entries[(int) $row['id']] = $row['p'];
            }
        }

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseActive(string $content): array
    {
        $entries = [];
        foreach (explode("\n", $content) as $line) {
            $row = $line === '' ? null : json_decode($line, true);
            if (!is_array($row) || !isset($row['id'], $row['d'])) {
                continue;
            }
            try {
                $payload = json_decode(Crypto::decrypt((string) $row['d']), true);
            } catch (Throwable) {
                $payload = null;
            }
            if (is_array($payload)) {
                $entries[(int) $row['id']] = $payload;
            }
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function writePacked(string $relative, array $entries): void
    {
        $lines = '';
        foreach ($entries as $id => $payload) {
            $lines .= json_encode(['id' => $id, 'p' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        }
        $packed = $this->path($relative) . self::PACKED;
        $directory = dirname($packed);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Conversation log directory is not writable: ' . $directory);
        }
        $temp = $packed . '.tmp-' . bin2hex(random_bytes(4));
        $data = Crypto::encrypt((string) gzencode($lines, 6));
        if (@file_put_contents($temp, $data, LOCK_EX) !== strlen($data) || !@rename($temp, $packed)) {
            @unlink($temp);
            throw new RuntimeException('Conversation log archive cannot be written: ' . $packed);
        }
    }

    private function removeEmptyDirs(string $directory): void
    {
        while (str_starts_with($directory, $this->root) && $directory !== $this->root && is_dir($directory)) {
            $entries = @scandir($directory);
            if ($entries === false || count($entries) > 2 || !@rmdir($directory)) {
                return;
            }
            $directory = dirname($directory);
        }
    }

    private function path(string $relative): string
    {
        if (preg_match('#^\d{4}/\d{2}/\d{2}/key-\d+$#', $relative) !== 1) {
            throw new RuntimeException('Invalid conversation log path: ' . $relative);
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
