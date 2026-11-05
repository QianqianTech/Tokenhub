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
 * Images, video and audio of the conversation log (会话记录的多模态内容):
 * generated results, reference images, uploaded audio and chat images,
 * copied into the conversation log directory so they live as long as the
 * record (the media library keeps generated files for a few days only).
 *
 *   {root}/media/YYYY/MM/DD/key-{api_key_id}/{media_key}.bin
 *
 * Each file is encrypted in 1 MiB chunks so a range of a video can be served
 * without decrypting the whole file:
 *
 *   "THCM1" | uint16 n | Crypto::encrypt(base64(file key)) (n bytes)
 *   | uint32 chunk size | uint64 plain size
 *   | per chunk: iv (12) | tag (16) | AES-256-GCM(chunk), AAD = chunk index
 *
 * The file key is random per file and wrapped with APP_KEY (rotate-app-key
 * only rewrites the header). With COS the same names are objects under the
 * prefix; files are uploaded once (they never change), removed locally after
 * keep_local_days and fetched back when viewed.
 */
final class ConversationLogMediaStore
{
    public const DIRECTORY = 'media';
    public const SUFFIX = '.bin';
    private const MAGIC = 'THCM1';
    private const CHUNK = 1048576;
    private const OVERHEAD = 28;

    private string $root;
    private ?TencentCosClient $remote = null;
    private string $prefix = '';
    private string $storageClass = '';

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? (new ConversationLogStore())->root();
    }

    public function useRemote(?TencentCosClient $remote, string $prefix = '', string $storageClass = ''): self
    {
        $this->remote = $remote;
        $this->prefix = trim($prefix, '/');
        $this->storageClass = $storageClass;

        return $this;
    }

    public static function newKey(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * Relative path of a media file (day in the configured timezone).
     */
    public function relativePath(string $createdAtUtc, string $timezone, ?int $apiKeyId, string $mediaKey): string
    {
        try {
            $local = (new DateTimeImmutable($createdAtUtc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));
        } catch (Throwable) {
            $local = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        $relative = self::DIRECTORY . '/' . $local->format('Y/m/d') . '/key-' . max(0, (int) $apiKeyId) . '/' . $mediaKey . self::SUFFIX;
        $this->path($relative);

        return $relative;
    }

    public function objectKey(string $relative): string
    {
        $this->path($relative);

        return ($this->prefix === '' ? '' : $this->prefix . '/') . $relative;
    }

    /**
     * Encrypts $bytes into the file. Returns the plain size.
     */
    public function writeString(string $relative, string $bytes): int
    {
        $source = fopen('php://memory', 'w+b');
        if ($source === false) {
            throw new RuntimeException('Cannot buffer media');
        }
        fwrite($source, $bytes);
        rewind($source);
        try {
            return $this->writeStream($relative, $source, strlen($bytes));
        } finally {
            fclose($source);
        }
    }

    /**
     * Encrypts a local file into the store without loading it into memory.
     */
    public function writeFile(string $relative, string $sourceFile): int
    {
        $size = @filesize($sourceFile);
        $source = @fopen($sourceFile, 'rb');
        if ($size === false || $source === false) {
            throw new RuntimeException('Media source cannot be read');
        }
        try {
            return $this->writeStream($relative, $source, (int) $size);
        } finally {
            fclose($source);
        }
    }

    /**
     * @param resource $source
     */
    private function writeStream(string $relative, $source, int $size): int
    {
        $path = $this->path($relative);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Conversation log media directory is not writable: ' . $directory);
        }
        $fileKey = random_bytes(32);
        $wrapped = Crypto::encrypt(base64_encode($fileKey));
        $temp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $out = @fopen($temp, 'wb');
        if ($out === false) {
            throw new RuntimeException('Conversation log media file is not writable: ' . $path);
        }
        $written = 0;
        try {
            fwrite($out, self::MAGIC . pack('n', strlen($wrapped)) . $wrapped . pack('N', self::CHUNK) . pack('J', $size));
            $index = 0;
            while ($written < $size) {
                $chunk = '';
                $want = min(self::CHUNK, $size - $written);
                while (strlen($chunk) < $want && !feof($source)) {
                    $part = fread($source, $want - strlen($chunk));
                    if ($part === false || $part === '') {
                        break;
                    }
                    $chunk .= $part;
                }
                if (strlen($chunk) !== $want) {
                    throw new RuntimeException('Media source ended early');
                }
                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($chunk, 'aes-256-gcm', $fileKey, OPENSSL_RAW_DATA, $iv, $tag, pack('J', $index));
                if ($cipher === false || fwrite($out, $iv . $tag . $cipher) === false) {
                    throw new RuntimeException('Media encryption failed');
                }
                $written += $want;
                $index++;
            }
        } catch (Throwable $throwable) {
            fclose($out);
            @unlink($temp);
            throw $throwable;
        }
        fclose($out);
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Conversation log media file cannot be written: ' . $path);
        }

        return $written;
    }

    /**
     * Plain size of a stored file (fetching it back from COS when needed).
     */
    public function size(string $relative): int
    {
        return $this->header($this->localFile($relative))['size'];
    }

    /**
     * Decrypted bytes [$start, $end] (inclusive), passed to $emit chunk by chunk.
     *
     * @param callable(string): void $emit
     */
    public function read(string $relative, int $start, int $end, callable $emit): void
    {
        $file = $this->localFile($relative);
        $header = $this->header($file);
        $end = min($end, $header['size'] - 1);
        if ($start > $end) {
            return;
        }
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Media file cannot be read');
        }
        try {
            $first = intdiv($start, $header['chunk']);
            $last = intdiv($end, $header['chunk']);
            for ($index = $first; $index <= $last; $index++) {
                $chunkStart = $index * $header['chunk'];
                $length = min($header['chunk'], $header['size'] - $chunkStart);
                fseek($handle, $header['offset'] + $index * ($header['chunk'] + self::OVERHEAD));
                $raw = (string) fread($handle, self::OVERHEAD + $length);
                $plain = openssl_decrypt(substr($raw, self::OVERHEAD), 'aes-256-gcm', $header['key'], OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), pack('J', $index));
                if ($plain === false) {
                    throw new RuntimeException('Media file cannot be decrypted (wrong APP_KEY or damaged file)');
                }
                $from = $index === $first ? $start - $chunkStart : 0;
                $to = $index === $last ? $end - $chunkStart : $length - 1;
                $emit(substr($plain, $from, $to - $from + 1));
            }
        } finally {
            fclose($handle);
        }
    }

    public function readAll(string $relative): string
    {
        $bytes = '';
        $this->read($relative, 0, PHP_INT_MAX, static function (string $part) use (&$bytes): void {
            $bytes .= $part;
        });

        return $bytes;
    }

    /**
     * Re-wraps the file key with the current APP_KEY (rotate-app-key).
     */
    public function rewrap(string $relative): bool
    {
        $file = $this->path($relative);
        if (!is_file($file)) {
            return false;
        }
        $header = $this->header($file);
        $wrapped = Crypto::encrypt(base64_encode($header['key']));
        $temp = $file . '.tmp-' . bin2hex(random_bytes(4));
        $in = fopen($file, 'rb');
        $out = fopen($temp, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Media file cannot be rewritten: ' . $relative);
        }
        fwrite($out, self::MAGIC . pack('n', strlen($wrapped)) . $wrapped . pack('N', $header['chunk']) . pack('J', $header['size']));
        fseek($in, $header['offset']);
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        rename($temp, $file);
        @unlink($file . ConversationLogStore::MARKER);

        return true;
    }

    public function delete(string $relative): void
    {
        $file = $this->path($relative);
        @unlink($file);
        @unlink($file . ConversationLogStore::MARKER);
        $this->removeEmptyDirs(dirname($file));
        if ($this->remote !== null) {
            $this->remote->delete($this->objectKey($relative));
        }
    }

    /**
     * Local media files: relative path => [bytes, mtime].
     *
     * @return array<string, array{bytes: int, mtime: int}>
     */
    public function files(): array
    {
        $base = $this->root . DIRECTORY_SEPARATOR . self::DIRECTORY;
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), self::SUFFIX)) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($this->root))), '/');
            if ($this->valid($relative)) {
                $files[$relative] = ['bytes' => (int) $file->getSize(), 'mtime' => (int) $file->getMTime()];
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Media objects in COS: relative path => size.
     *
     * @return array<string, int>
     */
    public function remoteFiles(): array
    {
        if ($this->remote === null) {
            return [];
        }
        $prefix = ($this->prefix === '' ? '' : $this->prefix . '/');
        $files = [];
        foreach ($this->remote->list($prefix . self::DIRECTORY . '/') as $key => $size) {
            $relative = substr($key, strlen($prefix));
            if ($this->valid($relative)) {
                $files[$relative] = (int) $size;
            }
        }

        return $files;
    }

    /**
     * Uploads files not in COS yet; removes local copies of days before the cutoff.
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
        foreach ($this->files() as $relative => $info) {
            $file = $this->path($relative);
            try {
                if (!is_file($file . ConversationLogStore::MARKER)) {
                    $this->remote->putFile($this->objectKey($relative), $file, $this->storageClass);
                    @file_put_contents($file . ConversationLogStore::MARKER, (string) $info['bytes']);
                    $result['uploaded']++;
                    $result['bytes'] += $info['bytes'];
                }
                if ($this->dayOf($relative) < $cutoff) {
                    @unlink($file);
                    @unlink($file . ConversationLogStore::MARKER);
                    $this->removeEmptyDirs(dirname($file));
                    $result['removed_local']++;
                }
            } catch (Throwable $throwable) {
                $result['errors'][] = $relative . '：' . $throwable->getMessage();
            }
        }

        return $result;
    }

    /**
     * Brings every COS media file back to disk and removes it from COS.
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
                $file = $this->path($relative);
                if (!is_file($file)) {
                    $this->download($relative);
                }
                @unlink($file . ConversationLogStore::MARKER);
                $this->remote->delete($this->objectKey($relative));
                $result['files']++;
            } catch (Throwable $throwable) {
                $result['errors'][] = $relative . '：' . $throwable->getMessage();
            }
        }

        return $result;
    }

    /**
     * Removes every local media file (清空全部; COS objects go with the prefix).
     */
    public function deleteAllLocal(): int
    {
        $removed = 0;
        foreach (array_keys($this->files()) as $relative) {
            $file = $this->path($relative);
            @unlink($file . ConversationLogStore::MARKER);
            if (@unlink($file)) {
                $removed++;
            }
            $this->removeEmptyDirs(dirname($file));
        }

        return $removed;
    }

    /**
     * @return array{files: int, bytes: int}
     */
    public function stats(): array
    {
        $files = $this->files();

        return ['files' => count($files), 'bytes' => array_sum(array_column($files, 'bytes'))];
    }

    public function dayOf(string $relative): string
    {
        return preg_match('#^media/(\d{4})/(\d{2})/(\d{2})/#', $relative, $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : '0000-00-00';
    }

    public static function keyOf(string $relative): string
    {
        return basename($relative, self::SUFFIX);
    }

    /**
     * Path on disk, downloaded from COS first when only the cloud has it.
     */
    private function localFile(string $relative): string
    {
        $file = $this->path($relative);
        if (!is_file($file)) {
            if ($this->remote === null || !$this->download($relative)) {
                throw new RuntimeException('Media file not found: ' . $relative);
            }
        }

        return $file;
    }

    private function download(string $relative): bool
    {
        if ($this->remote === null) {
            return false;
        }
        $file = $this->path($relative);
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Conversation log media directory is not writable: ' . $directory);
        }
        $temp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (!$this->remote->getToFile($this->objectKey($relative), $temp)) {
            return false;
        }
        rename($temp, $file);
        // Already in COS: the next sync must not upload it again.
        @file_put_contents($file . ConversationLogStore::MARKER, (string) filesize($file));

        return true;
    }

    /**
     * @return array{key: string, chunk: int, size: int, offset: int}
     */
    private function header(string $file): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Media file cannot be read');
        }
        try {
            $head = (string) fread($handle, 7);
            if (strlen($head) !== 7 || substr($head, 0, 5) !== self::MAGIC) {
                throw new RuntimeException('Not a conversation log media file');
            }
            $length = unpack('n', substr($head, 5))[1];
            $wrapped = (string) fread($handle, $length);
            $meta = (string) fread($handle, 12);
            if (strlen($wrapped) !== $length || strlen($meta) !== 12) {
                throw new RuntimeException('Damaged conversation log media file');
            }
            $key = base64_decode(Crypto::decrypt($wrapped), true);
            if (!is_string($key) || strlen($key) !== 32) {
                throw new RuntimeException('Media file key cannot be decrypted (wrong APP_KEY?)');
            }

            return [
                'key' => $key,
                'chunk' => unpack('N', substr($meta, 0, 4))[1],
                'size' => unpack('J', substr($meta, 4, 8))[1],
                'offset' => 7 + $length + 12,
            ];
        } finally {
            fclose($handle);
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

    private function valid(string $relative): bool
    {
        return preg_match('#^media/\d{4}/\d{2}/\d{2}/key-\d+/[a-f0-9]{24}\.bin$#', $relative) === 1;
    }

    private function path(string $relative): string
    {
        if (!$this->valid($relative)) {
            throw new RuntimeException('Invalid conversation log media path: ' . $relative);
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
