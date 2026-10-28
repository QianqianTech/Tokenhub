<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * multipart/form-data support for OpenAI-compatible file endpoints
 * (/v1/audio/transcriptions, /v1/audio/translations, /v1/images/edits).
 *
 * The parsed payload keeps ordinary form fields at the top level and file
 * parts under "__files" (never forwarded as JSON). OpenAIAdapter rebuilds a
 * multipart body for the upstream with the routed upstream model id.
 * Repeated file fields ("image[]" with several images) are kept in order
 * under "image[]#0", "image[]#1", ... and re-encoded as "image[]".
 *
 * Limits: GATEWAY_MAX_UPLOAD_BYTES (default 26214400 = 25 MiB) per file, and
 * a per-capability MIME allowlist checked against the detected content type
 * (audio/video for speech endpoints, images for image edits).
 */
final class MultipartUpload
{
    public const FILES_KEY = '__files';

    private const ALLOWED_MIME_PREFIXES = ['audio/', 'video/mp4', 'video/mpeg', 'video/webm', 'application/octet-stream'];

    private const IMAGE_MIME_PREFIXES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * @return list<string>
     */
    public static function allowedMimePrefixes(string $capability): array
    {
        return $capability === 'image_generation' ? self::IMAGE_MIME_PREFIXES : self::ALLOWED_MIME_PREFIXES;
    }

    public static function isMultipart(?string $contentType): bool
    {
        return is_string($contentType) && str_starts_with(strtolower(trim($contentType)), 'multipart/form-data');
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $files PHP $_FILES structure
     * @return array{ok: bool, payload?: array<string, mixed>, code?: string, message?: string}
     */
    public static function fromGlobals(array $fields, array $files, ?array $allowedMimePrefixes = null): array
    {
        $maxBytes = max(1, (int) env_value('GATEWAY_MAX_UPLOAD_BYTES', 26214400));
        $maxFiles = max(1, (int) env_value('GATEWAY_MAX_UPLOAD_FILES', 16));
        $parts = [];
        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                return ['ok' => false, 'code' => 'FILE_INVALID', 'message' => 'File upload failed'];
            }
            // name="image[]" arrives as one $_FILES entry with list values.
            $entries = [];
            if (is_array($file['name'] ?? null)) {
                foreach (array_keys($file['name']) as $index) {
                    $entries[(string) $name . '[]#' . count($entries)] = [
                        'name' => $file['name'][$index] ?? 'upload',
                        'type' => $file['type'][$index] ?? '',
                        'tmp_name' => $file['tmp_name'][$index] ?? '',
                        'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $file['size'][$index] ?? 0,
                    ];
                }
            } else {
                $entries[(string) $name] = $file;
            }
            foreach ($entries as $partName => $entry) {
                if (is_array($entry['name']) || is_array($entry['tmp_name'])) {
                    return ['ok' => false, 'code' => 'FILE_INVALID', 'message' => 'Nested file fields are not supported'];
                }
                if ((int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    return ['ok' => false, 'code' => 'FILE_INVALID', 'message' => 'File upload failed'];
                }
                $size = (int) ($entry['size'] ?? 0);
                if ($size <= 0 || $size > $maxBytes) {
                    return ['ok' => false, 'code' => 'FILE_TOO_LARGE', 'message' => 'File exceeds the upload limit'];
                }
                $path = (string) ($entry['tmp_name'] ?? '');
                $mime = self::detectMime($path, (string) ($entry['type'] ?? ''));
                if (!self::mimeAllowed($mime, $allowedMimePrefixes)) {
                    return ['ok' => false, 'code' => 'FILE_TYPE_UNSUPPORTED', 'message' => 'Unsupported file type'];
                }
                $parts[$partName] = [
                    'path' => $path,
                    'filename' => basename((string) ($entry['name'] ?? 'upload')),
                    'mime' => $mime,
                    'size' => $size,
                ];
                if (count($parts) > $maxFiles) {
                    return ['ok' => false, 'code' => 'FILE_INVALID', 'message' => 'Too many files in one request'];
                }
            }
        }
        if ($parts === []) {
            return ['ok' => false, 'code' => 'FILE_REQUIRED', 'message' => 'A file part is required'];
        }

        return ['ok' => true, 'payload' => [...$fields, self::FILES_KEY => $parts]];
    }

    /**
     * @param list<string>|null $prefixes
     */
    public static function mimeAllowed(string $mime, ?array $prefixes = null): bool
    {
        foreach ($prefixes ?? self::ALLOWED_MIME_PREFIXES as $prefix) {
            if (str_starts_with(strtolower($mime), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{body: string, content_type: string}
     */
    public static function encode(array $payload, ?string $boundary = null): array
    {
        $boundary ??= 'tokenhub-' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($payload as $name => $value) {
            if ($name === self::FILES_KEY || $name === 'rag' || $value === null) {
                continue;
            }
            if (is_array($value)) {
                // name[]=a&name[]=b form fields, e.g. timestamp_granularities[].
                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $item = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
                        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . self::quote((string) $name) . "[]\"\r\n\r\n{$item}\r\n";
                    }
                }
                continue;
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . self::quote((string) $name) . "\"\r\n\r\n{$value}\r\n";
        }
        foreach (($payload[self::FILES_KEY] ?? []) as $name => $file) {
            $contents = is_file((string) ($file['path'] ?? '')) ? (string) file_get_contents((string) $file['path']) : (string) ($file['contents'] ?? '');
            $field = (string) preg_replace('/#\d+$/', '', (string) $name);
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . self::quote($field) . '"; filename="' . self::quote((string) ($file['filename'] ?? 'upload')) . "\"\r\n"
                . 'Content-Type: ' . (string) ($file['mime'] ?? 'application/octet-stream') . "\r\n\r\n" . $contents . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return ['body' => $body, 'content_type' => 'multipart/form-data; boundary=' . $boundary];
    }

    /**
     * Payload without file bytes, for estimates, logs and retention.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function describe(array $payload): array
    {
        if (!isset($payload[self::FILES_KEY])) {
            return $payload;
        }
        $payload['files'] = array_map(static fn (array $file): array => [
            'filename' => $file['filename'] ?? null,
            'mime' => $file['mime'] ?? null,
            'size' => $file['size'] ?? null,
        ], $payload[self::FILES_KEY]);
        unset($payload[self::FILES_KEY]);

        return $payload;
    }

    private static function detectMime(string $path, string $declared): string
    {
        if ($path !== '' && is_file($path) && function_exists('mime_content_type')) {
            $detected = mime_content_type($path);
            if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                return $detected;
            }
        }

        return $declared !== '' ? $declared : 'application/octet-stream';
    }

    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '', ''], $value);
    }
}
