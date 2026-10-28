<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use Throwable;
use TokenHub\Support\App;

final class RagAiImageAnnotationService
{
    /**
     * @param null|callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $annotator
     */
    public function __construct(private readonly mixed $annotator = null)
    {
    }

    /**
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    public function enrichAsset(array $asset): array
    {
        $settings = $this->settings();
        if (($settings['enabled'] ?? false) !== true) {
            return $this->markNotConfigured($asset);
        }

        try {
            $annotation = $this->annotate($asset, $settings);
            return $this->applyAnnotation($asset, $annotation);
        } catch (Throwable $throwable) {
            return $this->markFailed($asset, $throwable->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        try {
            return (new SystemSettingsService())->loadSection('rag_ai_image_processing');
        } catch (Throwable) {
            return [
                'enabled' => false,
                'vision_model_id' => 0,
                'prompt_profile' => 'default',
                'max_retries' => 2,
                'timeout_ms' => 60000,
            ];
        }
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function annotate(array $asset, array $settings): array
    {
        if (is_callable($this->annotator)) {
            $result = ($this->annotator)($asset, $settings);
            if (!is_array($result)) {
                throw new RuntimeException('AI annotator returned invalid response');
            }
            return $result;
        }

        $route = $this->visionRoute((int) ($settings['vision_model_id'] ?? 0));
        if ($route === null) {
            throw new RuntimeException('RAG_MODEL_NOT_CONFIGURED');
        }
        $imageData = $this->imageDataUrl($asset);
        if ($imageData === '') {
            throw new RuntimeException('RAG_IMAGE_INVALID');
        }

        $payload = $this->visionPayload($route, $asset, $imageData, (string) ($settings['prompt_profile'] ?? 'default'));
        $result = (new ProviderHttpClient())->request($route, $this->visionEndpoint($route), $payload);
        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($result['error_code'] ?? 'RAG_AI_ANNOTATION_FAILED'));
        }

        return $this->extractAnnotation(is_array($result['json'] ?? null) ? $result['json'] : []);
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, mixed> $annotation
     * @return array<string, mixed>
     */
    private function applyAnnotation(array $asset, array $annotation): array
    {
        $metadata = $this->metadata($asset);
        $warnings = array_values(array_diff($this->warnings($asset), ['ocr_not_configured', 'caption_model_not_configured', 'RAG_AI_ANNOTATION_FAILED']));
        $qualityWarnings = $this->stringList($annotation['quality_warnings'] ?? []);
        $warnings = array_values(array_unique([...$warnings, ...$qualityWarnings]));

        $ai = [
            'status' => 'completed',
            'ocr_text' => (string) ($annotation['ocr_text'] ?? ''),
            'short_description' => (string) ($annotation['ai_short_description'] ?? $annotation['short_description'] ?? ''),
            'detailed_description' => (string) ($annotation['ai_detailed_description'] ?? $annotation['detailed_description'] ?? ''),
            'objects' => $this->stringList($annotation['ai_objects'] ?? $annotation['objects'] ?? []),
            'tags' => $this->stringList($annotation['ai_tags'] ?? $annotation['tags'] ?? []),
            'quality_warnings' => $qualityWarnings,
        ];
        $metadata['ai_annotation'] = $ai;
        $metadata['ocr_status'] = $ai['ocr_text'] !== '' ? 'completed' : 'empty';
        $metadata['caption_status'] = ($ai['short_description'] !== '' || $ai['detailed_description'] !== '') ? 'completed' : 'empty';

        $labels = array_values(array_unique([...$this->jsonList($asset['labels'] ?? '[]'), ...$ai['objects'], ...$ai['tags']]));
        $shortDescription = trim((string) ($asset['short_description'] ?? '')) !== ''
            ? (string) $asset['short_description']
            : $ai['short_description'];
        $detailedDescription = trim((string) ($asset['detailed_description'] ?? '')) !== ''
            ? (string) $asset['detailed_description']
            : $ai['detailed_description'];

        App::db()->execute(
            'UPDATE rag_image_assets SET short_description = ?, detailed_description = ?, ocr_text = ?, labels = ?, warnings = ?, metadata = ? WHERE id = ?',
            [
                $shortDescription,
                $detailedDescription,
                $ai['ocr_text'],
                json_encode($labels, JSON_UNESCAPED_UNICODE),
                json_encode($warnings, JSON_UNESCAPED_UNICODE),
                json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $asset['id'],
            ]
        );

        return App::db()->first('SELECT * FROM rag_image_assets WHERE id = ?', [$asset['id']]) ?? $asset;
    }

    /**
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    private function markNotConfigured(array $asset): array
    {
        $metadata = $this->metadata($asset);
        $metadata['ai_annotation'] = [
            'status' => 'not_configured',
            'ocr_text' => '',
            'short_description' => '',
            'detailed_description' => '',
            'objects' => [],
            'tags' => [],
        ];
        $metadata['ocr_status'] = 'not_configured';
        $metadata['caption_status'] = 'not_configured';
        $warnings = array_values(array_unique([...$this->warnings($asset), 'ocr_not_configured', 'caption_model_not_configured']));
        App::db()->execute('UPDATE rag_image_assets SET warnings = ?, metadata = ? WHERE id = ?', [
            json_encode($warnings, JSON_UNESCAPED_UNICODE),
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $asset['id'],
        ]);

        return App::db()->first('SELECT * FROM rag_image_assets WHERE id = ?', [$asset['id']]) ?? $asset;
    }

    /**
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    private function markFailed(array $asset, string $message): array
    {
        $metadata = $this->metadata($asset);
        $metadata['ai_annotation'] = [
            'status' => 'failed',
            'error_code' => str_contains($message, 'RAG_MODEL_NOT_CONFIGURED') ? 'RAG_MODEL_NOT_CONFIGURED' : 'RAG_AI_ANNOTATION_FAILED',
            'error_message' => substr($message, 0, 300),
        ];
        $warnings = array_values(array_unique([...$this->warnings($asset), $metadata['ai_annotation']['error_code']]));
        App::db()->execute('UPDATE rag_image_assets SET warnings = ?, metadata = ? WHERE id = ?', [
            json_encode($warnings, JSON_UNESCAPED_UNICODE),
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $asset['id'],
        ]);

        return App::db()->first('SELECT * FROM rag_image_assets WHERE id = ?', [$asset['id']]) ?? $asset;
    }

    private function visionRoute(int $modelId): ?array
    {
        if ($modelId <= 0) {
            return null;
        }

        return App::db()->first(
            'SELECT m.*, m.id AS model_id, p.id AS provider_id, p.protocol, p.base_url, p.encrypted_secret, p.timeout_ms, p.retry_count, p.default_headers
             FROM models m JOIN providers p ON p.id = m.provider_id
             WHERE m.id = ? AND m.status = "active" AND p.status = "active" AND m.capability = "vision_understanding"',
            [$modelId]
        );
    }

    private function imageDataUrl(array $asset): string
    {
        $path = (string) ($asset['storage_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $mime = (string) ($asset['mime_type'] ?? 'image/png');
        $binary = file_get_contents($path);
        return is_string($binary) ? 'data:' . $mime . ';base64,' . base64_encode($binary) : '';
    }

    private function visionEndpoint(array $route): string
    {
        return ((string) ($route['protocol'] ?? 'openai')) === 'anthropic' ? '/v1/messages' : '/v1/chat/completions';
    }

    private function visionPayload(array $route, array $asset, string $imageData, string $profile): array
    {
        $instruction = 'Extract OCR text and describe this image for a RAG knowledge base. Return only JSON with keys ocr_text, ai_short_description, ai_detailed_description, ai_objects, ai_tags, quality_warnings.';
        if ($profile === 'ocr_first') {
            $instruction .= ' Prioritize visible text and exact codes.';
        } elseif ($profile === 'technical') {
            $instruction .= ' Prefer technical object names, faults, codes, and observable states.';
        }

        if ((string) ($route['protocol'] ?? 'openai') === 'anthropic') {
            return [
                'model' => $route['external_name'] ?? $route['name'] ?? '',
                'max_tokens' => 800,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $instruction],
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $asset['mime_type'] ?? 'image/png', 'data' => substr($imageData, strpos($imageData, ',') + 1)]],
                    ],
                ]],
            ];
        }

        return [
            'model' => $route['external_name'] ?? $route['name'] ?? '',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $instruction],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageData]],
                ],
            ]],
            'temperature' => 0,
        ];
    }

    private function extractAnnotation(array $response): array
    {
        $text = '';
        if (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
            $text = is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (isset($response['content'][0]['text'])) {
            $text = (string) $response['content'][0]['text'];
        } else {
            $text = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded) && preg_match('/\{.*\}/s', $text, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('RAG_AI_ANNOTATION_FAILED');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(array $asset): array
    {
        $decoded = json_decode((string) ($asset['metadata'] ?? '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function warnings(array $asset): array
    {
        return $this->jsonList($asset['warnings'] ?? '[]');
    }

    /**
     * @return list<string>
     */
    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        return $this->stringList(is_array($decoded) ? $decoded : []);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,|;，；\r\n]+/u', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== '')));
    }
}

