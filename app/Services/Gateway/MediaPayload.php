<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * Recognises media inside request payloads (inline base64 images/audio/files
 * and media URLs) so that text-oriented processing leaves it alone:
 *
 * - token estimates count a media part as a fixed budget
 *   (GATEWAY_MEDIA_TOKEN_ESTIMATE, default 1500) instead of base64 length / 4,
 *   which turned one 1 MB screenshot into ~350k "tokens" and tripped quotas;
 * - outbound desensitization does not rewrite base64 data or signed media
 *   URLs (X-Amz-Security-Token=..., Expires=<ms timestamp>), which corrupted
 *   the media the upstream had to fetch.
 */
final class MediaPayload
{
    /** Keys whose string value is a media reference (URL or inline data). */
    private const MEDIA_KEYS = ['image_url', 'video_url', 'audio_url', 'file_url', 'image', 'video', 'mask', 'file_data', 'b64_json', 'first_frame_image', 'last_frame_image', 'last_frame_url', 'first_frame_url', 'img_url', 'reference_images', 'reference_videos', 'reference_audios'];

    /** Containers whose "url" / "data" child is a media reference. */
    private const MEDIA_CONTAINERS = ['image_url', 'video_url', 'audio_url', 'input_image', 'input_audio', 'input_video', 'input_file', 'source', 'file', 'image', 'video', 'mask'];

    private const VIDEO_KEYS = ['video_url', 'video', 'input_video', 'reference_videos'];

    private const INLINE_MIN_BASE64_CHARS = 512;

    public static function isInlineData(string $value): bool
    {
        if (str_starts_with($value, 'data:') && str_contains(substr($value, 0, 200), ';base64,')) {
            return true;
        }

        return strlen($value) >= self::INLINE_MIN_BASE64_CHARS
            && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', substr($value, 0, 4096)) === 1
            && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', substr($value, -1024)) === 1;
    }

    /**
     * True when $value (found under $key inside $parentKey) is media the
     * gateway must forward byte-for-byte.
     */
    public static function isMediaValue(string $value, ?string $key, ?string $parentKey): bool
    {
        if (self::isInlineData($value)) {
            return true;
        }
        if (preg_match('#^https?://#i', $value) !== 1 && !str_starts_with($value, 'file-') && !str_starts_with($value, 'file_')) {
            return false;
        }
        $key = $key === null ? null : strtolower($key);
        $parentKey = $parentKey === null ? null : strtolower($parentKey);
        if ($key !== null && in_array($key, self::MEDIA_KEYS, true)) {
            return true;
        }

        return in_array($key, ['url', 'data', 'file_id'], true) && $parentKey !== null && in_array($parentKey, self::MEDIA_CONTAINERS, true);
    }

    /**
     * Input-token estimate with media parts at a fixed per-part budget.
     *
     * @param array<string, mixed> $payload
     * @return array{tokens: int, media_parts: int}
     */
    public static function estimate(array $payload): array
    {
        $media = 0;
        $video = 0;
        $stripped = self::strip($payload, null, null, $media, $video);
        $chars = strlen(json_encode($stripped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $perPart = max(0, (int) env_value('GATEWAY_MEDIA_TOKEN_ESTIMATE', 1500));
        // A video clip is many frames: its own, larger budget.
        $perVideo = max(0, (int) env_value('GATEWAY_VIDEO_TOKEN_ESTIMATE', 8000));

        return [
            'tokens' => max(1, (int) ceil($chars / 4) + ($media - $video) * $perPart + $video * $perVideo),
            'media_parts' => $media,
        ];
    }

    private static function strip(mixed $value, ?string $key, ?string $parentKey, int &$media, int &$video): mixed
    {
        if (is_string($value)) {
            if (self::isMediaValue($value, $key, $parentKey)) {
                $media++;
                if (in_array(strtolower((string) $key), self::VIDEO_KEYS, true) || in_array(strtolower((string) $parentKey), self::VIDEO_KEYS, true)) {
                    $video++;
                }
                return '';
            }
            return $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $childKey => $child) {
            $out[$childKey] = self::strip($child, is_string($childKey) ? $childKey : $key, is_string($childKey) ? $key : $parentKey, $media, $video);
        }

        return $out;
    }
}
