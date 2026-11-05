<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;

/**
 * Collects the media of one gateway call for the conversation log while the
 * request payload is still in memory (ConversationExtractor calls it for every
 * image / audio / video part it meets).
 *
 * - Inline data (data: URLs, base64, multipart uploads) is encrypted into the
 *   media directory right away: the bytes must not travel through the queue.
 * - Links (http/https) and media-library ids are only noted; the queue worker
 *   copies them afterwards (conversation-logs.media).
 *
 * Each item gets a media key; the extractor puts it on the message part and
 * items() goes into the turn, from which writeForRecord() creates the rows.
 */
final class ConversationMediaCapture
{
    private const MAX_ITEMS = 24;

    /** @var list<array<string, mixed>> */
    private array $items = [];

    public function __construct(
        private readonly ConversationLogMediaStore $store,
        private readonly ?int $apiKeyId,
        private readonly string $createdAtUtc,
        private readonly string $timezone,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @param mixed $raw the part's source: data URL, URL, base64, Anthropic/OpenAI source array, multipart file
     * @return string|null media key, null when there is nothing to keep
     */
    public function __invoke(string $kind, mixed $raw, ?string $mime = null, string $direction = 'input'): ?string
    {
        if (count($this->items) >= self::MAX_ITEMS) {
            return null;
        }
        $kind = in_array($kind, ['image', 'audio', 'video'], true) ? $kind : 'file';
        $source = $this->source($raw, $mime);
        if ($source === null) {
            return null;
        }
        $key = ConversationLogMediaStore::newKey();
        $item = [
            'key' => $key,
            'direction' => $direction === 'output' ? 'output' : 'input',
            'kind' => $kind,
            'source' => $source['source'],
            'mime' => $source['mime'] ?? null,
            'name' => $source['name'] ?? null,
            'status' => 'pending',
        ];
        try {
            if ($source['source'] === 'inline') {
                $size = $source['size'];
                if ($size > $this->maxBytes) {
                    $item = [...$item, 'status' => 'skipped', 'size' => $size, 'error' => 'too_large'];
                } else {
                    $relative = $this->store->relativePath($this->createdAtUtc, $this->timezone, $this->apiKeyId, $key);
                    $item['size'] = isset($source['file'])
                        ? $this->store->writeFile($relative, $source['file'])
                        : $this->store->writeString($relative, $source['bytes']);
                    $item['file'] = $relative;
                    $item['status'] = 'stored';
                    $item['mime'] ??= self::sniff($source['bytes'] ?? (string) @file_get_contents($source['file'], false, null, 0, 4096));
                }
            } else {
                $item['ref'] = $source['ref'];
            }
        } catch (Throwable $throwable) {
            $item = [...$item, 'status' => 'failed', 'error' => mb_substr($throwable->getMessage(), 0, 300)];
            try {
                App::logger()->warning('gateway', 'conversation log media capture failed', ['error' => $throwable->getMessage()]);
            } catch (Throwable) {
            }
        }
        $this->items[] = $item;

        return $key;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function source(mixed $raw, ?string $mime): ?array
    {
        if (is_array($raw)) {
            // Multipart upload ({path, filename, mime, size}).
            if (isset($raw['path'], $raw['size']) && is_string($raw['path']) && is_file($raw['path'])) {
                return ['source' => 'inline', 'file' => $raw['path'], 'size' => (int) filesize($raw['path']), 'mime' => $this->mime($raw['mime'] ?? $mime), 'name' => is_string($raw['filename'] ?? null) ? mb_substr($raw['filename'], 0, 120) : null];
            }
            // Anthropic {type: base64, media_type, data}; OpenAI input_audio {data, format}; {url}.
            $data = $raw['data'] ?? null;
            $type = $raw['media_type'] ?? $raw['mime_type'] ?? $raw['mimeType'] ?? (isset($raw['format']) && is_string($raw['format']) ? 'audio/' . $raw['format'] : null);
            if (is_string($data) && $data !== '') {
                return $this->source($data, is_string($type) ? $type : $mime);
            }
            $url = $raw['url'] ?? $raw['image_url'] ?? $raw['video_url'] ?? $raw['audio_url'] ?? $raw['file_id'] ?? null;
            if (is_array($url)) {
                $url = $url['url'] ?? null;
            }

            return $url !== null ? $this->source($url, is_string($type) ? $type : $mime) : null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (str_starts_with($raw, 'data:')) {
            if (preg_match('#^data:([a-z0-9.+/-]+)?(;[^,]*)?,#i', substr($raw, 0, 200), $m) !== 1) {
                return null;
            }
            $bytes = base64_decode(substr($raw, strlen($m[0])), true);

            return $bytes === false ? null : ['source' => 'inline', 'bytes' => $bytes, 'size' => strlen($bytes), 'mime' => $this->mime(($m[1] ?? '') !== '' ? $m[1] : $mime)];
        }
        if (preg_match('#^https?://#i', $raw) === 1) {
            return ['source' => 'url', 'ref' => mb_substr($raw, 0, 4000), 'mime' => $this->mime($mime)];
        }
        if (preg_match('/^asset_[A-Za-z0-9_-]{6,80}$/', $raw) === 1) {
            return ['source' => 'asset', 'ref' => $raw, 'mime' => $this->mime($mime)];
        }
        if (strlen($raw) > 120 && preg_match('#^[A-Za-z0-9+/=\s_-]+$#', substr($raw, 0, 4096)) === 1) {
            $bytes = base64_decode(strtr($raw, '-_', '+/'), true);

            return $bytes === false ? null : ['source' => 'inline', 'bytes' => $bytes, 'size' => strlen($bytes), 'mime' => $this->mime($mime)];
        }

        return null;
    }

    private function mime(mixed $mime): ?string
    {
        if (!is_string($mime)) {
            return null;
        }
        $mime = strtolower(trim(explode(';', $mime)[0]));

        return preg_match('#^[a-z]+/[a-z0-9.+-]+$#', $mime) === 1 ? $mime : null;
    }

    public static function sniff(string $bytes): ?string
    {
        if ($bytes === '' || !function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $type = $finfo !== false ? finfo_buffer($finfo, substr($bytes, 0, 65536)) : false;

        return is_string($type) && preg_match('#^(image|video|audio)/#', $type) === 1 ? $type : null;
    }
}
