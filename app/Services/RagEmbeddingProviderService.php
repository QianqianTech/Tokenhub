<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use TokenHub\Support\App;

final class RagEmbeddingProviderService
{
    /**
     * @param null|callable(string, array<string, mixed>, array<string, mixed>): list<float> $embedder
     * @param null|callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed> $providerRequester
     */
    /** @param list<string>|null $imageRoots directories stored images may come from (tests); null = RAG upload storage */
    public function __construct(
        private readonly mixed $embedder = null,
        private readonly mixed $providerRequester = null,
        private readonly ?RagEmbeddingMeter $meter = null,
        private readonly ?array $imageRoots = null,
    )
    {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $config
     * @return list<float>
     */
    public function embed(string $modality, array $input, array $config): array
    {
        if (is_callable($this->embedder)) {
            $vector = ($this->embedder)($modality, $input, $config);
            if (!is_array($vector) || $vector === []) {
                throw new RuntimeException('RAG_EMBEDDING_FAILED');
            }

            return array_values(array_map('floatval', $vector));
        }

        if (!empty($config['provider_id']) && !empty($config['model_id'])) {
            if ($modality !== 'text' && RagMultimodalEmbedding::isMultimodal([...$config, 'modality' => $config['modality'] ?? $modality])) {
                return $this->multimodalEmbedding($modality, $input, $config);
            }
            return $this->providerEmbedding($input, $config);
        }

        if (!$this->allowsLocalFallback($config)) {
            throw new RuntimeException('RAG_MODEL_NOT_CONFIGURED');
        }

        $dimension = (int) ($config['dimension'] ?? 3);
        $text = (string) ($input['text'] ?? '');
        if ($modality !== 'text') {
            $text .= ' ' . (string) ($input['image_sha256'] ?? '') . ' ' . (string) ($input['image_path'] ?? '');
        }

        return (new RagEmbeddingJobService($this))->embed($text, $dimension);
    }

    /**
     * Embeds several texts with as few provider calls as possible (OpenAI
     * compatible /v1/embeddings accepts an input array). Splits by the vector
     * config's batch_size. Returns vectors in input order.
     *
     * @param list<string> $texts
     * @param array<string, mixed> $config
     * @return list<list<float>>
     */
    public function embedMany(string $modality, array $texts, array $config): array
    {
        if ($texts === []) {
            return [];
        }
        if (is_callable($this->embedder) || $modality !== 'text' || empty($config['provider_id']) || empty($config['model_id'])) {
            return array_map(fn (string $text): array => $this->embed($modality, ['text' => $text, 'modality' => $modality], $config), $texts);
        }
        $batchSize = max(1, min(256, (int) ($config['batch_size'] ?? 32)));
        $vectors = [];
        foreach (array_chunk($texts, $batchSize) as $batch) {
            array_push($vectors, ...$this->providerEmbeddings($batch, $config));
        }

        return $vectors;
    }

    /**
     * @param list<string> $texts
     * @param array<string, mixed> $config
     * @return list<list<float>>
     */
    private function providerEmbeddings(array $texts, array $config): array
    {
        $route = $this->route($config);
        $payload = ['model' => (string) (($route['external_name'] ?? '') ?: ($route['name'] ?? $config['model_name'] ?? '')), 'input' => array_values(array_map(fn (string $text): string => $this->withInstruction($text, 'document', $config), $texts))];
        $response = $this->send($route, $payload);
        $data = $response['json']['data'] ?? null;
        if (!is_array($data) || count($data) !== count($texts)) {
            throw new RuntimeException('RAG_EMBEDDING_INVALID_RESPONSE');
        }
        usort($data, static fn (array $a, array $b): int => (int) ($a['index'] ?? 0) <=> (int) ($b['index'] ?? 0));

        return array_map(fn (array $item): array => $this->vector($item['embedding'] ?? null, $config), $data);
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function route(array $config): array
    {
        $route = App::db()->first(
            'SELECT m.*, m.id AS model_id, p.id AS provider_id, p.protocol, p.base_url, p.encrypted_secret, p.default_headers, p.timeout_ms, p.retry_count
             FROM models m
             JOIN providers p ON p.id = m.provider_id
             WHERE m.id = ? AND p.id = ? AND m.capability = "embedding" AND m.status = "active" AND p.status = "active"
             LIMIT 1',
            [(int) $config['model_id'], (int) $config['provider_id']]
        );
        if ($route === null) {
            throw new RuntimeException('RAG_MODEL_NOT_CONFIGURED');
        }

        return $route;
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(array $route, array $payload, string $endpoint = '/v1/embeddings'): array
    {
        $attemptLimit = max(1, (int) ($route['retry_count'] ?? 1));
        $response = ['ok' => false, 'error_code' => 'PROVIDER_ERROR'];
        $started = microtime(true);
        for ($attempt = 1; $attempt <= $attemptLimit; $attempt++) {
            $response = is_callable($this->providerRequester)
                ? ($this->providerRequester)($route, $endpoint, $payload)
                : (new ProviderHttpClient())->request($route, $endpoint, $payload);
            if (($response['ok'] ?? false) === true || ($response['error_code'] ?? null) === 'PROVIDER_REJECTED') {
                break;
            }
            if ($attempt < $attemptLimit) {
                $retryAfterMs = max(0, (int) ($response['retry_after_ms'] ?? 0));
                $backoffMs = $retryAfterMs > 0 ? $retryAfterMs : min(2000, 100 * (2 ** ($attempt - 1)) + random_int(0, 100));
                usleep($backoffMs * 1000);
            }
        }
        ($this->meter ?? new RagEmbeddingMeter())->record($route, $payload, $response, $started);
        if (($response['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($response['error_code'] ?? 'RAG_EMBEDDING_FAILED'));
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<float>
     */
    private function vector(mixed $vector, array $config): array
    {
        if (!is_array($vector) || $vector === []) {
            throw new RuntimeException('RAG_EMBEDDING_INVALID_RESPONSE');
        }
        $normalized = [];
        foreach ($vector as $value) {
            if (!is_numeric($value) || !is_finite((float) $value)) {
                throw new RuntimeException('RAG_EMBEDDING_INVALID_RESPONSE');
            }
            $normalized[] = (float) $value;
        }
        $dimension = max(1, (int) ($config['dimension'] ?? count($normalized)));
        if (count($normalized) !== $dimension) {
            throw new RuntimeException('RAG_EMBEDDING_DIMENSION_MISMATCH');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $config
     * @return list<float>
     */
    private function providerEmbedding(array $input, array $config): array
    {
        $route = $this->route($config);
        $payload = [
            'model' => (string) (($route['external_name'] ?? '') ?: ($route['name'] ?? $config['model_name'] ?? '')),
            'input' => $this->withInstruction((string) ($input['text'] ?? ''), (string) ($input['purpose'] ?? 'document'), $config),
        ];
        $response = $this->send($route, $payload);

        return $this->vector($response['json']['data'][0]['embedding'] ?? null, $config);
    }

    /**
     * Image / multimodal vector from a multimodal embedding model (V-10). Indexing sends the
     * stored image (input.image_path) and, for "multimodal", the annotation text as well;
     * queries send the query image (input.image) and/or the query text. Without any image the
     * text alone is embedded with the same model, which is how text finds images.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $config
     * @return list<float>
     */
    private function multimodalEmbedding(string $modality, array $input, array $config): array
    {
        $purpose = (string) ($input['purpose'] ?? 'document');
        $image = is_string($input['image'] ?? null) && $input['image'] !== '' ? (string) $input['image'] : null;
        if ($image === null && !empty($input['image_path'])) {
            $image = RagMultimodalEmbedding::imageDataUri((string) $input['image_path'], (int) ($config['image_max_pixels'] ?? 0) ?: 1048576, $this->imageRoots);
            if ($image === null) {
                App::logger()->warning('rag', 'rag image not readable for multimodal embedding; using its text', ['image_path' => $input['image_path']]);
            }
        }
        $text = trim((string) ($input['text'] ?? ''));
        $parts = [];
        if ($image !== null) {
            $parts[] = ['type' => 'image', 'value' => $image];
        }
        if ($text !== '' && ($image === null || $modality === 'multimodal')) {
            $parts[] = ['type' => 'text', 'value' => $this->withInstruction($text, $purpose, $config)];
        }
        if ($parts === []) {
            throw new RuntimeException('RAG_EMBEDDING_INVALID_INPUT');
        }
        $route = $this->route($config);
        $multimodal = new RagMultimodalEmbedding();
        ['endpoint' => $endpoint, 'payload' => $payload] = $multimodal->request($route, $config, $parts, $purpose);
        $response = $this->send($route, $payload, $endpoint);

        return $this->vector(RagMultimodalEmbedding::combine($multimodal->vectors($config, $response['json'] ?? null)), $config);
    }

    /**
     * Some embedding models expect an instruction/prefix on queries (e5:
     * "query: ", bge: "为这个句子生成表示以用于检索相关文章：", Qwen3-Embedding:
     * "Instruct: ...\nQuery: "). Configured per vector config in config_json:
     * {"query_prefix": "...", "document_prefix": "..."}.
     *
     * @param array<string, mixed> $config
     */
    private function withInstruction(string $text, string $purpose, array $config): string
    {
        $json = $config['config_json'] ?? null;
        $settings = is_array($json) ? $json : (is_string($json) && $json !== '' ? (json_decode($json, true) ?: []) : []);
        $prefix = (string) ($settings[$purpose === 'query' ? 'query_prefix' : 'document_prefix'] ?? '');

        return $prefix === '' ? $text : $prefix . $text;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function allowsLocalFallback(array $config): bool
    {
        if (($config['allow_local_fallback'] ?? false) === true) {
            return true;
        }

        $configJson = $config['config_json'] ?? null;
        if (is_string($configJson) && $configJson !== '') {
            $decoded = json_decode($configJson, true);
            if (is_array($decoded) && ($decoded['allow_local_fallback'] ?? false) === true) {
                return true;
            }
        } elseif (is_array($configJson) && ($configJson['allow_local_fallback'] ?? false) === true) {
            return true;
        }

        return (bool) App::config()->get('rag.embedding.allow_local_fallback', false);
    }
}
