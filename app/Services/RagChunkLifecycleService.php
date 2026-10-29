<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Keeps vectors consistent with chunk state changes made outside the upload
 * pipeline (admin edits, disable, supersede) — RAG-004.
 */
final class RagChunkLifecycleService
{
    public function __construct(private readonly ?RagVectorDeletionService $deletion = null)
    {
    }

    /** @param list<int> $chunkIds */
    public function disable(array $chunkIds, string $lifecycle = 'deleted'): int
    {
        $chunkIds = array_values(array_filter(array_map('intval', $chunkIds), static fn (int $id): bool => $id > 0));
        if ($chunkIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($chunkIds), '?'));
        App::db()->execute('UPDATE rag_chunks SET enabled = 0, lifecycle_status = ? WHERE id IN (' . $placeholders . ')', [$lifecycle, ...$chunkIds]);
        App::db()->execute(
            'UPDATE rag_embedding_jobs SET status = "cancelled", locked_at = NULL, lock_token = NULL, dispatched_at = NULL, next_run_at = NULL WHERE chunk_id IN (' . $placeholders . ') AND status IN ("queued","retrying","running")',
            $chunkIds
        );

        return ($this->deletion ?? new RagVectorDeletionService())->deleteVectors($chunkIds);
    }

    /**
     * Called after an admin CRUD update of a chunk row.
     *
     * @param array<string, mixed> $changes columns written by the update
     */
    public function afterAdminUpdate(int $chunkId, array $changes): void
    {
        $chunk = App::db()->first('SELECT id, content, title, question, answer, content_hash, enabled, lifecycle_status FROM rag_chunks WHERE id = ?', [$chunkId]);
        if ($chunk === null) {
            return;
        }
        if ((int) $chunk['enabled'] !== 1 || (string) $chunk['lifecycle_status'] !== 'active') {
            $this->disable([$chunkId], (string) $chunk['lifecycle_status'] === 'active' ? 'deleted' : (string) $chunk['lifecycle_status']);
            return;
        }
        $textChanged = array_intersect(array_keys($changes), ['content', 'title', 'question', 'answer']) !== [];
        if (!$textChanged) {
            return;
        }
        $hash = hash('sha256', (string) $chunk['content']);
        if ($hash === (string) $chunk['content_hash'] && !array_key_exists('title', $changes)) {
            return;
        }
        App::db()->execute('UPDATE rag_chunks SET content_hash = ? WHERE id = ?', [$hash, $chunkId]);
        // Stale vectors stay searchable only through DB rehydration, which serves the new text;
        // the re-queued jobs replace them.
        App::db()->execute('UPDATE rag_vector_records SET index_status = "stale", status = "stale", indexed_at = NULL WHERE chunk_id = ? AND status <> "deleted"', [$chunkId]);
        App::db()->execute(
            'UPDATE rag_embedding_jobs SET status = "queued", attempts = 0, next_run_at = NULL, dispatched_at = NULL, locked_at = NULL, lock_token = NULL, error_code = NULL, error_message = NULL, processed_items = 0, progress_percent = 0 WHERE chunk_id = ?',
            [$chunkId]
        );
        $upload = App::db()->first('SELECT DISTINCT j.upload_id FROM rag_embedding_jobs j WHERE j.chunk_id = ? AND j.execution_backend = "php_provider" LIMIT 1', [$chunkId]);
        if ($upload !== null && !empty($upload['upload_id'])) {
            $uploadRow = App::db()->first('SELECT upload_id FROM rag_uploads WHERE id = ?', [(int) $upload['upload_id']]);
            if ($uploadRow !== null) {
                try {
                    App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), ['type' => 'rag.vectorize', 'upload_id' => (string) $uploadRow['upload_id']]);
                } catch (\Throwable $throwable) {
                    App::logger()->warning('rag', 'rag re-vectorize publish failed; recovery loop will pick the queued jobs up', ['error' => $throwable->getMessage()]);
                }
            }
        }
    }
}
