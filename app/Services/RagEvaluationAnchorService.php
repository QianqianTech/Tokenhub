<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Stable expectations for evaluation cases (RAG-040). Chunk ids change when a
 * document is re-uploaded or re-chunked; an anchor (document key + a short
 * verbatim passage of the expected chunk) survives that. Anchors are stored in
 * rag_eval_cases.metadata.expected_anchors when a case is created, used as an
 * alternative hit criterion during evaluation, and used by remap() to rewrite
 * expected_chunk_ids after re-indexing.
 */
final class RagEvaluationAnchorService
{
    private const ANCHOR_CHARS = 48;

    /**
     * @param list<string> $chunkIds external chunk ids
     * @return list<array{chunk_id: string, document_key: string, text: string}>
     */
    public function anchorsFor(array $chunkIds): array
    {
        if ($chunkIds === []) {
            return [];
        }
        $rows = App::db()->select(
            'SELECT chunk_id, content, metadata FROM rag_chunks WHERE chunk_id IN (' . implode(',', array_fill(0, count($chunkIds), '?')) . ')',
            array_values($chunkIds)
        );
        $anchors = [];
        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
            $text = $this->passage((string) $row['content']);
            if ($text === '') {
                continue;
            }
            $anchors[] = [
                'chunk_id' => (string) $row['chunk_id'],
                'document_key' => is_array($metadata) ? (string) ($metadata['document_key'] ?? '') : '',
                'text' => $text,
            ];
        }

        return $anchors;
    }

    /**
     * @param array<string, mixed> $source retrieved source (content, document_key)
     * @param list<array<string, mixed>> $anchors
     */
    public function matches(array $source, array $anchors): bool
    {
        if ($anchors === []) {
            return false;
        }
        $content = $this->normalize((string) ($source['content'] ?? ''));
        $documentKey = (string) ($source['document_key'] ?? '');
        foreach ($anchors as $anchor) {
            $text = $this->normalize((string) ($anchor['text'] ?? ''));
            if ($text === '' || !str_contains($content, $text)) {
                continue;
            }
            if ($documentKey !== '' && (string) ($anchor['document_key'] ?? '') !== '' && $documentKey !== (string) $anchor['document_key']) {
                continue;
            }
            return true;
        }

        return false;
    }

    /**
     * Rewrites expected_chunk_ids of cases whose expected chunks are no longer
     * active, by locating each anchor in the active chunks of the case's dataset.
     *
     * @return array{checked: int, remapped: int, unresolved: list<string>}
     */
    public function remap(?int $datasetId = null): array
    {
        $where = 'status IN ("draft","active")' . ($datasetId === null ? '' : ' AND dataset_id = ?');
        $cases = App::db()->select('SELECT * FROM rag_eval_cases WHERE ' . $where . ' ORDER BY id', $datasetId === null ? [] : [$datasetId]);
        $result = ['checked' => 0, 'remapped' => 0, 'unresolved' => []];
        foreach ($cases as $case) {
            $result['checked']++;
            $metadata = json_decode((string) ($case['metadata'] ?? '{}'), true) ?: [];
            $anchors = is_array($metadata['expected_anchors'] ?? null) ? $metadata['expected_anchors'] : [];
            $expected = json_decode((string) ($case['expected_chunk_ids'] ?? '[]'), true) ?: [];
            if ($expected === []) {
                continue;
            }
            $active = App::db()->select(
                'SELECT chunk_id FROM rag_chunks WHERE chunk_id IN (' . implode(',', array_fill(0, count($expected), '?')) . ') AND enabled = 1 AND lifecycle_status = "active"',
                array_values($expected)
            );
            if (count($active) === count($expected)) {
                continue;
            }
            if ($anchors === []) {
                $result['unresolved'][] = (string) $case['case_id'];
                continue;
            }
            $replacement = [];
            foreach ($anchors as $anchor) {
                $text = (string) ($anchor['text'] ?? '');
                if ($text === '') {
                    continue;
                }
                $row = App::db()->first(
                    'SELECT c.chunk_id FROM rag_chunks c JOIN rag_documents doc ON doc.id = c.document_id JOIN rag_vector_datasets dset ON dset.id = COALESCE(c.dataset_id, doc.dataset_id)
                     WHERE dset.id = ? AND c.enabled = 1 AND c.lifecycle_status = "active" AND c.content LIKE ? ORDER BY c.id DESC LIMIT 1',
                    [(int) $case['dataset_id'], '%' . addcslashes($text, '%_\\') . '%']
                );
                if ($row !== null) {
                    $replacement[] = (string) $row['chunk_id'];
                }
            }
            if ($replacement === []) {
                $result['unresolved'][] = (string) $case['case_id'];
                continue;
            }
            $metadata['remapped_from'] = $expected;
            $metadata['remapped_at'] = gmdate('c');
            App::db()->execute('UPDATE rag_eval_cases SET expected_chunk_ids = ?, metadata = ? WHERE id = ?', [
                json_encode(array_values(array_unique($replacement)), JSON_UNESCAPED_UNICODE),
                json_encode($metadata, JSON_UNESCAPED_UNICODE),
                $case['id'],
            ]);
            $result['remapped']++;
        }

        return $result;
    }

    /** A verbatim passage from the body of the chunk (skips the overlap prefix). */
    private function passage(string $content): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $content)), static fn (string $line): bool => mb_strlen($line) >= 8 && !str_starts_with($line, '|')));
        if ($lines === []) {
            $lines = [trim($content)];
        }
        $line = $lines[intdiv(count($lines), 2)];

        return mb_substr($line, 0, self::ANCHOR_CHARS);
    }

    private function normalize(string $text): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower($text)) ?? $text;
    }
}
