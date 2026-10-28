<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

/**
 * Detects non-text inputs in chat-style requests (OpenAI Chat Completions,
 * Responses, Anthropic Messages):
 *
 *   image  {"type":"image_url"} / {"type":"input_image"} / Anthropic {"type":"image"}
 *   video  {"type":"video_url","video_url":{...}} (Doubao, Qwen-VL, GLM-4V, Kimi),
 *          {"type":"video","video":[frames]} (Qwen), {"type":"input_video"}
 *   audio  {"type":"input_audio","input_audio":{"data","format"}} (OpenAI, Gemini, Qwen-Omni),
 *          {"type":"audio_url"}, {"type":"input_audio"} in Responses
 *
 * Gemini parts carry inline_data / file_data with a MIME type (image/*,
 * video/*, audio/*).
 *
 * Only the conversation (messages / input / system / contents) is scanned, so request
 * options such as "audio": {"voice": ...} (spoken output) or "modalities"
 * are not mistaken for inputs.
 */
final class InputModalities
{
    private const IMAGE_TYPES = ['image_url', 'input_image', 'image'];
    private const VIDEO_TYPES = ['video_url', 'video', 'input_video'];
    private const AUDIO_TYPES = ['input_audio', 'audio_url'];

    /** Capabilities that are chat models with extra input modalities. */
    public const TEXT_FAMILY = ['text_generation', 'vision_understanding', 'video_understanding', 'audio_understanding'];

    /**
     * @param array<string, mixed> $payload
     * @return array{image: int, video: int, audio: int}
     */
    public static function count(array $payload): array
    {
        $counts = ['image' => 0, 'video' => 0, 'audio' => 0];
        foreach (['messages', 'input', 'system', 'contents'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                self::walk($payload[$key], $counts, 0);
            }
        }

        return $counts;
    }

    /**
     * Capability of a text-family request from its inputs: video beats audio
     * beats image (the model must accept every modality anyway, see
     * ModelCapabilityContract::requirements()).
     *
     * @param array{image: int, video: int, audio: int} $counts
     */
    public static function capability(array $counts): string
    {
        return match (true) {
            $counts['video'] > 0 => 'video_understanding',
            $counts['audio'] > 0 => 'audio_understanding',
            $counts['image'] > 0 => 'vision_understanding',
            default => 'text_generation',
        };
    }

    /**
     * @param array<mixed> $value
     * @param array{image: int, video: int, audio: int} $counts
     */
    private static function walk(array $value, array &$counts, int $depth): void
    {
        if ($depth > 8) {
            return;
        }
        foreach (['inline_data', 'inlineData', 'file_data', 'fileData'] as $geminiKey) {
            $mime = $value[$geminiKey]['mime_type'] ?? $value[$geminiKey]['mimeType'] ?? null;
            if (is_string($mime)) {
                $kind = strtolower(explode('/', $mime)[0]);
                if (isset($counts[$kind])) {
                    $counts[$kind]++;
                }
                return;
            }
        }
        $type = $value['type'] ?? null;
        if (is_string($type)) {
            if (in_array($type, self::VIDEO_TYPES, true)) {
                $counts['video']++;
                return;
            }
            if (in_array($type, self::AUDIO_TYPES, true)) {
                $counts['audio']++;
                return;
            }
            if (in_array($type, self::IMAGE_TYPES, true)) {
                $counts['image']++;
                return;
            }
        } elseif (isset($value['image_url']) && !isset($value['role'])) {
            // Typeless legacy image part.
            $counts['image']++;
            return;
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                self::walk($child, $counts, $depth + 1);
            }
        }
    }
}
