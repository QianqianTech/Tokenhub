<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagVectorContractService
{
    private const UUID_NAMESPACE_URL = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $config
     * @param array<string, mixed> $chunk
     * @return array{collection: string, vector_id: string, point_id: string, dimension: int, payload: array<string, mixed>}
     */
    public function forChunk(array $dataset, array $config, array $chunk, string $modality): array
    {
        $vectorId = 'vec_' . $this->safe($modality, 'text') . '_' . (string) ($chunk['chunk_id'] ?? '');
        $payload = [
            'vector_id' => $vectorId,
            'kb_id' => $dataset['external_kb_id'] ?? $dataset['kb_id_external'] ?? null,
            'dataset_id' => $dataset['dataset_id'] ?? null,
            'dataset_name' => $dataset['name'] ?? '',
            'chunk_id' => $chunk['chunk_id'] ?? null,
            'title' => $chunk['title'] ?? '',
            'content' => $chunk['content'] ?? '',
            'modality' => $modality,
        ];

        return [
            'collection' => $this->collection($dataset, $config, $modality),
            'vector_id' => $vectorId,
            'point_id' => $this->pointId($vectorId),
            'dimension' => max(1, (int) ($config['dimension'] ?? 3)),
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $dataset @param array<string, mixed> $config */
    public function collection(array $dataset, array $config, string $modality): string
    {
        $configured = trim((string) ($config['collection_name'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $prefix = (string) App::config()->get('rag.qdrant.collection_prefix', 'tokenhub_rag');
        $datasetId = $this->safe((string) ($dataset['dataset_id'] ?? 'default'), 'default');
        return $prefix . '_' . $datasetId . '_' . $this->safe($modality, 'text');
    }

    public function pointId(string $vectorId): string
    {
        $namespace = hex2bin(str_replace('-', '', self::UUID_NAMESPACE_URL));
        if ($namespace === false) {
            throw new \RuntimeException('Invalid UUID namespace.');
        }
        $hash = sha1($namespace . $vectorId);

        return sprintf(
            '%08s-%04s-%04x-%04x-%012s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }

    private function safe(string $value, string $fallback): string
    {
        return preg_replace('/[^A-Za-z0-9_]+/', '_', $value) ?: $fallback;
    }
}
