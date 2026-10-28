<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers\Vendor;

use TokenHub\Services\Gateway\MultipartUpload;

/**
 * Builds vendor requests from TokenHub's canonical media request.
 *
 * Canonical fields (all optional except model/prompt where the vendor needs them):
 *   prompt, negative_prompt, duration (s), resolution ("720p", "1080p", "2k"),
 *   aspect_ratio | ratio ("16:9"), size ("1280x720"), n, seed,
 *   image | image_url | first_frame   first frame / input image (URL or data URI)
 *   last_frame | end_image            last frame
 *   reference_images                  list of URLs / data URIs
 *   generate_audio                    bool
 *   input, voice, response_format, speed  (speech)
 *   vendor_options                    object merged into the vendor body (model, duration,
 *                                     count, size and callback fields cannot be overridden)
 *
 * Every mapper returns ['path' => ..., 'json' => [...]] (or 'multipart'),
 * plus optional 'headers' and 'relative' (append the path to the base URL
 * instead of the host root / versioned base).
 */
final class VendorRequestMapper
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function map(string $mapper, array $payload, string $model): array
    {
        if (!method_exists($this, $mapper)) {
            throw new \InvalidArgumentException('Unknown vendor mapper ' . $mapper);
        }
        /** @var array<string, mixed> $request */
        $request = $this->{$mapper}($payload, $model);
        if (is_array($payload['vendor_options'] ?? null) && isset($request['json'])) {
            // Vendor-specific extras are welcome, but never the fields that
            // decide authorization or billing (model, duration, count, size,
            // callback): those always come from TokenHub's mapping.
            $request['json'] = self::protect(array_replace_recursive($request['json'], self::stripProtected($payload['vendor_options'])), $request['json']);
        }

        return $request;
    }

    private const PROTECTED_KEYS = ['model', 'model_name', 'model_id', 'version', 'duration', 'durationseconds', 'seconds', 'music_length_ms', 'n', 'num', 'num_images', 'samplecount', 'resolution', 'size', 'width', 'height', 'callback_url', 'webhook', 'webhook_url', 'fal_webhook'];

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function stripProtected(array $options): array
    {
        foreach ($options as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::PROTECTED_KEYS, true)) {
                unset($options[$key]);
            } elseif (is_array($value)) {
                $options[$key] = self::stripProtected($value);
            }
        }

        return $options;
    }

    /**
     * Re-applies protected values from the mapped body after a merge.
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private static function protect(array $merged, array $mapped): array
    {
        foreach ($mapped as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::PROTECTED_KEYS, true)) {
                $merged[$key] = $value;
            } elseif (is_array($value) && is_array($merged[$key] ?? null)) {
                $merged[$key] = self::protect($merged[$key], $value);
            }
        }

        return $merged;
    }

    // ------------------------------------------------------------------ video

    private function klingVideo(array $p, string $model): array
    {
        $contents = [['type' => 'prompt', 'text' => $this->prompt($p)]];
        $first = $this->firstFrame($p);
        $last = $this->lastFrame($p);
        if ($first !== null) {
            $contents[] = ['type' => 'first_frame', 'url' => $this->rawBase64($first)];
        }
        if ($last !== null) {
            $contents[] = ['type' => 'last_frame', 'url' => $this->rawBase64($last)];
        }
        $omni = preg_match('/kling-4|omni/i', $model) === 1;
        if ($omni) {
            foreach ($this->references($p) as $index => $reference) {
                $contents[] = ['type' => 'refer_image', 'url' => $this->rawBase64($reference), 'id' => 'image_' . ($index + 1)];
            }
        }
        $settings = array_filter([
            'duration' => $this->duration($p),
            'resolution' => $this->lowerResolution($p),
            'aspect_ratio' => $this->aspect($p),
        ], static fn (mixed $value): bool => $value !== null);
        $audio = $this->audio($p);
        if ($audio !== null && !$omni) {
            $settings['audio'] = $audio ? 'native' : 'off';
        }
        $body = ['contents' => $contents];
        if ($settings !== []) {
            $body['settings'] = $settings;
        }
        $path = $omni ? '/omni-video/' . rawurlencode($model) : (($first !== null ? '/image-to-video/' : '/text-to-video/') . rawurlencode($model));

        return ['path' => $path, 'json' => $body];
    }

    private function minimaxVideo(array $p, string $model): array
    {
        $content = [['type' => 'text', 'text' => $this->prompt($p)]];
        foreach ([['first_frame', $this->firstFrame($p)], ['last_frame', $this->lastFrame($p)]] as [$role, $url]) {
            if ($url !== null) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url], 'role' => $role];
            }
        }
        foreach ($this->references($p) as $reference) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $reference], 'role' => 'reference_image'];
        }
        $resolution = strtolower((string) ($this->resolution($p) ?? '768p'));
        $resolution = match (true) {
            in_array($resolution, ['1080p', '2k', '1440p', '4k'], true) => '2K',
            in_array($resolution, ['480p', '540p', '360p'], true) => '480P',
            default => '768P',
        };

        return ['path' => '/v2/video_generation', 'json' => array_filter([
            'model' => $model,
            'content' => $content,
            'resolution' => $resolution,
            'duration' => $this->duration($p) ?? 6,
            'ratio' => $this->aspect($p),
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function wanVideo(array $p, string $model): array
    {
        $media = [];
        foreach ([['first_frame', $this->firstFrame($p)], ['last_frame', $this->lastFrame($p)]] as [$type, $url]) {
            if ($url !== null) {
                $media[] = ['type' => $type, 'url' => $url];
            }
        }
        if ($media === []) {
            foreach ($this->references($p) as $reference) {
                $media[] = ['type' => 'reference_image', 'url' => $reference];
            }
        }
        $input = ['prompt' => $this->prompt($p)];
        if ($media !== []) {
            $input['media'] = $media;
        }
        $parameters = array_filter([
            'resolution' => $this->resolution($p) !== null ? strtoupper((string) $this->resolution($p)) : null,
            'ratio' => $this->aspect($p),
            'duration' => $this->duration($p),
            'audio' => $this->audio($p),
            'seed' => $this->seed($p),
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'path' => '/api/v1/services/aigc/video-generation/video-synthesis',
            'headers' => ['X-DashScope-Async' => 'enable'],
            'json' => ['model' => $model, 'input' => $input, ...($parameters !== [] ? ['parameters' => $parameters] : [])],
        ];
    }

    private function viduVideo(array $p, string $model): array
    {
        $first = $this->firstFrame($p);
        $last = $this->lastFrame($p);
        $path = match (true) {
            $first !== null && $last !== null => '/ent/v2/start-end2video',
            $first !== null => '/ent/v2/img2video',
            default => '/ent/v2/text2video',
        };
        $body = array_filter([
            'model' => $model,
            'prompt' => $this->prompt($p) !== '' ? $this->prompt($p) : null,
            'images' => $first !== null ? array_values(array_filter([$first, $last])) : null,
            'duration' => $this->duration($p),
            'resolution' => $this->lowerResolution($p),
            'aspect_ratio' => $first === null ? $this->aspect($p) : null,
            'audio' => $this->audio($p),
            'seed' => $this->seed($p),
        ], static fn (mixed $value): bool => $value !== null);

        return ['path' => $path, 'json' => $body];
    }

    private function zhipuVideo(array $p, string $model): array
    {
        $first = $this->firstFrame($p);
        $last = $this->lastFrame($p);

        return ['path' => '/v1/videos/generations', 'json' => array_filter([
            'model' => $model,
            'prompt' => $this->prompt($p) !== '' ? $this->prompt($p) : null,
            'image_url' => $first !== null ? ($last !== null ? [$first, $last] : $first) : null,
            'duration' => $this->duration($p),
            'size' => $this->size($p),
            'with_audio' => $this->audio($p),
            'quality' => isset($p['quality']) ? (string) $p['quality'] : null,
            'fps' => isset($p['fps']) ? (int) $p['fps'] : null,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function xaiVideo(array $p, string $model): array
    {
        $first = $this->firstFrame($p);
        $references = array_map(static fn (string $url): array => ['url' => $url], $this->references($p));

        return ['path' => '/v1/videos/generations', 'json' => array_filter([
            'model' => $model,
            'prompt' => $this->prompt($p),
            'duration' => $this->duration($p),
            'resolution' => $this->lowerResolution($p),
            'aspect_ratio' => $this->aspect($p),
            'image' => $first !== null ? ['url' => $first] : null,
            'reference_images' => $references !== [] ? $references : null,
            'generate_audio' => $this->audio($p),
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function openrouterVideo(array $p, string $model): array
    {
        $frames = [];
        foreach ([['first_frame', $this->firstFrame($p)], ['last_frame', $this->lastFrame($p)]] as [$type, $url]) {
            if ($url !== null) {
                $frames[] = ['type' => 'image_url', 'image_url' => ['url' => $url], 'frame_type' => $type];
            }
        }
        $references = array_map(static fn (string $url): array => ['type' => 'image_url', 'image_url' => ['url' => $url]], $this->references($p));

        return ['path' => '/v1/videos', 'json' => array_filter([
            'model' => $model,
            'prompt' => $this->prompt($p),
            'duration' => $this->duration($p),
            'resolution' => $this->resolution($p),
            'aspect_ratio' => $this->aspect($p),
            'size' => $this->size($p),
            'frame_images' => $frames !== [] ? $frames : null,
            'input_references' => $references !== [] ? $references : null,
            'generate_audio' => $this->audio($p),
            'seed' => $this->seed($p),
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function runwayVideo(array $p, string $model): array
    {
        $first = $this->firstFrame($p);
        $ratio = match ($this->aspect($p)) {
            '9:16' => '720:1280',
            '1:1' => '960:960',
            '4:3' => '1104:832',
            '3:4' => '832:1104',
            '21:9' => '1584:672',
            default => '1280:720',
        };

        return [
            'path' => $first !== null ? '/v1/image_to_video' : '/v1/text_to_video',
            'headers' => ['X-Runway-Version' => '2024-11-06'],
            'json' => array_filter([
                'model' => $model,
                'promptText' => $this->prompt($p),
                'promptImage' => $first,
                'ratio' => $ratio,
                'duration' => $this->duration($p) ?? 5,
                'seed' => $this->seed($p),
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    private function lumaVideo(array $p, string $model): array
    {
        $video = array_filter([
            'duration' => $this->duration($p) !== null ? $this->duration($p) . 's' : null,
            'resolution' => $this->lowerResolution($p),
            'start_frame' => $this->firstFrame($p) !== null ? ['url' => $this->firstFrame($p)] : null,
            'end_frame' => $this->lastFrame($p) !== null ? ['url' => $this->lastFrame($p)] : null,
        ], static fn (mixed $value): bool => $value !== null);

        return ['path' => '/v1/generations', 'json' => array_filter([
            'model' => $model,
            'type' => 'video',
            'prompt' => $this->prompt($p),
            'aspect_ratio' => $this->aspect($p),
            'video' => $video !== [] ? $video : null,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function veoVertex(array $p, string $model): array
    {
        $instance = ['prompt' => $this->prompt($p)];
        foreach (['image' => $this->firstFrame($p), 'lastFrame' => $this->lastFrame($p)] as $key => $image) {
            if ($image !== null) {
                $instance[$key] = $this->googleImage($image);
            }
        }
        $parameters = array_filter([
            'durationSeconds' => $this->duration($p),
            'aspectRatio' => $this->aspect($p),
            'resolution' => $this->lowerResolution($p),
            'generateAudio' => $this->audio($p),
            'negativePrompt' => isset($p['negative_prompt']) ? (string) $p['negative_prompt'] : null,
            'seed' => $this->seed($p),
            'sampleCount' => 1,
        ], static fn (mixed $value): bool => $value !== null);

        return ['path' => '/' . $model . ':predictLongRunning', 'relative' => true, 'json' => ['instances' => [$instance], 'parameters' => $parameters]];
    }

    // ------------------------------------------------------------------ image

    private function klingImage(array $p, string $model): array
    {
        $image = $this->firstFrame($p);
        $resolution = strtolower((string) ($this->resolution($p) ?? ''));

        return ['path' => '/v1/images/generations', 'json' => array_filter([
            'model_name' => $model,
            'prompt' => $this->prompt($p),
            'negative_prompt' => $image === null && isset($p['negative_prompt']) ? (string) $p['negative_prompt'] : null,
            'image' => $image !== null ? $this->rawBase64($image) : null,
            'resolution' => in_array($resolution, ['1k', '2k'], true) ? $resolution : null,
            'aspect_ratio' => $this->aspect($p),
            'n' => $this->n($p),
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function minimaxImage(array $p, string $model): array
    {
        $reference = $this->firstFrame($p);

        return ['path' => '/v1/image_generation', 'json' => array_filter([
            'model' => $model,
            'prompt' => $this->prompt($p),
            'aspect_ratio' => $this->aspect($p),
            'n' => $this->n($p),
            'seed' => $this->seed($p),
            'response_format' => 'url',
            'subject_reference' => $reference !== null ? [['type' => 'character', 'image_file' => $reference]] : null,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function wanImage(array $p, string $model): array
    {
        $content = [];
        foreach (array_values(array_filter([$this->firstFrame($p), ...$this->references($p)])) as $image) {
            $content[] = ['image' => $image];
        }
        $content[] = ['text' => $this->prompt($p)];
        $size = $this->size($p);
        $parameters = array_filter([
            'size' => $size !== null ? str_replace('x', '*', $size) : ($this->resolution($p) !== null ? strtoupper((string) $this->resolution($p)) : null),
            'n' => $this->n($p),
            'seed' => $this->seed($p),
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'path' => '/api/v1/services/aigc/image-generation/generation',
            'headers' => ['X-DashScope-Async' => 'enable'],
            'json' => ['model' => $model, 'input' => ['messages' => [['role' => 'user', 'content' => $content]]], ...($parameters !== [] ? ['parameters' => $parameters] : [])],
        ];
    }

    private function bflImage(array $p, string $model): array
    {
        $images = array_values(array_filter([$this->firstFrame($p), ...$this->references($p)]));
        $body = ['prompt' => $this->prompt($p)];
        if ($images !== []) {
            $body['images'] = count($images) === 1 ? $images[0] : $images;
        }
        if ($this->aspect($p) !== null) {
            $body['aspect_ratio'] = $this->aspect($p);
        }
        $resolution = strtolower((string) ($this->resolution($p) ?? ''));
        if (in_array($resolution, ['768sq', '1k', '1.5k', '2k', '4k', 'match_input'], true)) {
            $body['resolution'] = $resolution;
        }
        if (($size = $this->size($p)) !== null && preg_match('/^(\d+)x(\d+)$/', $size, $m) === 1) {
            $body['width'] = (int) $m[1];
            $body['height'] = (int) $m[2];
        }

        return ['path' => '/v1/' . rawurlencode($model), 'json' => $body];
    }

    private function openrouterImage(array $p, string $model): array
    {
        $body = ['model' => $model, 'prompt' => $this->prompt($p)];
        foreach (['n', 'resolution', 'aspect_ratio', 'size', 'quality', 'output_format', 'background', 'output_compression', 'seed', 'user'] as $key) {
            if (isset($p[$key]) && $p[$key] !== '') {
                $body[$key] = $p[$key];
            }
        }
        $references = array_values(array_filter([$this->firstFrame($p), ...$this->references($p)]));
        if ($references !== []) {
            $body['input_references'] = array_map(static fn (string $url): array => ['type' => 'image_url', 'image_url' => ['url' => $url]], $references);
        }

        return ['path' => '/v1/images', 'json' => $body];
    }

    private function hunyuanImage(array $p, string $model): array
    {
        $image = $this->firstFrame($p);
        $size = $this->size($p);
        $body = array_filter([
            'Prompt' => $this->prompt($p),
            'NegativePrompt' => isset($p['negative_prompt']) ? (string) $p['negative_prompt'] : null,
            'Resolution' => $size !== null ? str_replace('x', ':', $size) : null,
            'Num' => $this->n($p),
            'Seed' => $this->seed($p),
            'ContentImage' => $image !== null ? (str_starts_with($image, 'data:') ? ['ImageBase64' => $this->rawBase64($image)] : ['ImageUrl' => $image]) : null,
        ], static fn (mixed $value): bool => $value !== null);

        return ['path' => '/', 'headers' => ['X-TC-Action' => 'SubmitHunyuanImageJob'], 'json' => $body];
    }

    // ------------------------------------------------------------------ queues

    private function falQueue(array $p, string $model): array
    {
        $input = $this->genericInput($p);
        if (isset($input['image']) && !isset($input['image_url'])) {
            $input['image_url'] = $input['image'];
            unset($input['image']);
        }

        return ['path' => '/' . ltrim($model, '/'), 'json' => $input];
    }

    private function replicate(array $p, string $model): array
    {
        return ['path' => '/v1/models/' . ltrim($model, '/') . '/predictions', 'json' => ['input' => $this->genericInput($p)]];
    }

    // ------------------------------------------------------------------ audio

    private function minimaxTts(array $p, string $model): array
    {
        $format = strtolower((string) ($p['response_format'] ?? 'mp3'));

        return ['path' => '/v1/t2a_v2', 'json' => [
            'model' => $model,
            'text' => (string) ($p['input'] ?? ''),
            'stream' => false,
            'voice_setting' => array_filter([
                'voice_id' => (string) ($p['voice'] ?? 'male-qn-qingse'),
                'speed' => isset($p['speed']) ? (float) $p['speed'] : null,
            ], static fn (mixed $value): bool => $value !== null),
            'audio_setting' => ['format' => in_array($format, ['mp3', 'pcm', 'flac', 'wav'], true) ? $format : 'mp3'],
            'output_format' => 'hex',
        ]];
    }

    private function cosyvoiceTts(array $p, string $model): array
    {
        $format = strtolower((string) ($p['response_format'] ?? 'mp3'));

        return ['path' => '/api/v1/services/audio/tts/SpeechSynthesizer', 'json' => [
            'model' => $model,
            'input' => array_filter([
                'text' => (string) ($p['input'] ?? ''),
                'voice' => (string) ($p['voice'] ?? 'longxiaochun'),
                'format' => in_array($format, ['mp3', 'pcm', 'wav', 'opus'], true) ? $format : 'mp3',
                'rate' => isset($p['speed']) ? (float) $p['speed'] : null,
            ], static fn (mixed $value): bool => $value !== null),
        ]];
    }

    private function elevenlabsTts(array $p, string $model): array
    {
        $voice = rawurlencode((string) ($p['voice'] ?? ''));
        $format = match (strtolower((string) ($p['response_format'] ?? 'mp3'))) {
            'pcm' => 'pcm_24000',
            'opus' => 'opus_48000_128',
            default => 'mp3_44100_128',
        };

        return ['path' => '/v1/text-to-speech/' . $voice . '?output_format=' . $format, 'headers' => ['Accept' => 'audio/mpeg'], 'json' => [
            'text' => (string) ($p['input'] ?? ''),
            'model_id' => $model,
        ]];
    }

    private function elevenlabsMusic(array $p, string $model): array
    {
        $duration = $this->duration($p);

        return ['path' => '/v1/music', 'json' => array_filter([
            'prompt' => $this->prompt($p),
            'music_length_ms' => $duration !== null ? $duration * 1000 : null,
            'model_id' => $model,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function elevenlabsStt(array $p, string $model): array
    {
        $fields = ['model_id' => $model];
        if (isset($p['language'])) {
            $fields['language_code'] = (string) $p['language'];
        }
        $files = [];
        foreach ((array) ($p[MultipartUpload::FILES_KEY] ?? []) as $name => $file) {
            if (str_starts_with((string) $name, 'file')) {
                $files['file'] = $file;
                break;
            }
        }

        return ['path' => '/v1/speech-to-text', 'multipart' => [...$fields, MultipartUpload::FILES_KEY => $files]];
    }

    /**
     * Qwen ASR through the OpenAI-compatible chat endpoint: the uploaded
     * file travels as a data URI in an input_audio part.
     */
    private function qwenAsr(array $p, string $model): array
    {
        $audio = null;
        foreach ((array) ($p[MultipartUpload::FILES_KEY] ?? []) as $file) {
            $bytes = is_file((string) ($file['path'] ?? '')) ? (string) file_get_contents((string) $file['path']) : (string) ($file['contents'] ?? '');
            $audio = 'data:' . (string) ($file['mime'] ?? 'audio/mpeg') . ';base64,' . base64_encode($bytes);
            break;
        }
        $audio ??= isset($p['file_url']) ? (string) $p['file_url'] : '';
        $body = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => [['type' => 'input_audio', 'input_audio' => ['data' => $audio]]]]],
            'stream' => false,
        ];
        if (isset($p['language'])) {
            $body['asr_options'] = ['language' => (string) $p['language']];
        }

        return ['path' => '/compatible-mode/v1/chat/completions', 'json' => $body];
    }

    private function deepgramListen(array $p, string $model): array
    {
        $file = null;
        foreach ((array) ($p[MultipartUpload::FILES_KEY] ?? []) as $candidate) {
            $file = $candidate;
            break;
        }
        $bytes = $file === null ? '' : (is_file((string) ($file['path'] ?? '')) ? (string) file_get_contents((string) $file['path']) : (string) ($file['contents'] ?? ''));
        $query = http_build_query(array_filter([
            'model' => $model,
            'language' => isset($p['language']) ? (string) $p['language'] : null,
            'smart_format' => 'true',
        ], static fn (mixed $value): bool => $value !== null));

        return ['path' => '/v1/listen?' . $query, 'raw' => $bytes, 'headers' => ['Content-Type' => (string) ($file['mime'] ?? 'audio/mpeg')]];
    }

    private function ideogramImage(array $p, string $model): array
    {
        return ['path' => '/v2/image/generate/' . rawurlencode($model), 'json' => array_filter([
            'prompt' => $this->prompt($p),
            'num_images' => $this->n($p),
            'seed' => $this->seed($p),
            'size' => $this->size($p),
            'quality' => isset($p['quality']) ? (string) $p['quality'] : null,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function stabilityImage(array $p, string $model): array
    {
        $endpoint = str_starts_with(strtolower($model), 'sd3') ? 'sd3' : strtolower($model);
        $fields = array_filter([
            'prompt' => $this->prompt($p),
            'negative_prompt' => isset($p['negative_prompt']) ? (string) $p['negative_prompt'] : null,
            'aspect_ratio' => $this->aspect($p),
            'seed' => $this->seed($p),
            'output_format' => 'png',
            'model' => $endpoint === 'sd3' ? $model : null,
        ], static fn (mixed $value): bool => $value !== null);

        return ['path' => '/v2beta/stable-image/generate/' . rawurlencode($endpoint), 'headers' => ['Accept' => 'application/json'], 'multipart' => [...$fields, MultipartUpload::FILES_KEY => []]];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Canonical payload minus TokenHub-only fields (queue vendors take the model's own input schema).
     *
     * @return array<string, mixed>
     */
    private function genericInput(array $p): array
    {
        $input = $p;
        foreach (['model', 'vendor_options', 'stream', 'response_format', MultipartUpload::FILES_KEY, 'rag', '__tokenhub_callback_url'] as $key) {
            unset($input[$key]);
        }
        if (is_array($p['vendor_options'] ?? null)) {
            $input = array_replace_recursive($input, self::stripProtected($p['vendor_options']));
        }

        return $input;
    }

    private function prompt(array $p): string
    {
        return (string) ($p['prompt'] ?? $p['input'] ?? '');
    }

    private function firstFrame(array $p): ?string
    {
        foreach (['first_frame', 'image', 'image_url', 'start_image'] as $key) {
            $value = $this->urlOf($p[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function lastFrame(array $p): ?string
    {
        foreach (['last_frame', 'end_image', 'last_frame_url', 'tail_image'] as $key) {
            $value = $this->urlOf($p[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function references(array $p): array
    {
        $out = [];
        foreach ((array) ($p['reference_images'] ?? []) as $item) {
            $url = $this->urlOf($item);
            if ($url !== null) {
                $out[] = $url;
            }
        }

        return $out;
    }

    private function urlOf(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value)) {
            $url = $value['url'] ?? $value['image_url']['url'] ?? $value['image_url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
            if (array_is_list($value) && isset($value[0])) {
                return $this->urlOf($value[0]);
            }
        }

        return null;
    }

    private function duration(array $p): ?int
    {
        $value = $p['duration'] ?? $p['seconds'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function resolution(array $p): ?string
    {
        $value = $p['resolution'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function lowerResolution(array $p): ?string
    {
        $value = $this->resolution($p);

        return $value !== null ? strtolower($value) : null;
    }

    private function aspect(array $p): ?string
    {
        $value = $p['aspect_ratio'] ?? $p['ratio'] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function size(array $p): ?string
    {
        $value = $p['size'] ?? null;

        return is_string($value) && preg_match('/^\d+[x*:]\d+$/', $value) === 1 ? str_replace(['*', ':'], 'x', $value) : null;
    }

    private function audio(array $p): ?bool
    {
        foreach (['generate_audio', 'with_audio', 'audio'] as $key) {
            if (array_key_exists($key, $p) && (is_bool($p[$key]) || in_array($p[$key], [0, 1, '0', '1', 'true', 'false'], true))) {
                return filter_var($p[$key], FILTER_VALIDATE_BOOL);
            }
        }

        return null;
    }

    private function seed(array $p): ?int
    {
        return isset($p['seed']) && is_numeric($p['seed']) ? (int) $p['seed'] : null;
    }

    private function n(array $p): ?int
    {
        return isset($p['n']) && is_numeric($p['n']) ? max(1, (int) $p['n']) : null;
    }

    /**
     * Kling takes bare base64 (no data: prefix) or a URL.
     */
    private function rawBase64(string $value): string
    {
        return preg_match('#^data:[^;,]*;base64,(.*)$#s', $value, $m) === 1 ? $m[1] : $value;
    }

    /**
     * @return array<string, string>
     */
    private function googleImage(string $value): array
    {
        if (preg_match('#^data:([^;,]*);base64,(.*)$#s', $value, $m) === 1) {
            return ['bytesBase64Encoded' => $m[2], 'mimeType' => $m[1] !== '' ? $m[1] : 'image/png'];
        }

        return str_starts_with($value, 'gs://') ? ['gcsUri' => $value, 'mimeType' => 'image/png'] : ['uri' => $value];
    }
}
