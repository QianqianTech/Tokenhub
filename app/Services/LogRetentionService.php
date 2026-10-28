<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\Crypto;

final class LogRetentionService
{
    public function __construct(private readonly ?string $logPath = null)
    {
    }

    /**
     * @return list<string>
     */
    public function rotate(?string $logType = null, int $maxSizeBytes = 104857600, ?int $now = null): array
    {
        $now ??= time();
        $types = $logType === null ? LogService::LOG_TYPES : [(new LogService($this->basePath()))->normalizeType($logType)];
        $rotated = [];
        foreach ($types as $type) {
            $active = $this->basePath() . DIRECTORY_SEPARATOR . $type . '.jsonl';
            if (!is_file($active)) {
                continue;
            }

            $mtime = filemtime($active) ?: $now;
            $size = filesize($active) ?: 0;
            $differentDay = gmdate('Y-m-d', $mtime) !== gmdate('Y-m-d', $now);
            if (!$differentDay && $size < $maxSizeBytes) {
                continue;
            }

            $target = $this->basePath() . DIRECTORY_SEPARATOR . $type . '.' . gmdate('YmdHis', $mtime) . '.jsonl';
            rename($active, $target);
            file_put_contents($active, '', LOCK_EX);
            $rotated[] = $target;
        }

        return $rotated;
    }

    /**
     * @param list<array<string, mixed>> $policies
     * @return list<array<string, mixed>>
     */
    public function archive(array $policies, ?int $now = null): array
    {
        $now ??= time();
        $archives = [];
        foreach ($policies as $policy) {
            $type = (new LogService($this->basePath()))->normalizeType((string) ($policy['log_type'] ?? ''));
            $onlineDays = (int) ($policy['online_retention_days'] ?? $policy['retention_days'] ?? 30);
            $cutoff = $now - ($onlineDays * 86400);
            foreach (glob($this->basePath() . DIRECTORY_SEPARATOR . $type . '.*.jsonl') ?: [] as $file) {
                if ((filemtime($file) ?: $now) > $cutoff) {
                    continue;
                }
                $archivePath = $this->archivePath($file, (bool) ($policy['compress_enabled'] ?? true), (bool) ($policy['encrypt_enabled'] ?? true));
                $content = (string) file_get_contents($file);
                if ((bool) ($policy['compress_enabled'] ?? true)) {
                    $content = gzencode($content, 9) ?: '';
                }
                if ((bool) ($policy['encrypt_enabled'] ?? true)) {
                    $content = Crypto::encrypt($content);
                }
                if (!is_dir(dirname($archivePath))) {
                    mkdir(dirname($archivePath), 0775, true);
                }
                file_put_contents($archivePath, $content, LOCK_EX);
                unlink($file);
                $archives[] = [
                    'log_type' => $type,
                    'source_path' => $file,
                    'archive_path' => $archivePath,
                    'compressed' => (int) (bool) ($policy['compress_enabled'] ?? true),
                    'encrypted' => (int) (bool) ($policy['encrypt_enabled'] ?? true),
                    'size_bytes' => filesize($archivePath) ?: 0,
                ];
            }
        }

        return $archives;
    }

    /**
     * @param list<array<string, mixed>> $archives
     */
    public function cleanupArchives(array $archives, ?int $now = null, ?callable $deleteFile = null): int
    {
        $now ??= time();
        $deleteFile ??= static function (string $path): void {
            if (is_file($path)) {
                unlink($path);
            }
        };
        $count = 0;
        foreach ($archives as $archive) {
            $retentionDays = (int) ($archive['archive_retention_days'] ?? 180);
            $createdAt = strtotime((string) ($archive['created_at'] ?? 'now')) ?: $now;
            if ($createdAt + ($retentionDays * 86400) >= $now) {
                continue;
            }
            $deleteFile((string) $archive['archive_path']);
            $count++;
        }
        return $count;
    }

    public function basePath(): string
    {
        return rtrim($this->logPath ?? storage_path('logs'), DIRECTORY_SEPARATOR);
    }

    private function archivePath(string $sourcePath, bool $compressed, bool $encrypted): string
    {
        $suffix = '';
        if ($compressed) {
            $suffix .= '.gz';
        }
        if ($encrypted) {
            $suffix .= '.enc';
        }
        return $this->basePath() . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . basename($sourcePath) . $suffix;
    }
}

