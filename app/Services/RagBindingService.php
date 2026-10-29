<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

/**
 * Writes RAG grants. A knowledge-base grant and a dataset grant are separate
 * rows: granting one dataset no longer grants the whole knowledge base, and a
 * knowledge-base grant reaches datasets through acl_mode = inherit (see
 * RagAccessResolver) instead of being copied onto every dataset.
 */
final class RagBindingService
{
    public const SUBJECT_TYPES = ['api_key', 'user', 'department'];
    public const PERMISSIONS = ['read', 'write', 'admin'];

    /**
     * @param array{include_descendants?: bool|int, expires_at?: string|null, is_default?: bool|int, priority?: int, granted_by_user_id?: int|null} $options
     */
    public function grantKnowledgeBase(int $kbId, string $subjectType, int $subjectId, string $permission = 'read', array $options = []): void
    {
        $this->assertBinding($subjectType, $subjectId, $permission);
        if (App::db()->first('SELECT id FROM rag_knowledge_bases WHERE id = ? AND deleted_at IS NULL', [$kbId]) === null) {
            abort_json('RAG_KB_NOT_FOUND', 'Knowledge base not found', 404);
        }
        $this->upsert('rag_knowledge_base_bindings', 'kb_id', $kbId, $subjectType, $subjectId, $permission, $options);
    }

    /**
     * @param array{include_descendants?: bool|int, expires_at?: string|null, is_default?: bool|int, priority?: int, granted_by_user_id?: int|null} $options
     */
    public function grantDataset(int $datasetId, string $subjectType, int $subjectId, string $permission = 'read', array $options = []): void
    {
        $this->assertBinding($subjectType, $subjectId, $permission);
        if (App::db()->first('SELECT id FROM rag_vector_datasets WHERE id = ? AND deleted_at IS NULL', [$datasetId]) === null) {
            abort_json('RAG_DATASET_NOT_FOUND', 'Vector dataset not found', 404);
        }
        $this->upsert('rag_dataset_bindings', 'dataset_id', $datasetId, $subjectType, $subjectId, $permission, $options);
    }

    public function revokeKnowledgeBase(int $kbId, string $subjectType, int $subjectId): int
    {
        return App::db()->execute('DELETE FROM rag_knowledge_base_bindings WHERE kb_id = ? AND subject_type = ? AND subject_id = ?', [$kbId, $subjectType, $subjectId]);
    }

    public function revokeDataset(int $datasetId, string $subjectType, int $subjectId): int
    {
        return App::db()->execute('DELETE FROM rag_dataset_bindings WHERE dataset_id = ? AND subject_type = ? AND subject_id = ?', [$datasetId, $subjectType, $subjectId]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function upsert(string $table, string $column, int $targetId, string $subjectType, int $subjectId, string $permission, array $options): void
    {
        $existing = App::db()->first(
            "SELECT id FROM {$table} WHERE {$column} = ? AND subject_type = ? AND subject_id = ?",
            [$targetId, $subjectType, $subjectId]
        );
        $fields = ['permission' => $permission];
        foreach (['include_descendants', 'is_default'] as $flag) {
            if (array_key_exists($flag, $options)) {
                $fields[$flag] = !empty($options[$flag]) ? 1 : 0;
            }
        }
        if (array_key_exists('expires_at', $options)) {
            $fields['expires_at'] = $this->expiresAt($options['expires_at']);
        }
        if (array_key_exists('granted_by_user_id', $options)) {
            $fields['granted_by_user_id'] = $options['granted_by_user_id'] === null ? null : (int) $options['granted_by_user_id'];
        }
        if ($table === 'rag_dataset_bindings' && array_key_exists('priority', $options)) {
            $fields['priority'] = max(0, (int) $options['priority']);
        }
        if ($existing === null) {
            $columns = [$column, 'subject_type', 'subject_id', ...array_keys($fields)];
            App::db()->execute(
                sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))),
                [$targetId, $subjectType, $subjectId, ...array_values($fields)]
            );
            return;
        }
        $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", array_keys($fields)));
        App::db()->execute("UPDATE {$table} SET {$sets} WHERE id = ?", [...array_values($fields), $existing['id']]);
    }

    private function expiresAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            abort_json('RAG_BINDING_INVALID', 'expires_at must be a valid date-time', 422);
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function assertBinding(string $subjectType, int $subjectId, string $permission): void
    {
        if (!in_array($subjectType, self::SUBJECT_TYPES, true) || $subjectId <= 0 || !in_array($permission, self::PERMISSIONS, true)) {
            abort_json('RAG_BINDING_INVALID', 'Invalid RAG binding', 422);
        }
    }
}
