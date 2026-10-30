<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use TokenHub\Support\App;

final class RagChunkingService
{
    /**
     * @param array<string, mixed> $upload
     * @param array<string, mixed> $dataset
     */
    public function createChunks(array $upload, array $dataset): void
    {
        $lockName = 'rag_chunk_upload_' . (int) $upload['id'];
        $lock = App::db()->first('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('RAG_CHUNKING_LOCK_TIMEOUT');
        }
        try {
            $items = App::db()->select('SELECT * FROM rag_upload_preview_items WHERE upload_id = ? AND validation_status IN ("valid","warning") ORDER BY id', [$upload['id']]);
            $chunker = new RagContentChunkerService();
            $structured = new RagStructuredChunkerService();
            $profile = $this->profile($dataset['kb_chunking_profile'] ?? null);
            $effectiveProfile = $chunker->profile($profile);
            $uploadMetadata = json_decode((string) ($upload['metadata'] ?? '{}'), true);
            $uploadMetadata = is_array($uploadMetadata) ? $uploadMetadata : [];
            $eligiblePreviewItemIds = [];
            foreach ($items as $item) {
                $eligiblePreviewItemIds[(string) $item['preview_item_id']] = true;
                $itemMetadata = json_decode((string) ($item['metadata'] ?? '{}'), true);
                if (!is_array($itemMetadata)) {
                    $itemMetadata = [];
                }
                // p2 (structure-aware) for parsed documents, or when the knowledge base opts in.
                $useStructured = (string) ($itemMetadata['chunker'] ?? '') === RagStructuredChunkerService::VERSION
                    || (string) ($profile['version'] ?? '') === 'p2'
                    || str_starts_with((string) ($profile['version'] ?? ''), 'p2-');
                $structuredProfile = $structured->profile(array_merge($profile, is_array($itemMetadata['chunking_profile'] ?? null) ? $itemMetadata['chunking_profile'] : []));
                $documentKey = (string) ($itemMetadata['document_key'] ?? $uploadMetadata['document_key'] ?? '');
                if ($documentKey === '') {
                    $documentKey = 'upload:' . (string) $upload['upload_id'] . ':' . (string) $item['preview_item_id'];
                }
                $documentId = $this->id('doc');
                App::db()->execute(
                    'INSERT INTO rag_documents (document_id, kb_id, dataset_id, upload_id, source_preview_item_id, title, document_key, doc_type, parse_status, index_status, metadata)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "parsed", "indexing", ?)
                     ON DUPLICATE KEY UPDATE dataset_id = VALUES(dataset_id), document_key = VALUES(document_key)',
                    [
                        $documentId,
                        $upload['kb_id'],
                        $dataset['id'] ?? null,
                        $upload['id'],
                        $item['preview_item_id'],
                        $item['title'] ?: 'RAG document',
                        mb_substr($documentKey, 0, 255),
                        $this->docType((string) $item['item_type'], (string) ($itemMetadata['document_format'] ?? '')),
                        json_encode(array_filter([
                            'source_preview_item_id' => $item['preview_item_id'],
                            'document_format' => $itemMetadata['document_format'] ?? null,
                            'pages' => $itemMetadata['pages'] ?? null,
                            'parser' => $itemMetadata['parser'] ?? null,
                        ], static fn ($value): bool => $value !== null), JSON_UNESCAPED_UNICODE),
                    ]
                );
                $document = App::db()->first(
                    'SELECT * FROM rag_documents WHERE upload_id = ? AND source_preview_item_id = ?',
                    [$upload['id'], $item['preview_item_id']]
                );
                $content = (string) ($item['content'] ?? '');
                if (trim($content) === '' && $item['question'] !== null) {
                    $content = '问题：' . $item['question'] . "\n答案：" . $item['answer'];
                }
                $chunkable = in_array((string) $item['item_type'], ['text', 'table_row'], true);
                if ($chunkable && $useStructured) {
                    $segments = array_map(static fn (array $chunk): array => [
                        'content' => $chunk['content'],
                        'start' => 0,
                        'end' => 0,
                        'boundary' => $chunk['boundary'],
                        'extra' => [
                            'heading_path' => $chunk['heading_path'],
                            'page' => $chunk['page'],
                            'section_key' => $chunk['section_key'],
                            'section_ordinal' => $chunk['section_ordinal'],
                            'block_kind' => $chunk['kind'],
                        ],
                    ], $structured->chunk($content, $structuredProfile));
                } elseif ($chunkable) {
                    $segments = $chunker->chunk($content, $profile);
                } else {
                    $segments = [[
                        'content' => $content,
                        'start' => 0,
                        'end' => mb_strlen($content),
                        'boundary' => 'atomic',
                    ]];
                }
                $expectedChunkIds = [];
                foreach ($segments as $index => $segment) {
                    $segmentOrdinal = $index + 1;
                    $segmentContent = (string) $segment['content'];
                    $metadata = array_merge($itemMetadata, [
                        'source_preview_item_id' => $item['preview_item_id'],
                        'segment_ordinal' => $segmentOrdinal,
                        'start' => (int) $segment['start'],
                        'end' => (int) $segment['end'],
                        'boundary' => (string) $segment['boundary'],
                        'chunking_profile' => $chunkable && $useStructured ? $structuredProfile : $effectiveProfile,
                        'document_key' => $documentKey,
                    ], $segment['extra'] ?? []);
                    unset($metadata['parse_warnings']);
                    $chunkId = 'chk_' . substr(hash('sha256', $item['preview_item_id'] . ':' . $segmentOrdinal), 0, 24);
                    $expectedChunkIds[] = $chunkId;
                    $contentHash = hash('sha256', $segmentContent);
                    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE);
                    $existingChunk = App::db()->first('SELECT id, content_hash, metadata, enabled, lifecycle_status FROM rag_chunks WHERE chunk_id = ?', [$chunkId]);
                    App::db()->execute(
                        'INSERT INTO rag_chunks (chunk_id, kb_id, dataset_id, document_id, chunk_type, ordinal, title, content, question, answer, image_asset_id, token_count, metadata, content_hash, enabled, lifecycle_status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, "active")
                         ON DUPLICATE KEY UPDATE dataset_id = VALUES(dataset_id), content = VALUES(content), content_hash = VALUES(content_hash), metadata = VALUES(metadata), token_count = VALUES(token_count), title = VALUES(title), question = VALUES(question), answer = VALUES(answer), enabled = 1, lifecycle_status = "active"',
                        [
                            $chunkId,
                            $upload['kb_id'],
                            $dataset['id'] ?? null,
                            $document['id'],
                            $item['item_type'] === 'image' ? 'image' : ($item['item_type'] === 'qa' ? 'qa' : ($item['item_type'] === 'table_row' ? 'table_row' : 'text')),
                            $segmentOrdinal,
                            $item['title'],
                            $segmentContent,
                            $item['question'],
                            $item['answer'],
                            $item['image_asset_id'],
                            RagStructuredChunkerService::estimateTokens($segmentContent),
                            $metadataJson,
                            $contentHash,
                        ]
                    );
                    if ($existingChunk !== null && $this->chunkChanged($existingChunk, $contentHash, $metadata)) {
                        $this->invalidateChunkEmbedding((int) $existingChunk['id']);
                    }
                }
                $this->reconcileDocumentSegments((int) $document['id'], $expectedChunkIds);
            }
            $documents = App::db()->select('SELECT id, source_preview_item_id FROM rag_documents WHERE upload_id = ?', [$upload['id']]);
            foreach ($documents as $document) {
                $previewItemId = $document['source_preview_item_id'] ?? null;
                if ($previewItemId === null || isset($eligiblePreviewItemIds[(string) $previewItemId])) {
                    continue;
                }
                $this->reconcileDocumentSegments((int) $document['id'], []);
            }
        } finally {
            App::db()->first('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    /** @return array<string, mixed> */
    private function profile(mixed $profile): array
    {
        if (is_array($profile)) {
            return $profile;
        }
        if (!is_string($profile) || $profile === '') {
            return [];
        }

        $decoded = json_decode($profile, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $existingChunk
     * @param array<string, mixed> $metadata
     */
    private function chunkChanged(array $existingChunk, string $contentHash, array $metadata): bool
    {
        $existingMetadata = json_decode((string) ($existingChunk['metadata'] ?? '{}'), true);

        return (string) $existingChunk['content_hash'] !== $contentHash
            || !is_array($existingMetadata)
            || $existingMetadata != $metadata
            || (int) $existingChunk['enabled'] !== 1
            || (string) $existingChunk['lifecycle_status'] !== 'active';
    }

    private function invalidateChunkEmbedding(int $chunkId): void
    {
        App::db()->execute(
            'UPDATE rag_embedding_jobs SET status = "queued", attempts = 0, next_run_at = NULL, dispatched_at = NULL, locked_at = NULL, lock_token = NULL, error_code = NULL, error_message = NULL, processed_items = 0, progress_percent = 0 WHERE chunk_id = ?',
            [$chunkId]
        );
        App::db()->execute(
            'UPDATE rag_vector_records SET index_status = "stale", status = "stale", indexed_at = NULL, deleted_at = NULL WHERE chunk_id = ?',
            [$chunkId]
        );
    }

    /** @param list<string> $expectedChunkIds */
    private function reconcileDocumentSegments(int $documentId, array $expectedChunkIds): void
    {
        $chunks = App::db()->select('SELECT id, chunk_id, enabled, lifecycle_status FROM rag_chunks WHERE document_id = ?', [$documentId]);
        $retired = [];
        foreach ($chunks as $chunk) {
            if (in_array((string) $chunk['chunk_id'], $expectedChunkIds, true)) {
                continue;
            }
            if ((string) $chunk['lifecycle_status'] === 'superseded' && (int) $chunk['enabled'] === 0) {
                continue;
            }
            $retired[] = (int) $chunk['id'];
        }
        // Removes the vectors from the store as well, not only the DB records (RAG-004).
        (new RagChunkLifecycleService())->disable($retired, 'superseded');
    }

    private function docType(string $itemType, string $format): string
    {
        if ($itemType === 'image') {
            return 'image';
        }
        if ($itemType === 'qa') {
            return 'qa';
        }

        return match ($format) {
            'pdf' => 'pdf',
            'docx' => 'word',
            'md' => 'markdown',
            'html' => 'html',
            'xlsx', 'xls', 'csv' => 'excel',
            default => 'plain_text',
        };
    }

    private function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }
}
