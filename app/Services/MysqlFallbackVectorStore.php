<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class MysqlFallbackVectorStore implements RagVectorStoreInterface
{
    public function upsert(string $collection, string $vectorId, array $vector, array $payload): void
    {
        App::db()->execute(
            'UPDATE rag_vector_records SET vector_json = ?, index_status = "indexed", status = "indexed", indexed_at = NOW(), deleted_at = NULL WHERE vector_id = ?',
            [json_encode(array_values($vector), JSON_UNESCAPED_SLASHES), $vectorId]
        );
    }

    public function search(string $collection, array $vector, int $topK, array $filter = []): array
    {
        $where = ['v.index_status = "indexed"', 'v.status = "indexed"', 'c.enabled = 1', 'c.lifecycle_status = "active"', '(c.expires_at IS NULL OR c.expires_at > NOW())'];
        $params = [];
        if (isset($filter['dataset_id'])) {
            $where[] = 'd.dataset_id = ?';
            $params[] = $filter['dataset_id'];
        }
        if (isset($filter['kb_id'])) {
            $where[] = 'k.kb_id = ?';
            $params[] = $filter['kb_id'];
        }
        if (isset($filter['modality'])) {
            $where[] = 'v.modality = ?';
            $params[] = $filter['modality'];
        }

        $rows = App::db()->select(
            'SELECT v.vector_id, v.vector_json, v.modality, v.dataset_id, v.chunk_id, c.chunk_id AS external_chunk_id, c.title, c.content, c.question, c.answer, c.image_asset_id, c.metadata AS chunk_metadata, d.dataset_id AS external_dataset_id, d.name AS dataset_name, k.kb_id AS external_kb_id,
                    i.image_id, i.thumbnail_path, i.purpose, i.short_description, i.detailed_description, i.ocr_text, i.labels, i.warnings, i.metadata AS image_metadata
             FROM rag_vector_records v
             JOIN rag_chunks c ON c.id = v.chunk_id
             JOIN rag_vector_datasets d ON d.id = v.dataset_id
             JOIN rag_knowledge_bases k ON k.id = v.kb_id
             LEFT JOIN rag_image_assets i ON i.id = c.image_asset_id
             WHERE ' . implode(' AND ', $where),
            $params
        );

        $hits = [];
        foreach ($rows as $row) {
            $candidate = json_decode((string) ($row['vector_json'] ?? '[]'), true);
            if (!is_array($candidate)) {
                continue;
            }
            $score = $this->cosine($vector, array_map('floatval', $candidate));
            $hits[] = [
                'vector_id' => (string) $row['vector_id'],
                'score' => $score,
                'payload' => [
                    'dataset_id' => $row['external_dataset_id'],
                    'dataset_name' => $row['dataset_name'],
                    'kb_id' => $row['external_kb_id'],
                    'chunk_id' => $row['external_chunk_id'],
                    'title' => $row['title'],
                    'content' => $row['content'],
                    'question' => $row['question'],
                    'answer' => $row['answer'],
                    'modality' => $row['modality'],
                    'image_id' => $row['image_id'] ?? null,
                    'thumbnail_path' => $row['thumbnail_path'] ?? null,
                    'purpose' => $row['purpose'] ?? '',
                    'short_description' => $row['short_description'] ?? '',
                    'detailed_description' => $row['detailed_description'] ?? '',
                    'ocr_text' => $row['ocr_text'] ?? '',
                    'labels' => json_decode((string) ($row['labels'] ?? '[]'), true) ?: [],
                    'warnings' => json_decode((string) ($row['warnings'] ?? '[]'), true) ?: [],
                    'chunk_metadata' => json_decode((string) ($row['chunk_metadata'] ?? '{}'), true) ?: [],
                    'image_metadata' => json_decode((string) ($row['image_metadata'] ?? '{}'), true) ?: [],
                ],
            ];
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($hits, 0, max(1, $topK));
    }

    public function delete(string $collection, array $vectorIds): void
    {
        foreach ($vectorIds as $vectorId) {
            App::db()->execute('UPDATE rag_vector_records SET index_status = "deleted", status = "deleted", deleted_at = NOW() WHERE vector_id = ?', [$vectorId]);
        }
    }

    public function healthy(): bool
    {
        return true;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $max = min(count($a), count($b));
        for ($i = 0; $i < $max; $i++) {
            $dot += (float) $a[$i] * (float) $b[$i];
            $normA += (float) $a[$i] * (float) $a[$i];
            $normB += (float) $b[$i] * (float) $b[$i];
        }
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
