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

        $batchSize = max(1, min(64, (int) App::config()->get('rag.embedding.batch_size', 16)));
        while (($jobs = $this->claimProviderJobs($uploadId, $batchSize)) !== []) {
            if (!$this->processProviderJobs($uploadId, $dataset, $jobs)) {
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
    /**
     * Embeds one chunk (one or more inputs: QA chunks also index each similar
     * question) and writes the vectors to the store and rag_vector_records.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $chunk
     * @param array<int, list<float>> $precomputed vectors by input index (batched calls)
     */
    public function indexChunk(RagVectorStoreInterface $store, string $collection, array $config, array $dataset, array $chunk, ?string $modality = null, array $precomputed = []): void
    {
        $modality = $modality ?? ($chunk['chunk_type'] === 'image' ? 'image' : 'text');
        $contract = (new RagVectorContractService())->forChunk($dataset, $config, $chunk, $modality);
        $collection = $contract['collection'] ?: $collection;
        foreach ($this->prepareChunkVectors($config, $dataset, $chunk, $modality, $precomputed) as $prepared) {
            ['vector_id' => $vectorId, 'vector' => $vector, 'input_hash' => $inputHash, 'payload' => $payload, 'lexical_text' => $lexicalText] = $prepared;
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
                        $inputHash,
                    ]
                );
            } catch (Throwable $throwable) {
                throw new RuntimeException('RAG_VECTOR_PERSISTENCE_UNAVAILABLE', 0, $throwable);
            }
            $store->upsert($collection, $vectorId, $vector, $payload);
            if ($store instanceof QdrantVectorStore && $modality === 'text') {
                $store->upsertLexical($collection, $vectorId, $lexicalText, self::lexicalPayload($payload));
            }
            try {
                App::db()->execute(
                    'UPDATE rag_vector_records SET index_status = "indexed", status = "indexed", indexed_at = NOW(), deleted_at = NULL WHERE vector_id = ?',
                    [$vectorId]
                );
            } catch (Throwable $throwable) {
                throw new RuntimeException('RAG_VECTOR_PERSISTENCE_UNAVAILABLE', 0, $throwable);
            }
        }
    }

    /**
     * Vectors and payloads for one chunk without writing anything (used by
     * indexChunk and by blue/green reindexing into a new collection).
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $chunk
     * @param array<int, list<float>> $precomputed
     * @return list<array{vector_id: string, vector: list<float>, input_hash: string, payload: array<string, mixed>, lexical_text: string, reused: bool}>
     */
    public function prepareChunkVectors(array $config, array $dataset, array $chunk, string $modality, array $precomputed = []): array
    {
        $contract = (new RagVectorContractService())->forChunk($dataset, $config, $chunk, $modality);
        $metadata = $this->sourceMetadata($chunk);
        $provider = $this->provider ?? new RagEmbeddingProviderService();
        $prepared = [];
        foreach ($this->embeddingInputs($chunk, $modality) as $index => $input) {
            $vectorId = $contract['vector_id'] . $input['suffix'];
            $inputHash = $this->inputHash($config, $input);
            $reused = $precomputed[$index] ?? $this->reusableVector($config, $inputHash);
            $vector = $reused ?? $provider->embed($modality, $input, $config);
            $prepared[] = [
                'vector_id' => $vectorId,
                'vector' => $vector,
                'input_hash' => $inputHash,
                'payload' => [
                    ...$contract['payload'],
                    'vector_id' => $vectorId,
                    'content' => $input['text'],
                    'document_key' => (string) ($metadata['document_key'] ?? ''),
                    'image_id' => $metadata['image_id'] ?? null,
                    'source_uri' => $metadata['source_uri'] ?? '',
                    'human_annotation' => $metadata['human_annotation'] ?? [],
                    'ai_annotation' => $metadata['ai_annotation'] ?? [],
                ],
                'lexical_text' => (string) ($input['lexical_text'] ?? $input['text']),
                'reused' => $reused !== null && !isset($precomputed[$index]),
            ];
        }

        return $prepared;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function lexicalPayload(array $payload): array
    {
        return [
            'vector_id' => $payload['vector_id'] ?? null,
            'kb_id' => $payload['kb_id'] ?? null,
            'dataset_id' => $payload['dataset_id'] ?? null,
            'chunk_id' => $payload['chunk_id'] ?? null,
            'document_key' => $payload['document_key'] ?? '',
            'modality' => 'text',
        ];
    }

    /**
     * The exact text sent to the embedding model, identified by model and
     * dimension: identical inputs reuse an existing vector instead of calling
     * the provider again (re-uploads, new document versions) — RAG-025/031.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     */
    private function inputHash(array $config, array $input): string
    {
        return hash('sha256', implode("\x1f", [
            (string) ($config['model_id'] ?? $config['model_name'] ?? ''),
            (string) ($config['dimension'] ?? ''),
            (string) ($input['modality'] ?? 'text'),
            (string) ($input['image_sha256'] ?? ''),
            (string) $input['text'],
        ]));
    }

    /**
     * @param array<string, mixed> $config
     * @return list<float>|null
     */
    private function reusableVector(array $config, string $inputHash): ?array
    {
        if (empty($config['id'])) {
            return null;
        }
        $row = App::db()->first(
            'SELECT vector_json FROM rag_vector_records WHERE content_hash = ? AND vector_config_id = ? AND status = "indexed" AND vector_json IS NOT NULL LIMIT 1',
            [$inputHash, (int) $config['id']]
        );
        $vector = $row === null ? null : json_decode((string) $row['vector_json'], true);
        if (!is_array($vector) || $vector === [] || count($vector) !== (int) ($config['dimension'] ?? count($vector))) {
            return null;
        }

        return array_values(array_map('floatval', $vector));
    }

    /**
     * @param array<string, mixed> $dataset
     * @param list<array<string, mixed>> $jobs
     */
    private function processProviderJobs(int $uploadId, array $dataset, array $jobs): bool
    {
        $configService = $this->vectorConfigs ?? new RagVectorConfigService();
        $precomputed = $this->batchEmbed($dataset, $jobs);
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
                $this->indexChunk($configService->store($config), $collection, $config, $dataset, $chunk, $modality, $precomputed[(int) $job['id']] ?? []);
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

    /**
     * One provider call for all text inputs of the claimed jobs that share a
     * vector config (RAG-031). Any failure leaves the map empty for those jobs
     * so they fall back to per-job calls with per-job error handling.
     *
     * @param array<string, mixed> $dataset
     * @param list<array<string, mixed>> $jobs
     * @return array<int, array<int, list<float>>> job id => input index => vector
     */
    private function batchEmbed(array $dataset, array $jobs): array
    {
        $groups = [];
        foreach ($jobs as $job) {
            if ((string) $job['job_type'] !== 'text_embedding' || empty($job['vector_config_id'])) {
                continue;
            }
            $groups[(int) $job['vector_config_id']][] = $job;
        }
        $result = [];
        $provider = $this->provider ?? new RagEmbeddingProviderService();
        foreach ($groups as $configId => $groupJobs) {
            if (count($groupJobs) < 2) {
                continue;
            }
            $config = App::db()->first('SELECT * FROM rag_vector_configs WHERE id = ? AND enabled = 1', [$configId]);
            if ($config === null) {
                continue;
            }
            $texts = [];
            $owners = [];
            foreach ($groupJobs as $job) {
                $chunk = [...$job, 'id' => (int) ($job['internal_chunk_id'] ?? 0), 'chunk_id' => (string) ($job['external_chunk_id'] ?? '')];
                foreach ($this->embeddingInputs($chunk, 'text') as $index => $input) {
                    if ($this->reusableVector($config, $this->inputHash($config, $input)) !== null) {
                        continue;
                    }
                    $texts[] = $input['text'];
                    $owners[] = [(int) $job['id'], $index];
                }
            }
            if (count($texts) < 2) {
                continue;
            }
            try {
                $vectors = $provider->embedMany('text', $texts, $config);
            } catch (Throwable $throwable) {
                App::logger()->warning('rag', 'rag batch embedding fell back to single requests', ['error' => $throwable->getMessage(), 'inputs' => count($texts)]);
                continue;
            }
            foreach ($owners as $position => [$jobId, $index]) {
                if (isset($vectors[$position])) {
                    $result[$jobId][$index] = $vectors[$position];
                }
            }
        }

        return $result;
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

    /** @return list<array<string, mixed>> */
    private function claimProviderJobs(int $uploadId, int $limit): array
    {
        $lockToken = bin2hex(random_bytes(16));
        return App::db()->transaction(function ($db) use ($uploadId, $lockToken, $limit): array {
            $jobs = $db->select(
                'SELECT j.*, c.id AS internal_chunk_id, c.chunk_id AS external_chunk_id, c.chunk_type, c.title, c.content, c.question, c.answer, c.image_asset_id, c.metadata, c.content_hash, doc.title AS document_title
                 FROM rag_embedding_jobs j
                 JOIN rag_chunks c ON c.id = j.chunk_id
                 LEFT JOIN rag_documents doc ON doc.id = c.document_id
                 WHERE j.upload_id = ?
                   AND j.execution_backend = "php_provider"
                   AND j.status IN ("queued","retrying")
                   AND (j.next_run_at IS NULL OR j.next_run_at <= NOW())
                 ORDER BY j.id
                 LIMIT ' . max(1, $limit) . '
                 FOR UPDATE SKIP LOCKED',
                [$uploadId]
            );
            $claimed = [];
            foreach ($jobs as $job) {
                $db->execute(
                    'UPDATE rag_embedding_jobs SET status = "running", attempts = attempts + 1, locked_at = NOW(), lock_token = ?, dispatched_at = NULL, progress_percent = 5 WHERE id = ? AND status IN ("queued","retrying")',
                    [$lockToken, $job['id']]
                );
                $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
                $job['status'] = 'running';
                $job['lock_token'] = $lockToken;
                $claimed[] = $job;
            }

            return $claimed;
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
            'SELECT COUNT(*) AS total_jobs, SUM(status = "succeeded") AS succeeded_jobs, SUM(status IN ("failed","cancelled")) AS failed_jobs, SUM(status IN ("queued","running","retrying")) AS active_jobs FROM rag_embedding_jobs WHERE upload_id = ? AND job_type <> "parse"',
            [$uploadId]
        ) ?? [];
        $total = (int) ($summary['total_jobs'] ?? 0);
        $succeeded = (int) ($summary['succeeded_jobs'] ?? 0);
        $failed = (int) ($summary['failed_jobs'] ?? 0);
        $active = (int) ($summary['active_jobs'] ?? 0);
        $status = $succeeded === $total && $total > 0 ? 'indexed' : ($active > 0 ? 'processing' : ($succeeded > 0 && $failed > 0 ? 'partial_failed' : 'failed'));
        if ($status === 'indexed') {
            $previous = App::db()->first('SELECT vectorize_status FROM rag_uploads WHERE id = ?', [$uploadId]);
            if ((string) ($previous['vectorize_status'] ?? '') !== 'indexed') {
                App::db()->execute('UPDATE rag_documents SET index_status = "indexed" WHERE upload_id = ? AND deleted_at IS NULL', [$uploadId]);
                (new RagDocumentVersionService())->publish($uploadId);
            }
        }
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
     * Texts sent to the embedding model for one chunk (RAG-024):
     *  - text chunks are prefixed with the document title and section path so
     *    a chunk such as "第三条 适用范围" keeps its topic;
     *  - QA chunks embed the question with its answer, plus every similar
     *    question on its own (suffix _q1, _q2 ...), all pointing at the chunk;
     *  - image chunks keep their annotations.
     *
     * @param array<string, mixed> $chunk
     * @return list<array{suffix: string, text: string, lexical_text?: string, image_path: string, image_sha256: string, modality: string}>
     */
    private function embeddingInputs(array $chunk, string $modality): array
    {
        $metadata = $this->sourceMetadata($chunk);
        $content = (string) ($chunk['content'] ?? '');
        $base = ['image_path' => (string) ($metadata['storage_path'] ?? ''), 'image_sha256' => (string) ($metadata['sha256'] ?? ''), 'modality' => $modality];
        if ($modality !== 'text' || !empty($chunk['image_asset_id'])) {
            $parts = [$content];
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

            return [['suffix' => '', 'text' => trim(implode("\n", array_filter($parts))), ...$base]];
        }

        if ((string) ($chunk['chunk_type'] ?? '') === 'qa') {
            $question = trim((string) ($chunk['question'] ?? ''));
            $answer = trim((string) ($chunk['answer'] ?? ''));
            $main = $question !== '' ? '问题：' . $question . "\n答案：" . $answer : $content;
            $inputs = [['suffix' => '', 'text' => $main, ...$base]];
            $similar = $metadata['similar_questions'] ?? [];
            $similar = is_array($similar) ? $similar : (preg_split('/[\n|｜；;]+/u', (string) $similar) ?: []);
            $seen = [mb_strtolower($question) => true];
            foreach ($similar as $alternative) {
                $alternative = trim((string) $alternative);
                $key = mb_strtolower($alternative);
                if ($alternative === '' || isset($seen[$key]) || count($inputs) > 20) {
                    continue;
                }
                $seen[$key] = true;
                $inputs[] = ['suffix' => '_q' . count($inputs), 'text' => '问题：' . $alternative, ...$base];
            }

            return $inputs;
        }

        $context = [];
        $documentTitle = trim((string) ($chunk['document_title'] ?? ''));
        $path = $metadata['heading_path'] ?? [];
        $path = is_array($path) ? array_values(array_filter(array_map('strval', $path), static fn (string $part): bool => trim($part) !== '')) : [];
        if ($documentTitle !== '' && ($path === [] || $path[0] !== $documentTitle)) {
            $context[] = $documentTitle;
        }
        array_push($context, ...$path);
        $title = trim((string) ($chunk['title'] ?? ''));
        if ($context === [] && $title !== '' && !str_contains($content, $title)) {
            $context[] = $title;
        }
        $text = $context === [] ? $content : implode(' > ', $context) . "\n" . $content;

        return [['suffix' => '', 'text' => trim($text), 'lexical_text' => trim($title . "\n" . $text), ...$base]];
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
