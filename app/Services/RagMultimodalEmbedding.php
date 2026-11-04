<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use TokenHub\Services\Media\PathConfinement;

/**
 * Real image / multimodal embeddings (V-10). An image or multimodal vector config whose
 * config_json sets "embedding_api" sends image pixels (and, for "multimodal", the text too)
 * to a multimodal embedding model instead of embedding the image's description text.
 * Text queries are embedded with the same model, so text finds images and images find images.
 *
 *  - "openai_mixed":         POST /v1/embeddings, input [{"text": ...}, {"image": url|data-uri}]
 *                            (Jina jina-clip-v2 and compatible servers)
 *  - "ark_multimodal":       POST {base}/embeddings/multimodal, input [{"type":"text",...},
 *                            {"type":"image_url","image_url":{"url":...}}] (Volcengine Ark,
 *                            doubao-embedding-vision; one fused vector per request)
 *  - "dashscope_multimodal": POST /api/v1/services/embeddings/multimodal-embedding/multimodal-embedding,
 *                            input.contents [{"text"}, {"image"}] (Alibaba Model Studio)
 *  - "text" (default):       previous behaviour, the image's annotations are embedded as text.
 *
 * Optional config_json keys: "endpoint" (absolute URL or path on the provider host),
 * "request_params" (merged into every request; DashScope: into "parameters"),
 * "document_params" / "query_params" (only when indexing / querying, e.g. Jina "task").
 */
final class RagMultimodalEmbedding
{
    public const APIS = ['text', 'openai_mixed', 'ark_multimodal', 'dashscope_multimodal'];
    private const MAX_IMAGE_BYTES = 8388608;

    /** @param array<string, mixed>|null $config */
    public static function api(?array $config): string
    {
        $api = (string) (self::settings($config)['embedding_api'] ?? 'text');

        return in_array($api, self::APIS, true) ? $api : 'text';
    }

    /** True when the config embeds pixels (and therefore also accepts text-to-image queries). */
    public static function isMultimodal(?array $config): bool
    {
        return in_array((string) ($config['modality'] ?? ''), ['image', 'multimodal'], true) && self::api($config) !== 'text';
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $config
     * @param list<array{type: string, value: string}> $parts
     * @return array{endpoint: string, payload: array<string, mixed>}
     */
    public function request(array $route, array $config, array $parts, string $purpose): array
    {
        $api = self::api($config);
        $settings = self::settings($config);
        $model = (string) (($route['external_name'] ?? '') ?: ($route['name'] ?? $config['model_name'] ?? ''));
        $params = [
            ...(is_array($settings['request_params'] ?? null) ? $settings['request_params'] : []),
            ...(is_array($settings[$purpose === 'query' ? 'query_params' : 'document_params'] ?? null) ? $settings[$purpose === 'query' ? 'query_params' : 'document_params'] : []),
        ];
        $payload = match ($api) {
            'ark_multimodal' => [
                'model' => $model,
                'input' => array_map(static fn (array $part): array => $part['type'] === 'image'
                    ? ['type' => 'image_url', 'image_url' => ['url' => $part['value']]]
                    : ['type' => 'text', 'text' => $part['value']], $parts),
                'encoding_format' => 'float',
                ...$params,
            ],
            'dashscope_multimodal' => [
                'model' => $model,
                'input' => ['contents' => array_map(static fn (array $part): array => [$part['type'] => $part['value']], $parts)],
                ...($params === [] ? [] : ['parameters' => $params]),
            ],
            default => [
                'model' => $model,
                'input' => array_map(static fn (array $part): array => [$part['type'] => $part['value']], $parts),
                ...$params,
            ],
        };

        return ['endpoint' => $this->endpoint($api, $route, $settings), 'payload' => $payload];
    }

    /**
     * Vectors in input order (one per part, or one fused vector).
     *
     * @param array<string, mixed>|null $json
     * @return list<list<float>>
     */
    public function vectors(array $config, ?array $json): array
    {
        $json ??= [];
        $items = match (self::api($config)) {
            'dashscope_multimodal' => $json['output']['embeddings'] ?? null,
            'ark_multimodal' => isset($json['data']['embedding']) ? [$json['data']] : ($json['data'] ?? null),
            default => $json['data'] ?? null,
        };
        if (!is_array($items) || $items === []) {
            throw new RuntimeException('RAG_EMBEDDING_INVALID_RESPONSE');
        }
        usort($items, static fn ($a, $b): int => (int) (is_array($a) ? ($a['index'] ?? 0) : 0) <=> (int) (is_array($b) ? ($b['index'] ?? 0) : 0));
        $vectors = [];
        foreach ($items as $item) {
            $vector = is_array($item) ? ($item['embedding'] ?? null) : null;
            if (!is_array($vector) || $vector === []) {
                throw new RuntimeException('RAG_EMBEDDING_INVALID_RESPONSE');
            }
            $vectors[] = array_values(array_map('floatval', $vector));
        }

        return $vectors;
    }

    /**
     * One vector for several parts that came back separately (image + text): the mean of
     * the unit vectors, normalised again.
     *
     * @param list<list<float>> $vectors
     * @return list<float>
     */
    public static function combine(array $vectors): array
    {
        if (count($vectors) === 1) {
            return $vectors[0];
        }
        $size = count($vectors[0]);
        $sum = array_fill(0, $size, 0.0);
        foreach ($vectors as $vector) {
            if (count($vector) !== $size) {
                throw new RuntimeException('RAG_EMBEDDING_DIMENSION_MISMATCH');
            }
            $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector))) ?: 1.0;
            foreach ($vector as $i => $value) {
                $sum[$i] += $value / $norm;
            }
        }
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $sum))) ?: 1.0;

        return array_map(static fn (float $v): float => $v / $norm, $sum);
    }

    /**
     * A stored RAG image as a data URI, downscaled to at most $maxPixels (JPEG, or PNG when
     * the image has transparency). Null when the file is missing or outside upload storage.
     *
     * @param list<string>|null $roots
     */
    public static function imageDataUri(string $path, int $maxPixels = 1048576, ?array $roots = null): ?string
    {
        if ($path === '') {
            return null;
        }
        $resolved = PathConfinement::resolve($path, $roots ?? PathConfinement::ragUploadRoots());
        if ($resolved === null || !is_file($resolved) || filesize($resolved) > 4 * self::MAX_IMAGE_BYTES) {
            return null;
        }
        $bytes = (string) file_get_contents($resolved);
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        [$width, $height] = $info;
        $mime = (string) ($info['mime'] ?? 'image/png');
        $maxPixels = max(65536, $maxPixels);
        if (($width * $height > $maxPixels || strlen($bytes) > self::MAX_IMAGE_BYTES) && function_exists('imagecreatefromstring')) {
            $source = @imagecreatefromstring($bytes);
            if ($source !== false) {
                $scale = min(1.0, sqrt($maxPixels / max(1, $width * $height)));
                $resized = imagescale($source, max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale)));
                if ($resized !== false) {
                    ob_start();
                    $alpha = $mime === 'image/png';
                    if ($alpha) {
                        imagesavealpha($resized, true);
                        imagepng($resized, null, 6);
                    } else {
                        imagejpeg($resized, null, 85);
                    }
                    $bytes = (string) ob_get_clean();
                    $mime = $alpha ? 'image/png' : 'image/jpeg';
                }
            }
        }
        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * Image of a chat request (last user message), for image-to-image retrieval: an http(s)
     * URL or a data URI. OpenAI chat/responses and Anthropic shapes, or rag.query_image.
     *
     * @param array<string, mixed> $payload
     */
    public static function queryImage(array $payload): ?string
    {
        $rag = is_array($payload['rag'] ?? null) ? $payload['rag'] : [];
        if (is_string($rag['query_image'] ?? null) && self::acceptableImage($rag['query_image'])) {
            return $rag['query_image'];
        }
        $messages = $payload['messages'] ?? (is_array($payload['input'] ?? null) ? $payload['input'] : []);
        if (!is_array($messages)) {
            return null;
        }
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? null;
            if (!is_array($message) || (string) ($message['role'] ?? 'user') !== 'user') {
                continue;
            }
            $content = is_array($message['content'] ?? null) ? $message['content'] : [];
            for ($j = count($content) - 1; $j >= 0; $j--) {
                $part = $content[$j] ?? null;
                if (!is_array($part)) {
                    continue;
                }
                $candidate = match ((string) ($part['type'] ?? '')) {
                    'image_url' => is_array($part['image_url'] ?? null) ? ($part['image_url']['url'] ?? null) : ($part['image_url'] ?? null),
                    'input_image' => $part['image_url'] ?? null,
                    'image' => self::anthropicImage($part['source'] ?? null),
                    default => null,
                };
                if (is_string($candidate) && self::acceptableImage($candidate)) {
                    return $candidate;
                }
            }

            return null; // only the latest user message counts
        }

        return null;
    }

    private static function anthropicImage(mixed $source): ?string
    {
        if (!is_array($source)) {
            return null;
        }
        if (($source['type'] ?? '') === 'url') {
            return is_string($source['url'] ?? null) ? $source['url'] : null;
        }
        if (($source['type'] ?? '') === 'base64' && is_string($source['data'] ?? null)) {
            return 'data:' . (string) ($source['media_type'] ?? 'image/png') . ';base64,' . $source['data'];
        }

        return null;
    }

    private static function acceptableImage(string $value): bool
    {
        if (str_starts_with($value, 'data:image/')) {
            return strlen($value) <= (int) (self::MAX_IMAGE_BYTES * 1.4);
        }

        return preg_match('#^https?://[^\s]+$#i', $value) === 1 && strlen($value) <= 4096;
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $settings
     */
    private function endpoint(string $api, array $route, array $settings): string
    {
        $base = rtrim((string) ($route['base_url'] ?? ''), '/');
        $origin = (string) (parse_url($base, PHP_URL_SCHEME) ?: 'https') . '://' . (string) parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
        $override = trim((string) ($settings['endpoint'] ?? ''));
        if ($override !== '') {
            return preg_match('#^https?://#i', $override) === 1 ? $override : $origin . '/' . ltrim($override, '/');
        }

        return match ($api) {
            'ark_multimodal' => str_contains((string) parse_url($base, PHP_URL_PATH), '/api/v3')
                ? $base . '/embeddings/multimodal'
                : $origin . '/api/v3/embeddings/multimodal',
            'dashscope_multimodal' => $origin . '/api/v1/services/embeddings/multimodal-embedding/multimodal-embedding',
            default => '/v1/embeddings',
        };
    }

    /** @param array<string, mixed>|null $config @return array<string, mixed> */
    private static function settings(?array $config): array
    {
        $json = $config['config_json'] ?? null;

        return is_array($json) ? $json : (is_string($json) && $json !== '' ? (json_decode($json, true) ?: []) : []);
    }
}
