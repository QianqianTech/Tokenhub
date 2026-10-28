<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagImageProcessingService
{
    /**
     * @param array<string, mixed> $upload
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    public function createAsset(array $upload, array $item): ?array
    {
        $source = (string) (($item['metadata']['image'] ?? '') ?: '');
        if ($source === '') {
            return null;
        }

        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $binary = $this->binary($source, dirname((string) ($upload['storage_path'] ?? '')), (string) ($metadata['package_image_path'] ?? ''));
        if ($binary === null) {
            return null;
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            return null;
        }

        $dir = storage_path('media' . DIRECTORY_SEPARATOR . 'rag-images');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $imageId = 'img_' . bin2hex(random_bytes(12));
        $mime = $info['mime'] ?? 'application/octet-stream';
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $path = $dir . DIRECTORY_SEPARATOR . $imageId . '.' . $extension;
        file_put_contents($path, $binary);

        App::db()->execute(
            'INSERT INTO rag_image_assets (image_id, kb_id, upload_id, original_filename, mime_type, width, height, size_bytes, sha256, storage_path, thumbnail_path, purpose, short_description, detailed_description, labels, warnings, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $imageId,
                $upload['kb_id'],
                $upload['id'],
                basename($source),
                $mime,
                $info[0] ?? null,
                $info[1] ?? null,
                strlen($binary),
                hash('sha256', $binary),
                $path,
                $path,
                $item['purpose'] ?? '',
                $item['description'] ?? '',
                $item['detailed_description'] ?? '',
                json_encode($this->labels($item), JSON_UNESCAPED_UNICODE),
                json_encode(['ocr_not_configured', 'caption_model_not_configured'], JSON_UNESCAPED_UNICODE),
                json_encode([
                    'source' => $source,
                    'package_image_path' => $metadata['package_image_path'] ?? null,
                    'source_uri' => $metadata['source_uri'] ?? '',
                    'objects' => $metadata['objects'] ?? '',
                    'human_annotation' => $metadata['human_annotation'] ?? [],
                    'ai_annotation' => $metadata['ai_annotation'] ?? [],
                    'ocr_status' => 'not_configured',
                    'caption_status' => 'not_configured',
                ], JSON_UNESCAPED_UNICODE),
            ]
        );

        $asset = App::db()->first('SELECT * FROM rag_image_assets WHERE image_id = ?', [$imageId]);
        if ($asset !== null) {
            return (new RagAiImageAnnotationService())->enrichAsset($asset);
        }

        return null;
    }

    private function binary(string $source, string $baseDir, string $packageImagePath = ''): ?string
    {
        if ($packageImagePath !== '' && is_file($packageImagePath)) {
            return file_get_contents($packageImagePath) ?: null;
        }
        if (str_starts_with($source, 'data:image/') && str_contains($source, ',')) {
            $raw = base64_decode(substr($source, strpos($source, ',') + 1), true);
            return is_string($raw) ? $raw : null;
        }
        $decoded = base64_decode($source, true);
        if ($decoded !== false && strlen($decoded) > 32) {
            return $decoded;
        }
        if (preg_match('/^https?:\/\//', $source) === 1) {
            $fetched = (new SafeRemoteFetcher())->get($source, OutboundUrlPolicy::PURPOSE_REMOTE_ASSET);
            return $fetched['ok'] ? (string) $fetched['body'] : null;
        }
        $path = $baseDir . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', $source));
        if (is_file($path)) {
            return file_get_contents($path) ?: null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<string>
     */
    private function labels(array $item): array
    {
        $tags = (string) (($item['metadata']['tags'] ?? '') ?: '');
        $objects = (string) (($item['metadata']['objects'] ?? '') ?: '');
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,|;，；]/u', $tags . '|' . $objects) ?: []))));
    }
}
