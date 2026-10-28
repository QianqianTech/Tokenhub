<?php

declare(strict_types=1);

namespace TokenHub\Services\Video;

/**
 * TokenHub's vendor-neutral /v1/videos/generations request.
 *
 *   prompt, negative_prompt
 *   image_url | first_frame_image | first_frame_url    first frame (image-to-video)
 *   last_frame_url | last_frame_image                  last frame
 *   reference_images[] / reference_videos[] / reference_audios[]
 *                                                      omni-reference inputs
 *   duration | seconds, resolution, ratio | aspect_ratio, size, fps, frames
 *   seed, watermark, generate_audio (alias: audio), camera_fixed
 *   content[]                                          vendor-native content, sent as is
 *   provider_options {}                                vendor-specific body fields, merged as is
 *
 * Every media value may be a URL string, {"url": ...}, {"image_url": {"url": ...}}
 * or a base64 data URI.
 */
final class CanonicalVideoRequest
{
    /**
     * @param list<string> $referenceImages
     * @param list<string> $referenceVideos
     * @param list<string> $referenceAudios
     * @param array<string, mixed> $providerOptions
     * @param list<array<string, mixed>>|null $nativeContent
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $prompt,
        public readonly ?string $negativePrompt,
        public readonly ?string $firstFrame,
        public readonly ?string $lastFrame,
        public readonly array $referenceImages,
        public readonly array $referenceVideos,
        public readonly array $referenceAudios,
        public readonly ?int $duration,
        public readonly ?string $resolution,
        public readonly ?string $ratio,
        public readonly ?string $size,
        public readonly ?int $seed,
        public readonly ?bool $watermark,
        public readonly ?bool $generateAudio,
        public readonly ?bool $cameraFixed,
        public readonly ?int $fps,
        public readonly ?int $frames,
        public readonly array $providerOptions,
        public readonly ?array $nativeContent,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $first = self::media($payload['first_frame_image'] ?? $payload['first_frame_url'] ?? $payload['first_frame'] ?? $payload['image_url'] ?? $payload['image'] ?? null);
        $last = self::media($payload['last_frame_image'] ?? $payload['last_frame_url'] ?? $payload['last_frame'] ?? null);

        $content = $payload['content'] ?? null;
        $options = $payload['provider_options'] ?? $payload['extra_body'] ?? [];

        return new self(
            prompt: self::string($payload['prompt'] ?? $payload['input'] ?? $payload['text'] ?? '') ?? '',
            negativePrompt: self::string($payload['negative_prompt'] ?? null),
            firstFrame: $first,
            lastFrame: $last,
            referenceImages: self::mediaList($payload['reference_images'] ?? $payload['reference_image'] ?? []),
            referenceVideos: self::mediaList($payload['reference_videos'] ?? $payload['reference_video'] ?? $payload['video_url'] ?? []),
            referenceAudios: self::mediaList($payload['reference_audios'] ?? $payload['reference_audio'] ?? $payload['audio_url'] ?? []),
            duration: self::int($payload['duration'] ?? $payload['seconds'] ?? null),
            resolution: self::string($payload['resolution'] ?? null),
            ratio: self::string($payload['ratio'] ?? $payload['aspect_ratio'] ?? null),
            size: self::string($payload['size'] ?? null),
            seed: self::int($payload['seed'] ?? null, true),
            watermark: self::bool($payload['watermark'] ?? null),
            generateAudio: self::bool($payload['generate_audio'] ?? $payload['audio'] ?? $payload['with_audio'] ?? null),
            cameraFixed: self::bool($payload['camera_fixed'] ?? null),
            fps: self::int($payload['fps'] ?? null),
            frames: self::int($payload['frames'] ?? null),
            providerOptions: is_array($options) ? self::withoutModel($options) : [],
            nativeContent: is_array($content) && array_is_list($content) && $content !== [] ? $content : null,
            raw: $payload,
        );
    }

    public function hasImageToVideo(): bool
    {
        return $this->firstFrame !== null || $this->lastFrame !== null;
    }

    public function hasReferences(): bool
    {
        return $this->referenceImages !== [] || $this->referenceVideos !== [] || $this->referenceAudios !== [];
    }

    /**
     * Requested output length, for estimates and billing when the upstream
     * reports none.
     */
    public function requestedSeconds(): int
    {
        if ($this->duration !== null && $this->duration > 0) {
            return $this->duration;
        }
        if ($this->frames !== null && $this->frames > 0) {
            return (int) ceil($this->frames / max(1, $this->fps ?? 24));
        }

        return 0;
    }

    /**
     * Ark / MiniMax H3 style content array (text + role-tagged media).
     *
     * @return list<array<string, mixed>>
     */
    public function roleContent(): array
    {
        if ($this->nativeContent !== null) {
            return $this->nativeContent;
        }
        $content = [];
        if ($this->prompt !== '') {
            $content[] = ['type' => 'text', 'text' => $this->prompt];
        }
        if ($this->firstFrame !== null) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $this->firstFrame], 'role' => 'first_frame'];
        }
        if ($this->lastFrame !== null) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $this->lastFrame], 'role' => 'last_frame'];
        }
        foreach ($this->referenceImages as $url) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url], 'role' => 'reference_image'];
        }
        foreach ($this->referenceVideos as $url) {
            $content[] = ['type' => 'video_url', 'video_url' => ['url' => $url], 'role' => 'reference_video'];
        }
        foreach ($this->referenceAudios as $url) {
            $content[] = ['type' => 'audio_url', 'audio_url' => ['url' => $url], 'role' => 'reference_audio'];
        }

        return $content;
    }

    /**
     * Number of media inputs (images, videos, audio) for estimates.
     */
    public function mediaInputCount(): int
    {
        if ($this->nativeContent !== null) {
            return count(array_filter($this->nativeContent, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? '') !== 'text'));
        }

        return ($this->firstFrame !== null ? 1 : 0) + ($this->lastFrame !== null ? 1 : 0)
            + count($this->referenceImages) + count($this->referenceVideos) + count($this->referenceAudios);
    }

    public static function media(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }
        if (is_array($value)) {
            foreach ([$value['url'] ?? null, $value['image_url']['url'] ?? null, $value['image_url'] ?? null, $value['video_url']['url'] ?? null, $value['audio_url']['url'] ?? null] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function mediaList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        $items = is_array($value) && array_is_list($value) ? $value : [$value];
        $out = [];
        foreach ($items as $item) {
            $url = self::media($item);
            if ($url !== null) {
                $out[] = $url;
            }
        }

        return $out;
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function int(mixed $value, bool $allowNegative = false): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $allowNegative || $int > 0 ? $int : null;
    }

    private static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) || is_int($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function withoutModel(array $options): array
    {
        unset($options['model']);

        return $options;
    }
}
