<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use Throwable;
use TokenHub\Support\App;

final class RagEmbeddingJobService
{
    /** @param null|callable(array<string, mixed>): void $queuePublisher */
    public function __construct(
        private readonly ?RagEmbeddingProviderService $provider = null,
        private readonly mixed $queuePublisher = null,
        private readonly ?RagVectorConfigService $vectorConfigs = null,
    ) {
    }

    /**
     * @param array<string, mixed> $dataset
     */
    public function queueUpload(int $uploadId, array $dataset): int
    {
        $chunks = App::db()->select(
            'SELECT c.*, d.upload_id FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ? AND c.lifecycle_status = "active" ORDER BY c.id',
            [$uploadId]
        );
        $configService = $this->vectorConfigs ?? new RagVectorConfigService();
        $queued = 0;
        foreach ($chunks as $chunk) {
            foreach ($this->jobsForChunk($configService, $dataset, $chunk) as $job) {
                $inserted = App::db()->execute(
                    'INSERT INTO rag_embedding_jobs (job_id, kb_id, dataset_id, upload_id, document_id, chunk_id, vector_config_id, job_type, execution_backend, status, total_items, processed_items, progress_percent)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "queued", 1, 0, 0)
                     ON DUPLICATE KEY UPDATE id = id',
                    [
                        $this->id('job'),
                        $chunk['kb_id'],
                        $dataset['id'],
                        $uploadId,
                        $chunk['document_id'],
                        $chunk['id'],
                        $job['vector_config_id'],
                        $job['job_type'],
                        $job['execution_backend'],
                    ]
                );
                $queued += $inserted;
            }
        }

        App::db()->execute(
            'UPDATE rag_vector_datasets SET chunk_count = (SELECT COUNT(*) FROM rag_chunks WHERE kb_id = ? AND lifecycle_status = "active"), vector_count = (SELECT COUNT(*) FROM rag_vector_records WHERE dataset_id = ? AND index_status = "indexed") WHERE id = ?',
            [$dataset['kb_id'], $dataset['id'], $dataset['id']]
        );

        $providerPending = (int) (App::db()->first(
            'SELECT COUNT(*) AS c FROM rag_embedding_jobs WHERE upload_id = ? AND execution_backend = "php_provider" AND status IN ("queued","retrying")',
            [$uploadId]
        )['c'] ?? 0);
        if ($providerPending > 0) {
            $this->publishUpload($uploadId);
        }

        return $queued;
    }

    /**
     * @param array<string, mixed> $dataset
     */
    public function processUpload(int $uploadId, array $dataset): void
    {
        $leaseSeconds = (int) App::config()->get('rag.embedding.job_lease_seconds', 900);
        App::db()->execute(
            'UPDATE rag_embedding_jobs SET status = "retrying", locked_at = NULL, lock_token = NULL, dispatched_at = NULL, next_run_at = NULL
             WHERE upload_id = ? AND execution_backend = "php_provider" AND status = "running"
               AND (locked_at IS NULL OR TIMESTAMPDIFF(SECOND, locked_at, NOW()) > ?)',
            [$uploadId, $leaseSeconds]
        );

        while (($job = $this->claimProviderJob($uploadId)) !== null) {
            if (!$this->processProviderJobs($uploadId, $dataset, [$job])) {
                break;
            }
        }

        $this->refreshUploadStatus($uploadId, $dataset);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $chunk
     */
    public function indexChunk(RagVectorStoreInterface $store, string $collection, array $config, array $dataset, array $chunk, ?string $modality = null): void
    {
        $modality = $modality ?? ($chunk['chunk_type'] === 'image' ? 'image' : 'text');
        $input = $this->embeddingInput($chunk, $modality);
        $vector = ($this->provider ?? new RagEmbeddingProviderService())->embed($modality, $input, $config);
        $contract = (new RagVectorContractService())->forChunk($dataset, $config, $chunk, $modality);
        $collection = $contract['collection'] ?: $collection;
        $vectorId = $contract['vector_id'];
        $metadata = $this->sourceMetadata($chunk);
        try {
            App::db()->execute(
                'INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, image_id, vector_config_id, modality, dimension, vector_json, content_hash, index_status, status, indexed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "stale", "stale", NULL)
             ON DUPLICATE KEY UPDATE collection_name = VALUES(collection_name), kb_id = VALUES(kb_id), dataset_id = VALUES(dataset_id), chunk_id = VALUES(chunk_id), image_id = VALUES(image_id), vector_config_id = VALUES(vector_config_id), modality = VALUES(modality), dimension = VALUES(dimension), vector_json = VALUES(vector_json), content_hash = VALUES(content_hash), index_status = "stale", status = "stale", indexed_at = NULL, deleted_at = NULL',
                [
                    $vectorId,
                    $collection,
                    $chunk['kb_id'],
                    $dataset['id'],
                    $chunk['id'],
                    $chunk['image_asset_id'] ?: null,
                    $config['id'] ?? null,
                    $modality,
                    count($vector),
                    json_encode($vector, JSON_UNESCAPED_SLASHES),
                    $chunk['content_hash'],
                ]
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException('RAG_VECTOR_PERSISTENCE_UNAVAILABLE', 0, $throwable);
        }
        $store->upsert($collection, $vectorId, $vector, [
            ...$contract['payload'],
            'content' => $input['text'],
            'image_id' => $metadata['image_id'] ?? null,
            'source_uri' => $metadata['source_uri'] ?? '',
            'human_annotation' => $metadata['human_annotation'] ?? [],
            'ai_annotation' => $metadata['ai_annotation'] ?? [],
        ]);
        try {
            App::db()->execute(
                'UPDATE rag_vector_records SET index_status = "indexed", status = "indexed", indexed_at = NOW(), deleted_at = NULL WHERE vector_id = ?',
                [$vectorId]
            );
        } catch (Throwable $throwable) {
            throw new RuntimeException('RAG_VECTOR_PERSISTENCE_UNAVAILABLE', 0, $throwable);
        }
    }

    /**
     * @param array<string, mixed> $dataset
     * @param list<array<string, mixed>> $jobs
     */
    private function processProviderJobs(int $uploadId, array $dataset, array $jobs): bool
    {
        $configService = $this->vectorConfigs ?? new RagVectorConfigService();
        foreach ($jobs as $job) {
            $config = App::db()->first('SELECT * FROM rag_vector_configs WHERE id = ? AND enabled = 1', [(int) ($job['vector_config_id'] ?? 0)]);
            if ($config === null) {
                App::db()->execute('UPDATE rag_embedding_jobs SET status = "failed", error_code = "RAG_MODEL_NOT_CONFIGURED", error_message = "Vector config is missing", locked_at = NULL, lock_token = NULL WHERE id = ? AND lock_token = ?', [$job['id'], $job['lock_token']]);
                continue;
            }
            $modality = match ((string) $job['job_type']) {
                'image_embedding' => 'image',
                'multimodal_embedding' => 'multimodal',
                default => 'text',
            };
            $chunk = [
                ...$job,
                'id' => (int) ($job['internal_chunk_id'] ?? 0),
                'chunk_id' => (string) ($job['external_chunk_id'] ?? ''),
            ];
            try {
                $collection = (new RagVectorContractService())->collection($dataset, $config, $modality);
                $this->indexChunk($configService->store($config), $collection, $config, $dataset, $chunk, $modality);
                App::db()->execute('UPDATE rag_embedding_jobs SET status = "succeeded", processed_items = 1, progress_percent = 100, error_code = NULL, error_message = NULL, locked_at = NULL, lock_token = NULL, next_run_at = NULL WHERE id = ? AND lock_token = ?', [$job['id'], $job['lock_token']]);
            } catch (Throwable $throwable) {
                $message = $throwable->getMessage();
                $errorCode = in_array($message, ['RAG_MODEL_NOT_CONFIGURED', 'RAG_EMBEDDING_INVALID_RESPONSE', 'RAG_EMBEDDING_DIMENSION_MISMATCH', 'RAG_VECTOR_STORE_UNAVAILABLE', 'RAG_VECTOR_STORE_REJECTED', 'RAG_VECTOR_PERSISTENCE_UNAVAILABLE', 'PROVIDER_TIMEOUT', 'PROVIDER_ERROR', 'PROVIDER_REJECTED'], true)
                    ? $message
                    : $this->errorCode($modality);
                $attempts = (int) ($job['attempts'] ?? 1);
                $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 5));
                $retryable = in_array($errorCode, ['PROVIDER_TIMEOUT', 'PROVIDER_ERROR', 'RAG_VECTOR_STORE_UNAVAILABLE', 'RAG_VECTOR_PERSISTENCE_UNAVAILABLE'], true) && $attempts < $maxAttempts;
                $delaySeconds = $retryable ? $this->retryDelaySeconds($attempts) : 0;
                App::db()->execute(
                    'UPDATE rag_embedding_jobs SET status = ?, error_code = ?, error_message = ?, locked_at = NULL, lock_token = NULL, dispatched_at = NULL, next_run_at = IF(? > 0, TIMESTAMPADD(SECOND, ?, NOW()), NULL) WHERE id = ? AND lock_token = ?',
                    [$retryable ? 'retrying' : 'failed', $errorCode, substr($message, 0, 500), $delaySeconds, $delaySeconds, $job['id'], $job['lock_token']]
                );
                if ($retryable) {
                    $this->refreshUploadStatus($uploadId, $dataset);
                    return false;
                }
            }
        }

        $this->refreshUploadStatus($uploadId, $dataset);
        return true;
    }

    private function publishUpload(int $uploadId): void
    {
        $upload = App::db()->first('SELECT upload_id FROM rag_uploads WHERE id = ?', [$uploadId]);
        $message = ['type' => 'rag.vectorize', 'upload_id' => (string) ($upload['upload_id'] ?? '')];
        if (is_callable($this->queuePublisher)) {
            ($this->queuePublisher)($message);
        } else {
            App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), $message);
        }
        App::db()->execute(
            'UPDATE rag_embedding_jobs SET dispatched_at = NOW() WHERE upload_id = ? AND execution_backend = "php_provider" AND status IN ("queued","retrying")',
            [$uploadId]
        );
    }

    /** @return array<string, mixed>|null */
    private function claimProviderJob(int $uploadId): ?array
    {
        $lockToken = bin2hex(random_bytes(16));
        return App::db()->transaction(function ($db) use ($uploadId, $lockToken): ?array {
            $job = $db->first(
                'SELECT j.*, c.id AS internal_chunk_id, c.chunk_id AS external_chunk_id, c.chunk_type, c.title, c.content, c.question, c.answer, c.image_asset_id, c.metadata, c.content_hash
                 FROM rag_embedding_jobs j
                 JOIN rag_chunks c ON c.id = j.chunk_id
                 WHERE j.upload_id = ?
                   AND j.execution_backend = "php_provider"
                   AND j.status IN ("queued","retrying")
                   AND (j.next_run_at IS NULL OR j.next_run_at <= NOW())
                 ORDER BY j.id
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED',
                [$uploadId]
            );
            if ($job === null) {
                return null;
            }
            $db->execute(
                'UPDATE rag_embedding_jobs SET status = "running", attempts = attempts + 1, locked_at = NOW(), lock_token = ?, dispatched_at = NULL, progress_percent = 5 WHERE id = ? AND status IN ("queued","retrying")',
                [$lockToken, $job['id']]
            );
            $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
            $job['status'] = 'running';
            $job['lock_token'] = $lockToken;

            return $job;
        });
    }

    private function retryDelaySeconds(int $attempts): int
    {
        $base = (int) App::config()->get('rag.embedding.retry_base_seconds', 5);
        $exponential = min(900, $base * (2 ** max(0, $attempts - 1)));
        return min(900, $exponential + random_int(0, max(1, $base)));
    }

    /** @param array<string, mixed> $dataset */
    private function refreshUploadStatus(int $uploadId, array $dataset): void
    {
        $summary = App::db()->first(
            'SELECT COUNT(*) AS total_jobs, SUM(status = "succeeded") AS succeeded_jobs, SUM(status IN ("failed","cancelled")) AS failed_jobs, SUM(status IN ("queued","running","retrying")) AS active_jobs FROM rag_embedding_jobs WHERE upload_id = ?',
            [$uploadId]
        ) ?? [];
        $total = (int) ($summary['total_jobs'] ?? 0);
        $succeeded = (int) ($summary['succeeded_jobs'] ?? 0);
        $failed = (int) ($summary['failed_jobs'] ?? 0);
        $active = (int) ($summary['active_jobs'] ?? 0);
        $status = $succeeded === $total && $total > 0 ? 'indexed' : ($active > 0 ? 'processing' : ($succeeded > 0 && $failed > 0 ? 'partial_failed' : 'failed'));
        $progress = $status === 'indexed' ? 100 : ($status === 'processing' ? max(45, min(95, (int) (($succeeded / max(1, $total)) * 100))) : 80);
        App::db()->execute(
            'UPDATE rag_uploads SET vectorize_status = ?, status = ?, processed_items = ?, progress_percent = ?, progress = ?, current_stage = ?, stage = ? WHERE id = ?',
            [$status, $status === 'processing' ? 'vectorizing' : $status, $succeeded, $progress, $progress, $status, $status, $uploadId]
        );
        App::db()->execute(
            'UPDATE rag_vector_datasets SET chunk_count = (SELECT COUNT(*) FROM rag_chunks WHERE kb_id = ? AND lifecycle_status = "active"), vector_count = (SELECT COUNT(*) FROM rag_vector_records WHERE dataset_id = ? AND index_status = "indexed"), last_indexed_at = IF(? = "indexed", NOW(), last_indexed_at) WHERE id = ?',
            [$dataset['kb_id'], $dataset['id'], $status, $dataset['id']]
        );
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $chunk
     * @return list<array{modality: string, job_type: string, vector_config_id: int|null, execution_backend: string, config: array<string, mixed>, collection: string}>
     */
    private function jobsForChunk(RagVectorConfigService $configService, array $dataset, array $chunk): array
    {
        $jobs = [];
        $textConfig = $configService->textConfig($dataset);
        $jobs[] = [
            'modality' => 'text',
            'job_type' => 'text_embedding',
            'vector_config_id' => isset($textConfig['id']) ? (int) $textConfig['id'] : null,
            'execution_backend' => $this->executionBackend($textConfig),
            'config' => $textConfig,
            'collection' => (string) ($textConfig['collection_name'] ?? $configService->collection((string) $dataset['dataset_id'], 'text')),
        ];

        if ($chunk['chunk_type'] === 'image') {
            $imageConfig = $configService->imageConfig($dataset);
            if ($imageConfig !== null) {
                $jobs[] = [
                    'modality' => 'image',
                    'job_type' => 'image_embedding',
                    'vector_config_id' => isset($imageConfig['id']) ? (int) $imageConfig['id'] : null,
                    'execution_backend' => $this->executionBackend($imageConfig),
                    'config' => $imageConfig,
                    'collection' => (string) ($imageConfig['collection_name'] ?? $configService->collection((string) $dataset['dataset_id'], 'image')),
                ];
            }
            $multimodalConfig = $configService->multimodalConfig($dataset);
            if ($multimodalConfig !== null) {
                $jobs[] = [
                    'modality' => 'multimodal',
                    'job_type' => 'multimodal_embedding',
                    'vector_config_id' => isset($multimodalConfig['id']) ? (int) $multimodalConfig['id'] : null,
                    'execution_backend' => $this->executionBackend($multimodalConfig),
                    'config' => $multimodalConfig,
                    'collection' => (string) ($multimodalConfig['collection_name'] ?? $configService->collection((string) $dataset['dataset_id'], 'multimodal')),
                ];
            }
        }

        return $jobs;
    }

    /** @param array<string, mixed> $config */
    private function executionBackend(array $config): string
    {
        return !empty($config['provider_id']) && !empty($config['model_id']) ? 'php_provider' : 'python_local';
    }

    /**
     * @param array<string, mixed> $chunk
     * @return array<string, mixed>
     */
    private function embeddingInput(array $chunk, string $modality): array
    {
        $metadata = $this->sourceMetadata($chunk);
        $parts = [(string) ($chunk['content'] ?? '')];
        foreach (['purpose', 'short_description', 'detailed_description', 'ocr_text'] as $field) {
            if (!empty($metadata[$field])) {
                $parts[] = (string) $metadata[$field];
            }
        }
        $ai = is_array($metadata['ai_annotation'] ?? null) ? $metadata['ai_annotation'] : [];
        foreach (['ocr_text', 'short_description', 'detailed_description'] as $field) {
            if (!empty($ai[$field])) {
                $parts[] = (string) $ai[$field];
            }
        }
        foreach (['objects', 'tags'] as $field) {
            if (is_array($ai[$field] ?? null)) {
                $parts[] = implode(' ', $ai[$field]);
            }
        }

        return [
            'text' => trim(implode("\n", array_filter($parts))),
            'image_path' => (string) ($metadata['storage_path'] ?? ''),
            'image_sha256' => (string) ($metadata['sha256'] ?? ''),
            'modality' => $modality,
        ];
    }

    /**
     * @param array<string, mixed> $chunk
     * @return array<string, mixed>
     */
    private function sourceMetadata(array $chunk): array
    {
        if (empty($chunk['image_asset_id'])) {
            $chunkMetadata = json_decode((string) ($chunk['metadata'] ?? '{}'), true);
            return is_array($chunkMetadata) ? $chunkMetadata : [];
        }

        $asset = App::db()->first('SELECT * FROM rag_image_assets WHERE id = ?', [$chunk['image_asset_id']]);
        if ($asset === null) {
            return [];
        }
        $metadata = json_decode((string) ($asset['metadata'] ?? '{}'), true) ?: [];
        return [
            ...$metadata,
            'image_id' => $asset['image_id'] ?? null,
            'purpose' => $asset['purpose'] ?? '',
            'short_description' => $asset['short_description'] ?? '',
            'detailed_description' => $asset['detailed_description'] ?? '',
            'ocr_text' => $asset['ocr_text'] ?? '',
            'storage_path' => $asset['storage_path'] ?? '',
            'sha256' => $asset['sha256'] ?? '',
        ];
    }

    private function errorCode(string $modality): string
    {
        return match ($modality) {
            'image' => 'RAG_IMAGE_EMBEDDING_FAILED',
            'multimodal' => 'RAG_MULTIMODAL_EMBEDDING_FAILED',
            default => 'RAG_EMBEDDING_FAILED',
        };
    }

    /**
     * @return list<float>
     */
    public function embed(string $text, int $dimension = 3): array
    {
        $dimension = max(1, $dimension);
        $vector = array_fill(0, $dimension, 0.0);
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $tokens = preg_split('/\s+/u', $normalized) ?: [];
        if ($tokens === [] || $tokens === ['']) {
            $tokens = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        foreach ($tokens as $token) {
            $hash = crc32((string) $token);
            $index = abs($hash) % $dimension;
            $vector[$index] += 1.0;
        }
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));
        if ($norm > 0) {
            $vector = array_map(static fn (float $v): float => $v / $norm, $vector);
        }

        return $vector;
    }

    private function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }
}
