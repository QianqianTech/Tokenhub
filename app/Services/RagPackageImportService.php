<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class RagPackageImportService
{
    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return array{error_code: ?string, error_message: ?string, manifest_name: ?string, package_dir: string, items: list<array<string, mixed>>}
     */
    public function parse(string $zipPath, string $packageId): array
    {
        $packageDir = storage_path('rag' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $this->safeDirectoryName($packageId) . '_package');
        if (!is_dir($packageDir)) {
            mkdir($packageDir, 0775, true);
        }

        $entries = $this->entries($zipPath);
        if ($entries === []) {
            return $this->packageError('RAG_PACKAGE_INVALID', 'Image package is not a readable ZIP file.', $packageDir);
        }

        foreach ($entries as $entry) {
            if (!$this->isSafeEntryPath($entry)) {
                return $this->packageError('RAG_PACKAGE_PATH_INVALID', 'ZIP contains an unsafe path.', $packageDir);
            }
        }

        $manifests = array_values(array_filter($entries, static function (string $entry): bool {
            $normalized = str_replace('\\', '/', $entry);
            return !str_contains($normalized, '/') && in_array(strtolower($normalized), ['manifest.csv', 'manifest.xlsx'], true);
        }));

        if ($manifests === []) {
            return $this->packageError('RAG_PACKAGE_MANIFEST_MISSING', 'Image package must contain manifest.csv or manifest.xlsx at root.', $packageDir);
        }
        if (count($manifests) > 1) {
            return $this->packageError('RAG_PACKAGE_MANIFEST_CONFLICT', 'Image package contains more than one root manifest.', $packageDir);
        }

        $manifestName = $manifests[0];
        $manifestPath = $packageDir . DIRECTORY_SEPARATOR . basename($manifestName);
        file_put_contents($manifestPath, $this->readEntry($zipPath, $manifestName));

        $items = (new RagExcelImportService())->parse($manifestPath, 'image');
        $entryMap = array_fill_keys($entries, true);

        foreach ($items as $index => $item) {
            $items[$index] = $this->attachPackageImage($zipPath, $packageDir, $entryMap, $item);
        }

        return [
            'error_code' => null,
            'error_message' => null,
            'manifest_name' => $manifestName,
            'package_dir' => $packageDir,
            'items' => $items,
        ];
    }

    /**
     * @return array{error_code: string, error_message: string, manifest_name: null, package_dir: string, items: list<array<string, mixed>>}
     */
    private function packageError(string $code, string $message, string $packageDir): array
    {
        return [
            'error_code' => $code,
            'error_message' => $message,
            'manifest_name' => null,
            'package_dir' => $packageDir,
            'items' => [],
        ];
    }

    /**
     * @param array<string, bool> $entryMap
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function attachPackageImage(string $zipPath, string $packageDir, array $entryMap, array $item): array
    {
        $source = (string) (($item['metadata']['image'] ?? '') ?: '');
        $errors = is_array($item['validation_errors'] ?? null) ? $item['validation_errors'] : [];
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $metadata['package_mode'] = 'manifest_zip';
        $metadata['annotation_status'] = 'human_provided';
        $metadata['ai_annotation'] = [
            'ocr_text' => '',
            'short_description' => '',
            'detailed_description' => '',
            'objects' => '',
            'tags' => '',
            'status' => 'not_configured',
        ];
        $metadata['quality_warnings'] = ['ocr_not_configured', 'caption_model_not_configured'];

        if (!$this->isSafeManifestImagePath($source)) {
            $errors[] = 'RAG_PACKAGE_PATH_INVALID';
            return $this->markItem($item, $metadata, $errors);
        }

        if (!isset($entryMap[$source])) {
            $errors[] = 'RAG_PACKAGE_IMAGE_MISSING';
            return $this->markItem($item, $metadata, $errors);
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
            $errors[] = 'RAG_IMAGE_UNSUPPORTED_FORMAT';
            return $this->markItem($item, $metadata, $errors);
        }

        $binary = $this->readEntry($zipPath, $source);
        $info = @getimagesizefromstring($binary);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if ($info === false || !in_array($mime, self::ALLOWED_IMAGE_MIME_TYPES, true)) {
            $errors[] = $info === false ? 'RAG_IMAGE_INVALID' : 'RAG_IMAGE_UNSUPPORTED_FORMAT';
            return $this->markItem($item, $metadata, $errors);
        }

        $imageDir = $packageDir . DIRECTORY_SEPARATOR . 'images';
        if (!is_dir($imageDir)) {
            mkdir($imageDir, 0775, true);
        }
        $localPath = $imageDir . DIRECTORY_SEPARATOR . hash('sha256', $source) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        file_put_contents($localPath, $binary);

        $metadata['package_image_path'] = $localPath;
        $metadata['package_image_source'] = $source;
        $metadata['image_mime_type'] = $mime;
        $metadata['image_width'] = $info[0] ?? null;
        $metadata['image_height'] = $info[1] ?? null;

        return $this->markItem($item, $metadata, $errors);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $metadata
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function markItem(array $item, array $metadata, array $errors): array
    {
        $item['metadata'] = $metadata;
        $item['validation_errors'] = array_values(array_unique($errors));
        $item['validation_status'] = $item['validation_errors'] === [] ? ($item['validation_status'] ?? 'valid') : 'error';

        return $item;
    }

    /**
     * @return list<string>
     */
    private function entries(string $zipPath): array
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return [];
            }
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && !str_ends_with($name, '/')) {
                    $entries[] = $name;
                }
            }
            $zip->close();

            return $entries;
        }

        $output = $this->runUnzip(['unzip', '-Z1', $zipPath]);
        if ($output === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $output) ?: []), static fn (string $entry): bool => $entry !== '' && !str_ends_with($entry, '/')));
    }

    private function readEntry(string $zipPath, string $entry): string
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return '';
            }
            $content = $zip->getFromName($entry);
            $zip->close();

            return is_string($content) ? $content : '';
        }

        return $this->runUnzip(['unzip', '-p', $zipPath, $entry]) ?? '';
    }

    /**
     * @param list<string> $command
     */
    private function runUnzip(array $command): ?string
    {
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return $status === 0 ? $stdout : null;
    }

    private function isSafeEntryPath(string $entry): bool
    {
        if ($entry === '' || str_contains($entry, "\0")) {
            return false;
        }

        return $this->isSafeManifestImagePath($entry) || in_array(strtolower(str_replace('\\', '/', $entry)), ['manifest.csv', 'manifest.xlsx'], true);
    }

    private function isSafeManifestImagePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_contains($normalized, '://')) {
            return false;
        }
        foreach (explode('/', $normalized) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.')) {
                return false;
            }
        }

        return true;
    }

    private function safeDirectoryName(string $name): string
    {
        return substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'package', 0, 120);
    }
}

