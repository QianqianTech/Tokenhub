<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;

final class RagRetrievalService
{
    public function __construct(
        private readonly ?RagEmbeddingProviderService $embeddingProvider = null,
        private readonly ?RagTextAnalyzerService $textAnalyzer = null,
        private readonly ?RagRetrievalProfileService $retrievalProfile = null,
        private readonly ?\Closure $beforeRecord = null,
        private readonly ?RagRerankService $reranker = null,
        private readonly ?RagVectorConfigService $vectorConfigs = null,
    )
    {
    }

    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function retrieve(array $apiKey, array $payload): array
    {
        $rag = is_array($payload['rag'] ?? null) ? $payload['rag'] : [];
        if (($rag['enabled'] ?? false) !== true) {
            return ['enabled' => false, 'payload' => $payload, 'sources' => []];
        }
        $started = microtime(true);
        $required = (bool) ($rag['required'] ?? false);
        $topK = $this->normalizeTopK($rag['top_k'] ?? 8);
        $queryModality = $this->queryModality($payload, $rag);
        $query = $this->fallbackQuery($payload);
        $datasets = [];
        try {
            $datasets = (new RagPermissionService())->resolveDatasets(
                $apiKey,
                $this->stringList($rag['dataset_ids'] ?? []),
                $this->stringList($rag['dataset_names'] ?? []),
                $this->stringList($rag['knowledge_base_ids'] ?? []),
                'read'
            );
        } catch (Throwable $throwable) {
            HttpAbortException::rethrowIfAbort($throwable);
            if ($required) {
                abort_json('RAG_RETRIEVAL_FAILED', 'RAG retrieval failed', 502);
            }
            App::logger()->warning('rag', 'rag retrieval fallback', ['error' => $throwable->getMessage()]);
            return $this->fallbackResponse($apiKey, $payload, [], $query, $topK, $queryModality, $started);
        }

        if ($datasets === []) {
            if ($required) {
                abort_json('RAG_REQUIRED_NO_CONTEXT', 'No RAG dataset available', 424);
            }
            return $this->fallbackResponse($apiKey, $payload, [], $query, $topK, $queryModality, $started);
        }

        try {
            $query = $this->query($payload);
            $sources = trim($query) === '' ? [] : $this->searchSourcesForDatasets($datasets, $query, $topK, $queryModality);
            $latencyMs = (int) ((microtime(true) - $started) * 1000);
            if ($sources === []) {
                if ($required) {
                    abort_json('RAG_REQUIRED_NO_CONTEXT', 'Required RAG context did not meet retrieval thresholds', 424);
                }
                return $this->fallbackResponse($apiKey, $payload, $datasets, $query, $topK, $queryModality, $started);
            }
            try {
                $retrievalId = $this->record($apiKey, $query, $datasets, $sources, $topK, $latencyMs, false, $queryModality);
            } catch (Throwable $throwable) {
                App::logger()->error('rag', 'rag retrieval log failed', ['error' => $throwable->getMessage()]);
                $retrievalId = null;
            }
            return [
                'enabled' => true,
                'payload' => (new RagPromptInjector())->inject($payload, $sources),
                'sources' => $sources,
                'retrieval_id' => $retrievalId,
                'fallback_used' => false,
            ];
        } catch (Throwable $throwable) {
            HttpAbortException::rethrowIfAbort($throwable);
            if ($required) {
                abort_json('RAG_RETRIEVAL_FAILED', 'RAG retrieval failed', 502);
            }
            App::logger()->warning('rag', 'rag retrieval fallback', ['error' => $throwable->getMessage()]);
            return $this->fallbackResponse($apiKey, $payload, $datasets, $query, $topK, $queryModality, $started);
        }
    }

    /**
     * @param list<array<string, mixed>> $datasets
     * @return list<array<string, mixed>>
     */
    public function searchSourcesForDatasets(array $datasets, string $query, int $topK, string $queryModality = 'text'): array
    {
        $configService = $this->vectorConfigs ?? new RagVectorConfigService();
        $topK = $this->normalizeTopK($topK);
        $candidateK = max($topK * 4, 20);
        $queryProfile = $this->queryProfile($query, $this->synonyms($datasets));
        $rankedLists = [
            'vector' => [],
            'lexical' => $this->lexicalSourcesForDatasets($datasets, $queryProfile, $candidateK, $queryModality),
        ];
        $queryVectors = [];
        foreach ($datasets as $dataset) {
            foreach ($this->retrievalConfigs($configService, $dataset, $queryModality) as $retrieval) {
                $config = $retrieval['config'];
                $modality = $retrieval['modality'];
                $collection = $retrieval['collection'];
                try {
                    // One query embedding per model/config, not per dataset (RAG-031).
                    $vectorKey = $modality . ':' . (string) ($config['id'] ?? $config['config_id'] ?? '') . ':' . (string) ($config['model_name'] ?? '') . ':' . (string) ($config['dimension'] ?? '');
                    $vector = $queryVectors[$vectorKey] ??= ($this->embeddingProvider ?? new RagEmbeddingProviderService())->embed($modality, ['text' => $query, 'modality' => $modality, 'purpose' => 'query'], $config);
                    $hits = $configService->store($config)->search($collection, $vector, $candidateK, ['dataset_id' => $dataset['dataset_id'], 'modality' => $modality]);
                } catch (Throwable $throwable) {
                    App::logger()->warning('rag', 'rag vector retrieval skipped', ['error' => $throwable->getMessage(), 'dataset_id' => $dataset['dataset_id'] ?? null, 'modality' => $modality]);
                    continue;
                }
                foreach ($hits as $hit) {
                    $payloadData = is_array($hit['payload'] ?? null) ? $hit['payload'] : [];
                    $weightedScore = (float) ($hit['score'] ?? 0) + $this->modalityBoost($queryModality, (string) ($payloadData['modality'] ?? $modality));
                    $rankedLists['vector'][] = [
                        'dataset_id' => $payloadData['dataset_id'] ?? $dataset['dataset_id'],
                        'dataset_name' => $payloadData['dataset_name'] ?? $dataset['name'],
                        'kb_id' => $payloadData['kb_id'] ?? $dataset['external_kb_id'] ?? null,
                        'chunk_id' => $payloadData['chunk_id'] ?? null,
                        'title' => $payloadData['title'] ?? '',
                        'score' => $weightedScore,
                        'raw_score' => (float) ($hit['score'] ?? 0),
                        'content' => $payloadData['content'] ?? '',
                        'modality' => $payloadData['modality'] ?? $modality,
                        'image_id' => $payloadData['image_id'] ?? null,
                        'source_uri' => $this->sourceUri($payloadData),
                        'human_annotation' => $payloadData['image_metadata']['human_annotation'] ?? $payloadData['human_annotation'] ?? [],
                        'ai_annotation' => $payloadData['image_metadata']['ai_annotation'] ?? $payloadData['ai_annotation'] ?? [],
                        'labels' => $payloadData['labels'] ?? [],
                        'warnings' => $payloadData['warnings'] ?? [],
                        'retrieval_strategy' => 'vector',
                    ];
                }
            }
        }

        usort($rankedLists['vector'], static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $rankedLists['vector'] = $this->rehydrateVectorHits($rankedLists['vector'], $datasets);
        $sources = $this->rrfFuse($rankedLists);
        $sources = $this->rerankSources($sources, $queryProfile);
        $sources = $this->applyConfidenceThresholds($sources, $datasets, $queryProfile);
        $rerank = $this->reranker ?? new RagRerankService();
        $rerankSettings = $rerank->settings($datasets);
        if ($rerankSettings !== null && count($sources) > 1) {
            $reranked = $rerank->rerank($query, $sources, $rerankSettings);
            if ($reranked !== null) {
                $sources = $reranked;
            }
        }
        $sources = $this->diverseTopK($sources, $topK);
        $sources = $this->expandContext($sources, $datasets);

        return array_values(array_map(static function (array $source, int $index): array {
            $source['source_id'] = 'src_' . ($index + 1);
            return $source;
        }, $sources, array_keys($sources)));
    }

    /**
     * Direct retrieval for tool-style callers such as the MCP server: no chat
     * payload and no prompt injection, but the same dataset permissions,
     * ranking pipeline and rag_retrieval_logs record as gateway retrievals.
     *
     * @param array<string, mixed> $apiKey
     * @param array{knowledge_base_ids?: list<string>, dataset_ids?: list<string>, top_k?: int} $options
     * @return array{retrieval_id: string|null, dataset_count: int, sources: list<array<string, mixed>>}
     */
    public function search(array $apiKey, string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            abort_json('RAG_QUERY_REQUIRED', 'query is required', 422);
        }
        $started = microtime(true);
        $topK = $this->normalizeTopK($options['top_k'] ?? 5);
        $datasets = (new RagPermissionService())->resolveDatasets(
            $apiKey,
            $this->stringList($options['dataset_ids'] ?? []),
            [],
            $this->stringList($options['knowledge_base_ids'] ?? []),
            'read'
        );
        if ($datasets === []) {
            return ['retrieval_id' => null, 'dataset_count' => 0, 'sources' => []];
        }

        $sources = $this->searchSourcesForDatasets($datasets, $query, $topK, 'text');
        try {
            $retrievalId = $this->record($apiKey, $query, $datasets, $sources, $topK, (int) ((microtime(true) - $started) * 1000), $sources === [], 'text');
        } catch (Throwable $throwable) {
            App::logger()->error('rag', 'rag retrieval log failed', ['error' => $throwable->getMessage()]);
            $retrievalId = null;
        }

        return ['retrieval_id' => $retrievalId, 'dataset_count' => count($datasets), 'sources' => $sources];
    }

    public function findRetrieval(string $retrievalId, array $apiKey): array
    {
        $row = App::db()->first('SELECT * FROM rag_retrieval_logs WHERE retrieval_id = ? AND api_key_id = ?', [$retrievalId, $apiKey['id'] ?? 0]);
        if ($row === null) {
            abort_json('RAG_RETRIEVAL_NOT_FOUND', 'RAG retrieval not found', 404);
        }
        return [
            'retrieval_id' => $row['retrieval_id'],
            'request_id' => $row['request_id'],
            'dataset_ids' => json_decode((string) ($row['dataset_ids'] ?? '[]'), true) ?: [],
            'sources' => json_decode((string) ($row['sources'] ?? '[]'), true) ?: [],
            'hit_count' => (int) $row['hit_count'],
            'fallback_used' => (bool) $row['fallback_used'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $datasets
     * @param list<array<string, mixed>> $sources
     */
    private function record(array $apiKey, string $query, array $datasets, array $sources, int $topK, int $latencyMs, bool $fallback, string $queryModality = 'text'): string
    {
        if ($this->beforeRecord !== null) {
            ($this->beforeRecord)();
        }
        $retrievalId = 'rtr_' . bin2hex(random_bytes(12));
        App::db()->execute(
            'INSERT INTO rag_retrieval_logs (retrieval_id, request_id, api_key_id, user_id, department_id, kb_ids, dataset_ids, query, query_modality, top_k, hit_count, max_score, sources, latency_ms, fallback_used) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $retrievalId,
                App::context()->requestId(),
                $apiKey['id'] ?? null,
                $apiKey['user_id'] ?? null,
                $apiKey['department_id'] ?? null,
                json_encode(array_values(array_unique(array_map(static fn (array $d): mixed => $d['external_kb_id'] ?? $d['kb_id'] ?? null, $datasets))), JSON_UNESCAPED_UNICODE),
                json_encode(array_values(array_map(static fn (array $d): mixed => $d['dataset_id'], $datasets)), JSON_UNESCAPED_UNICODE),
                $query,
                $queryModality,
                $topK,
                count($sources),
                $sources[0]['score'] ?? null,
                json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $latencyMs,
                $fallback ? 1 : 0,
            ]
        );
        return $retrievalId;
    }

    /**
     * Fallback logging failures remain observable in the application log while preserving the
     * optional request's identifiable fallback contract with a null retrieval id.
     *
     * @param list<array<string, mixed>> $datasets
     * @return array<string, mixed>
     */
    private function fallbackResponse(array $apiKey, array $payload, array $datasets, string $query, int $topK, string $queryModality, float $started): array
    {
        try {
            $retrievalId = $this->record(
                $apiKey,
                $query,
                $datasets,
                [],
                $topK,
                (int) ((microtime(true) - $started) * 1000),
                true,
                $queryModality
            );
        } catch (Throwable $throwable) {
            App::logger()->error('rag', 'rag retrieval fallback log failed', ['error' => $throwable->getMessage()]);
            $retrievalId = null;
        }

        return [
            'enabled' => true,
            'payload' => $payload,
            'sources' => [],
            'retrieval_id' => $retrievalId,
            'fallback_used' => true,
        ];
    }

    /**
     * Synonym dictionaries from the knowledge bases' retrieval_profile:
     * {"synonyms": {"故障": ["报警", "告警"], "VPN": ["远程接入"]}}. Matching is
     * on the normalised query; both directions must be listed explicitly.
     *
     * @param list<array<string, mixed>> $datasets
     * @return array<string, list<string>>
     */
    private function synonyms(array $datasets): array
    {
        $merged = [];
        foreach ($datasets as $dataset) {
            $profile = $dataset['kb_retrieval_profile'] ?? null;
            $profile = is_array($profile) ? $profile : (is_string($profile) && $profile !== '' ? (json_decode($profile, true) ?: []) : []);
            $synonyms = is_array($profile['synonyms'] ?? null) ? $profile['synonyms'] : [];
            foreach ($synonyms as $term => $alternatives) {
                $key = $this->normalizeText((string) $term);
                foreach ((array) $alternatives as $alternative) {
                    $alternative = $this->normalizeText((string) $alternative);
                    if ($key !== '' && $alternative !== '' && count($merged[$key] ?? []) < 20) {
                        $merged[$key][] = $alternative;
                    }
                }
            }
        }

        return array_map(static fn (array $values): array => array_values(array_unique($values)), $merged);
    }

    /**
     * Small-to-big (RAG-035): a hit from a structured (p2) document is widened
     * with its neighbouring chunks of the same section, within a character
     * budget, so the answer is not cut at a chunk border. Controlled per
     * knowledge base: retrieval_profile.neighbor_chunks (0-2, default 1) and
     * retrieval_profile.expanded_max_chars (default 2400).
     *
     * @param list<array<string, mixed>> $sources
     * @param list<array<string, mixed>> $datasets
     * @return list<array<string, mixed>>
     */
    private function expandContext(array $sources, array $datasets): array
    {
        $settings = [];
        foreach ($datasets as $dataset) {
            $profile = $dataset['kb_retrieval_profile'] ?? null;
            $profile = is_array($profile) ? $profile : (is_string($profile) && $profile !== '' ? (json_decode($profile, true) ?: []) : []);
            $settings[(string) ($dataset['dataset_id'] ?? '')] = [
                'neighbors' => max(0, min(2, (int) ($profile['neighbor_chunks'] ?? 1))),
                'max_chars' => max(400, min(8000, (int) ($profile['expanded_max_chars'] ?? 2400))),
            ];
        }
        $seen = [];
        foreach ($sources as $source) {
            $seen[(string) ($source['chunk_id'] ?? '')] = true;
        }
        foreach ($sources as &$source) {
            $setting = $settings[(string) ($source['dataset_id'] ?? '')] ?? ['neighbors' => 0, 'max_chars' => 2400];
            $sectionKey = (string) ($source['section_key'] ?? '');
            $ordinal = (int) ($source['segment_ordinal'] ?? 0);
            if ($setting['neighbors'] === 0 || $sectionKey === '' || $ordinal <= 0 || ($source['modality'] ?? 'text') !== 'text') {
                continue;
            }
            $chunk = App::db()->first('SELECT document_id FROM rag_chunks WHERE chunk_id = ?', [(string) $source['chunk_id']]);
            if ($chunk === null) {
                continue;
            }
            $low = $ordinal - $setting['neighbors'];
            $high = $ordinal + $setting['neighbors'];
            $neighbors = App::db()->select(
                'SELECT chunk_id, ordinal, content, metadata FROM rag_chunks WHERE document_id = ? AND ordinal BETWEEN ? AND ? AND ordinal <> ? AND enabled = 1 AND lifecycle_status = "active" ORDER BY ordinal',
                [(int) $chunk['document_id'], $low, $high, $ordinal]
            );
            $before = [];
            $after = [];
            $budget = $setting['max_chars'] - mb_strlen((string) ($source['content'] ?? ''));
            $added = [];
            foreach ($neighbors as $neighbor) {
                $metadata = json_decode((string) ($neighbor['metadata'] ?? '{}'), true);
                if (!is_array($metadata) || (string) ($metadata['section_key'] ?? '') !== $sectionKey || isset($seen[(string) $neighbor['chunk_id']])) {
                    continue;
                }
                $text = (string) $neighbor['content'];
                if (mb_strlen($text) > $budget) {
                    continue;
                }
                $budget -= mb_strlen($text);
                $added[] = (string) $neighbor['chunk_id'];
                if ((int) $neighbor['ordinal'] < $ordinal) {
                    $before[] = $text;
                } else {
                    $after[] = $text;
                }
            }
            if ($added !== []) {
                $source['content'] = trim(implode("\n", [...$before, (string) $source['content'], ...$after]));
                $source['expanded_from_chunk_ids'] = $added;
            }
        }
        unset($source);

        return $sources;
    }

    private function fallbackQuery(array $payload): string
    {
        try {
            return $this->query($payload);
        } catch (Throwable) {
            return '[unavailable query]';
        }
    }

    /**
     * Retrieval query (RAG-034): the text of the last *user* message only —
     * never an assistant/tool message, never image data or JSON. Optional
     * rag.query overrides it (e.g. a client-side rewritten question).
     */
    private function query(array $payload): string
    {
        $rag = is_array($payload['rag'] ?? null) ? $payload['rag'] : [];
        if (isset($rag['query']) && is_string($rag['query']) && trim($rag['query']) !== '') {
            return trim($rag['query']);
        }
        if (isset($payload['input']) && is_scalar($payload['input'])) {
            return (string) $payload['input'];
        }
        $messages = $payload['messages'] ?? (is_array($payload['input'] ?? null) ? $payload['input'] : []);
        if (is_array($messages)) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                $message = $messages[$i];
                if (!is_array($message) || (string) ($message['role'] ?? 'user') !== 'user') {
                    continue;
                }
                $text = $this->textOf($message['content'] ?? null);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        if (isset($payload['prompt']) && is_string($payload['prompt'])) {
            return $payload['prompt'];
        }

        return '';
    }

    /** Text parts of a message content (string, OpenAI parts, Anthropic blocks). */
    private function textOf(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && in_array((string) ($part['type'] ?? 'text'), ['text', 'input_text'], true) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @param array<string, mixed> $dataset
     * @return list<array{modality: string, config: array<string, mixed>, collection: string}>
     */
    private function retrievalConfigs(RagVectorConfigService $configService, array $dataset, string $queryModality): array
    {
        $configs = [];
        foreach ($this->modalities($queryModality) as $modality) {
            $config = match ($modality) {
                'image' => $configService->imageConfig($dataset),
                'multimodal' => $configService->multimodalConfig($dataset),
                default => is_array($dataset['_text_config_override'] ?? null) ? $dataset['_text_config_override'] : $configService->textConfig($dataset),
            };
            if ($config === null) {
                continue;
            }
            $collection = (string) ($config['collection_name'] ?? $configService->collection((string) $dataset['dataset_id'], $modality));
            if ($modality === 'text' && !empty($dataset['_collection_override'])) {
                // Evaluation of a candidate index during blue/green reindexing.
                $collection = (string) $dataset['_collection_override'];
            }
            $configs[] = [
                'modality' => $modality,
                'config' => $config,
                'collection' => $collection,
            ];
        }

        return $configs;
    }

    /**
     * @return list<string>
     */
    private function modalities(string $queryModality): array
    {
        return match ($queryModality) {
            'image' => ['image', 'multimodal', 'text'],
            'multimodal' => ['multimodal', 'text'],
            default => ['text'],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $rag
     */
    private function queryModality(array $payload, array $rag): string
    {
        $explicit = (string) ($rag['query_modality'] ?? $rag['modality'] ?? '');
        if (in_array($explicit, ['text', 'image', 'multimodal'], true)) {
            return $explicit;
        }
        $capability = (string) ($rag['model_capability'] ?? $payload['_tokenhub_capability'] ?? '');
        if ($capability === 'vision_understanding') {
            return 'multimodal';
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        return str_contains($encoded, 'image_url') || str_contains($encoded, '"type":"image"') ? 'multimodal' : 'text';
    }

    private function modalityBoost(string $queryModality, string $sourceModality): float
    {
        if ($queryModality === 'image') {
            return match ($sourceModality) {
                'image' => 0.20,
                'multimodal' => 0.15,
                default => 0.0,
            };
        }
        if ($queryModality === 'multimodal') {
            return $sourceModality === 'multimodal' ? 0.20 : 0.0;
        }

        return 0.0;
    }

    /**
     * @param list<array<string, mixed>> $datasets
     * @return list<array<string, mixed>>
     */
    private function lexicalSourcesForDatasets(array $datasets, array $queryProfile, int $limit, string $queryModality): array
    {
        $queryTerms = $queryProfile['terms'];
        if ($queryTerms === []) {
            return [];
        }

        $sources = [];
        $lexicalIndex = new RagLexicalIndexService($this->vectorConfigs);
        foreach ($datasets as $dataset) {
            $datasetId = (string) ($dataset['dataset_id'] ?? '');
            if ($datasetId === '') {
                continue;
            }
            $candidates = $lexicalIndex->candidates($dataset, (string) $queryProfile['expanded_query'], max(50, $limit * 3));
            $candidateFilter = '';
            $params = [$datasetId];
            if ($candidates['chunk_ids'] !== null) {
                if ($candidates['chunk_ids'] === []) {
                    continue;
                }
                $candidateFilter = ' AND c.chunk_id IN (' . implode(',', array_fill(0, count($candidates['chunk_ids']), '?')) . ')';
                array_push($params, ...$candidates['chunk_ids']);
            }
            $rows = App::db()->select(
                'SELECT c.chunk_id, c.title, c.content, c.question, c.answer, c.chunk_type, c.metadata AS chunk_metadata,
                        d.dataset_id AS external_dataset_id, d.name AS dataset_name, k.kb_id AS external_kb_id, doc.title AS document_title,
                        i.image_id, i.purpose, i.short_description, i.detailed_description, i.ocr_text, i.labels, i.warnings, i.metadata AS image_metadata
                 FROM rag_chunks c
                 JOIN rag_documents doc ON doc.id = c.document_id
                 JOIN rag_uploads u ON u.id = doc.upload_id
                 JOIN rag_vector_datasets d ON d.id = COALESCE(c.dataset_id, doc.dataset_id, u.dataset_id)
                 JOIN rag_knowledge_bases k ON k.id = c.kb_id
                 LEFT JOIN rag_image_assets i ON i.id = c.image_asset_id
                 WHERE d.dataset_id = ? AND c.enabled = 1 AND c.lifecycle_status = "active" AND (c.expires_at IS NULL OR c.expires_at > NOW()) AND doc.deleted_at IS NULL' . $candidateFilter
                 . ($candidates['chunk_ids'] === null ? ' ORDER BY c.id DESC LIMIT ' . RagLexicalIndexService::scanLimit() : ''),
                $params
            );
            foreach ($rows as $row) {
                $chunkMetadata = json_decode((string) ($row['chunk_metadata'] ?? '{}'), true) ?: [];
                $imageMetadata = json_decode((string) ($row['image_metadata'] ?? '{}'), true) ?: [];
                $content = trim(implode("\n", array_filter([
                    (string) ($row['title'] ?? ''),
                    (string) ($row['content'] ?? ''),
                    (string) ($row['question'] ?? ''),
                    (string) ($row['answer'] ?? ''),
                    (string) ($row['purpose'] ?? ''),
                    (string) ($row['short_description'] ?? ''),
                    (string) ($row['detailed_description'] ?? ''),
                    (string) ($row['ocr_text'] ?? ''),
                ])));
                $lexicalScore = $this->lexicalScore((string) $queryProfile['expanded_query'], $queryTerms, $content, (string) ($row['title'] ?? ''));
                if ($lexicalScore <= 0.0) {
                    continue;
                }
                $modality = $this->chunkModality((string) ($row['chunk_type'] ?? 'text'));
                $sources[] = [
                    'dataset_id' => $row['external_dataset_id'] ?? $datasetId,
                    'dataset_name' => $row['dataset_name'] ?? $dataset['name'] ?? '',
                    'kb_id' => $row['external_kb_id'] ?? $dataset['external_kb_id'] ?? null,
                    'chunk_id' => $row['chunk_id'] ?? null,
                    'title' => $row['title'] ?? '',
                    'score' => $lexicalScore + $this->modalityBoost($queryModality, $modality),
                    'raw_score' => $lexicalScore,
                    'content' => $row['content'] ?? '',
                    'modality' => $modality,
                    'image_id' => $row['image_id'] ?? null,
                    'source_uri' => $this->sourceUri(['chunk_metadata' => $chunkMetadata, 'image_metadata' => $imageMetadata]),
                    'human_annotation' => $imageMetadata['human_annotation'] ?? [],
                    'ai_annotation' => $imageMetadata['ai_annotation'] ?? [],
                    'labels' => json_decode((string) ($row['labels'] ?? '[]'), true) ?: [],
                    'warnings' => json_decode((string) ($row['warnings'] ?? '[]'), true) ?: [],
                    'retrieval_strategy' => 'lexical',
                    'document_title' => (string) ($row['document_title'] ?? ''),
                    'heading_path' => $chunkMetadata['heading_path'] ?? null,
                    'page' => $chunkMetadata['page'] ?? null,
                    'document_key' => $chunkMetadata['document_key'] ?? null,
                    'section_key' => $chunkMetadata['section_key'] ?? null,
                    'segment_ordinal' => $chunkMetadata['segment_ordinal'] ?? null,
                ];
            }
        }

        usort($sources, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string) ($a['chunk_id'] ?? ''), (string) ($b['chunk_id'] ?? '')));
        return array_slice($sources, 0, max(1, $limit));
    }

    /**
     * Vector hits carry the payload written at index time. The database is the
     * source of truth: hits whose chunk is disabled, expired, superseded,
     * deleted, or no longer in one of the caller's datasets are dropped, and
     * title/content come from the current row (RAG-003).
     *
     * @param list<array<string, mixed>> $hits
     * @param list<array<string, mixed>> $datasets
     * @return list<array<string, mixed>>
     */
    private function rehydrateVectorHits(array $hits, array $datasets): array
    {
        $chunkIds = array_values(array_unique(array_filter(array_map(static fn (array $hit): string => (string) ($hit['chunk_id'] ?? ''), $hits))));
        if ($chunkIds === []) {
            return [];
        }
        $allowed = [];
        foreach ($datasets as $dataset) {
            $allowed[(string) ($dataset['dataset_id'] ?? '')] = true;
        }
        $now = gmdate('Y-m-d H:i:s');
        $rows = App::db()->select(
            'SELECT c.chunk_id, c.chunk_type, c.title, c.content, c.metadata, d.dataset_id AS external_dataset_id, doc.title AS document_title
             FROM rag_chunks c
             JOIN rag_documents doc ON doc.id = c.document_id
             JOIN rag_uploads u ON u.id = doc.upload_id
             JOIN rag_vector_datasets d ON d.id = COALESCE(c.dataset_id, doc.dataset_id, u.dataset_id)
             WHERE c.chunk_id IN (' . implode(',', array_fill(0, count($chunkIds), '?')) . ')
               AND c.enabled = 1 AND c.lifecycle_status = "active"
               AND (c.expires_at IS NULL OR c.expires_at > ?)
               AND doc.deleted_at IS NULL
               AND (doc.expires_at IS NULL OR doc.expires_at > ?)',
            [...$chunkIds, $now, $now]
        );
        $current = [];
        foreach ($rows as $row) {
            if (isset($allowed[(string) $row['external_dataset_id']])) {
                $current[(string) $row['chunk_id']] = $row;
            }
        }
        $kept = [];
        foreach ($hits as $hit) {
            $row = $current[(string) ($hit['chunk_id'] ?? '')] ?? null;
            if ($row === null || (string) $row['external_dataset_id'] !== (string) ($hit['dataset_id'] ?? '')) {
                continue;
            }
            $hit['title'] = (string) ($row['title'] ?? '');
            if (!in_array((string) $row['chunk_type'], ['image', 'image_text'], true) || trim((string) ($row['content'] ?? '')) !== '') {
                $hit['content'] = (string) ($row['content'] ?? '');
            }
            $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
            if (is_array($metadata)) {
                foreach (['heading_path', 'page', 'document_key', 'section_key', 'segment_ordinal'] as $field) {
                    if (isset($metadata[$field]) && $metadata[$field] !== '' && $metadata[$field] !== []) {
                        $hit[$field] = $metadata[$field];
                    }
                }
            }
            $hit['document_title'] = (string) ($row['document_title'] ?? '');
            $kept[] = $hit;
        }

        return $kept;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $rankedLists
     * @return list<array<string, mixed>>
     */
    private function rrfFuse(array $rankedLists): array
    {
        $fused = [];
        $rrfK = 60.0;
        foreach ($rankedLists as $strategy => $sources) {
            foreach ($sources as $rank => $source) {
                $chunkId = (string) ($source['chunk_id'] ?? '');
                if ($chunkId === '') {
                    continue;
                }
                $key = $chunkId . ':' . (string) ($source['modality'] ?? 'text');
                if (!isset($fused[$key])) {
                    $fused[$key] = $source;
                    $fused[$key]['score'] = 0.0;
                    $fused[$key]['rank_signals'] = [];
                }
                $rankValue = $rank + 1;
                $fused[$key]['score'] += 1.0 / ($rrfK + $rankValue);
                $fused[$key]['rank_signals'][$strategy . '_rank'] = $rankValue;
                $fused[$key]['rank_signals'][$strategy . '_score'] = (float) ($source['raw_score'] ?? $source['score'] ?? 0);
                $fused[$key]['rank_signals'][$strategy . '_ranking_score'] = (float) ($source['score'] ?? 0);
            }
        }

        $sources = array_values($fused);
        foreach ($sources as &$source) {
            $source['raw_score'] = (float) ($source['rank_signals']['vector_score'] ?? $source['rank_signals']['lexical_score'] ?? 0);
            $source['rank_signals']['rrf_score'] = round((float) $source['score'], 6);
            $source['retrieval_strategy'] = isset($source['rank_signals']['vector_rank'], $source['rank_signals']['lexical_rank']) ? 'hybrid_rrf' : (isset($source['rank_signals']['lexical_rank']) ? 'lexical' : 'vector');
        }
        unset($source);
        usort($sources, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string) ($a['chunk_id'] ?? ''), (string) ($b['chunk_id'] ?? '')));

        return $sources;
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @param array{original_query: string, expanded_query: string, terms: list<string>, rewrite_terms: list<string>} $queryProfile
     * @return list<array<string, mixed>>
     */
    private function rerankSources(array $sources, array $queryProfile): array
    {
        foreach ($sources as &$source) {
            $rerank = $this->localRerankScore($source, $queryProfile);
            $source['score'] = (float) ($source['score'] ?? 0) + $rerank;
            $source['rank_signals']['rerank_score'] = round($rerank, 6);
            $source['rank_signals']['rewrite_terms'] = $queryProfile['rewrite_terms'];
        }
        unset($source);
        usort($sources, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string) ($a['chunk_id'] ?? ''), (string) ($b['chunk_id'] ?? '')));

        return $sources;
    }

    /**
     * @param array<string, mixed> $source
     * @param array{original_query: string, expanded_query: string, terms: list<string>, rewrite_terms: list<string>} $queryProfile
     */
    private function localRerankScore(array $source, array $queryProfile): float
    {
        $titleTerms = $this->terms((string) ($source['title'] ?? ''));
        $contentTerms = $this->terms((string) ($source['content'] ?? ''));
        $allTerms = array_values(array_unique([...$titleTerms, ...$contentTerms]));
        $matched = array_intersect($queryProfile['terms'], $allTerms);
        $score = count($matched) * 0.08;
        $titleMatches = count(array_intersect($queryProfile['terms'], $titleTerms));
        $score += $titleMatches * 0.12;
        $rewriteMatches = count(array_intersect($queryProfile['rewrite_terms'], $allTerms));
        $score += $rewriteMatches * 0.16;
        $normalizedContent = $this->normalizeText((string) ($source['title'] ?? '') . ' ' . (string) ($source['content'] ?? ''));
        foreach ($queryProfile['phrases'] as $phrase) {
            if (str_contains($normalizedContent, $phrase)) {
                $score += 0.24;
            }
        }

        return $score;
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @return list<array<string, mixed>>
     */
    private function diverseTopK(array $sources, int $topK): array
    {
        $selected = [];
        $skipped = [];
        foreach ($sources as $source) {
            if ($this->isNearDuplicate($source, $selected)) {
                $skipped[] = $source;
                continue;
            }
            $selected[] = $source;
            if (count($selected) >= $topK) {
                return $selected;
            }
        }
        foreach ($skipped as $source) {
            $selected[] = $source;
            if (count($selected) >= $topK) {
                break;
            }
        }

        return array_slice($selected, 0, max(1, $topK));
    }

    /**
     * @param list<array<string, mixed>> $selected
     */
    private function isNearDuplicate(array $candidate, array $selected): bool
    {
        foreach ($selected as $source) {
            if ($this->jaccard($this->terms((string) ($candidate['content'] ?? '')), $this->terms((string) ($source['content'] ?? ''))) >= 0.92) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * @param list<string> $queryTerms
     */
    private function lexicalScore(string $query, array $queryTerms, string $content, string $title): float
    {
        $contentTerms = $this->terms($content);
        if ($contentTerms === []) {
            return 0.0;
        }
        $titleTerms = $this->terms($title);
        $termCounts = array_count_values($contentTerms);
        $score = 0.0;
        foreach ($queryTerms as $term) {
            $count = (int) ($termCounts[$term] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $score += 1.0 + log(1 + $count);
            if (in_array($term, $titleTerms, true)) {
                $score += 0.75;
            }
        }
        $normalizedContent = $this->normalizeText($content);
        $normalizedQuery = $this->normalizeText($query);
        if ($normalizedQuery !== '' && str_contains($normalizedContent, $normalizedQuery)) {
            $score += 6.0;
        }

        return round($score / sqrt(max(1, count($contentTerms))), 6);
    }

    /**
     * @return array{original_query: string, expanded_query: string, terms: list<string>, rewrite_terms: list<string>}
     */
    /**
     * @param array<string, list<string>> $synonyms knowledge-base synonym dictionary (RAG-034)
     */
    private function queryProfile(string $query, array $synonyms = []): array
    {
        $analysis = $this->analyzer()->analyze($query);
        $normalized = $analysis['normalized'];
        $rewriteTerms = $analysis['rewrite_terms'];
        foreach ($synonyms as $term => $alternatives) {
            if ($term !== '' && str_contains($normalized, $term)) {
                foreach ($alternatives as $alternative) {
                    $rewriteTerms[] = $alternative;
                }
            }
        }
        $expanded = $normalized;
        $rewriteMap = [
            'overheat' => ['thermal', 'overload'],
            'overheating' => ['thermal', 'overload'],
            'hot' => ['thermal', 'overload'],
            'err' => ['error', 'alarm'],
            'fault' => ['error', 'alarm'],
            'resetting' => ['reset'],
        ];
        foreach ($analysis['terms'] as $term) {
            if (preg_match('/^e?(\d{2,4})$/', $term, $matches) === 1) {
                $rewriteTerms[] = 'e' . $matches[1];
                continue;
            }
            foreach ($rewriteMap[$term] ?? [] as $rewriteTerm) {
                $rewriteTerms[] = $rewriteTerm;
            }
        }
        $rewriteTerms = array_values(array_unique($rewriteTerms));
        if ($rewriteTerms !== []) {
            $expanded .= ' ' . implode(' ', $rewriteTerms);
        }
        $rewriteParts = [];
        foreach ($rewriteTerms as $rewriteTerm) {
            // A multi-character CJK rewrite ("远程接入") must also match through its bigrams.
            array_push($rewriteParts, ...$this->terms((string) $rewriteTerm));
        }
        $terms = array_values(array_unique([...$analysis['terms'], ...$rewriteTerms, ...$rewriteParts]));

        return [
            'original_query' => $query,
            'expanded_query' => $expanded,
            'terms' => $terms,
            'rewrite_terms' => $rewriteTerms,
            'phrases' => $analysis['phrases'],
            'codes' => $analysis['codes'],
        ];
    }

    /**
     * @return list<string>
     */
    private function importantPhrases(string $query): array
    {
        return $this->analyzer()->analyze($query)['phrases'];
    }

    /**
     * @return list<string>
     */
    private function terms(string $text): array
    {
        return $this->analyzer()->terms($text);
    }

    private function normalizeText(string $text): string
    {
        return $this->analyzer()->normalize($text);
    }

    private function analyzer(): RagTextAnalyzerService
    {
        return $this->textAnalyzer ?? new RagTextAnalyzerService();
    }

    private function profileService(): RagRetrievalProfileService
    {
        return $this->retrievalProfile ?? new RagRetrievalProfileService();
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @param list<array<string, mixed>> $datasets
     * @param array{original_query: string, expanded_query: string, terms: list<string>, rewrite_terms: list<string>, phrases: list<string>, codes: list<string>} $queryProfile
     * @return list<array<string, mixed>>
     */
    private function applyConfidenceThresholds(array $sources, array $datasets, array $queryProfile): array
    {
        $profiles = [];
        foreach ($datasets as $dataset) {
            $datasetId = (string) ($dataset['dataset_id'] ?? '');
            if ($datasetId !== '') {
                $profiles[$datasetId] = $this->profileService()->resolve($dataset['kb_retrieval_profile'] ?? null);
            }
        }

        $accepted = [];
        foreach ($sources as $source) {
            $profile = $profiles[(string) ($source['dataset_id'] ?? '')] ?? $this->profileService()->resolve(null);
            $signals = is_array($source['rank_signals'] ?? null) ? $source['rank_signals'] : [];
            $vectorScore = max(0.0, (float) ($signals['vector_score'] ?? 0.0));
            $lexicalScore = max(0.0, (float) ($signals['lexical_score'] ?? 0.0));
            $lexicalConfidence = min(1.0, 1.0 - exp(-$lexicalScore));
            $rerankScore = max(0.0, (float) ($signals['rerank_score'] ?? 0.0));
            $strategy = (string) ($source['retrieval_strategy'] ?? 'vector');
            $contentAnalysis = $this->analyzer()->analyze((string) ($source['title'] ?? '') . ' ' . (string) ($source['content'] ?? ''));
            $exactPhraseEvidence = array_intersect($queryProfile['phrases'], $contentAnalysis['phrases']) !== [];
            $codeEvidence = array_intersect($queryProfile['codes'], $contentAnalysis['codes']) !== [];
            $hasExactEvidence = $exactPhraseEvidence || $codeEvidence;
            $baseThreshold = match ($strategy) {
                'lexical' => $profile['min_lexical_score'],
                'hybrid_rrf' => $profile['min_hybrid_confidence'],
                default => $profile['min_vector_score'],
            };
            $threshold = $hasExactEvidence ? max(0.0, $baseThreshold - $profile['exact_match_discount']) : $baseThreshold;
            $confidence = match ($strategy) {
                'lexical' => $lexicalConfidence,
                'hybrid_rrf' => 0.55 * $vectorScore + 0.35 * $lexicalConfidence + 0.10 * min(1.0, $rerankScore),
                default => $vectorScore,
            };
            $signals['min_vector_score'] = $profile['min_vector_score'];
            $signals['min_lexical_score'] = $profile['min_lexical_score'];
            $signals['min_hybrid_confidence'] = $profile['min_hybrid_confidence'];
            $signals['exact_match_discount'] = $profile['exact_match_discount'];
            $signals['profile_name'] = $profile['name'];
            $signals['profile_warnings'] = $profile['warnings'];
            $signals['lexical_confidence'] = round($lexicalConfidence, 6);
            $signals['exact_phrase_evidence'] = $exactPhraseEvidence;
            $signals['code_evidence'] = $codeEvidence;
            $signals['confidence_threshold'] = round($threshold, 6);
            $signals['exact_match_discount_applied'] = $hasExactEvidence;
            $signals['threshold_bypassed_for_image'] = false;
            $signals['exact_phrase_match'] = $exactPhraseEvidence;
            $signals['code_match'] = $codeEvidence;
            $signals['threshold'] = round($threshold, 6);
            $signals['threshold_profile'] = $profile['name'];
            $signals['accepted'] = $confidence >= $threshold;
            $source['rank_signals'] = $signals;
            $source['confidence_score'] = round($confidence, 6);

            if ($signals['accepted']) {
                $accepted[] = $source;
            }
        }

        return $accepted;
    }

    private function chunkModality(string $chunkType): string
    {
        return in_array($chunkType, ['image', 'image_text'], true) ? 'image' : 'text';
    }

    /**
     * @param array<string, mixed> $payloadData
     */
    private function sourceUri(array $payloadData): string
    {
        if (isset($payloadData['source_uri']) && is_scalar($payloadData['source_uri'])) {
            return (string) $payloadData['source_uri'];
        }
        foreach (['image_metadata', 'chunk_metadata'] as $key) {
            if (is_array($payloadData[$key] ?? null) && isset($payloadData[$key]['source_uri']) && is_scalar($payloadData[$key]['source_uri'])) {
                return (string) $payloadData[$key]['source_uri'];
            }
        }

        return '';
    }

    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)));
    }

    private function normalizeTopK(mixed $topK): int
    {
        if (is_int($topK)) {
            $value = $topK;
        } elseif (is_float($topK) && is_finite($topK) && floor($topK) === $topK) {
            $value = (int) $topK;
        } elseif (is_string($topK) && preg_match('/^-?\d+$/', trim($topK)) === 1) {
            $value = (int) $topK;
        } else {
            return 8;
        }

        return $value < 1 ? 8 : min(20, $value);
    }
}
