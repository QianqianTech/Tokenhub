<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class AgentManagementRagService
{
    public function knowledgeBases(array $apiKey, array $query): array
    {
        return $this->list('rag_knowledge_bases', $this->departmentId($apiKey), $query);
    }

    public function createKnowledgeBase(array $apiKey, array $data): array
    {
        $departmentId = $this->departmentId($apiKey);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Knowledge base name is required', 422);
        }
        $ownerUserId = array_key_exists('owner_user_id', $data)
            ? (int) $data['owner_user_id']
            : (int) ($apiKey['user_id'] ?? 0);
        $owner = App::db()->first(
            'SELECT id FROM users WHERE id = ? AND department_id = ? AND status = "active"',
            [$ownerUserId, $departmentId]
        );
        if ($ownerUserId <= 0 || $owner === null) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Knowledge base owner must be an active user in the API key department', 422);
        }
        $kbId = 'kb_' . bin2hex(random_bytes(12));
        App::db()->execute(
            'INSERT INTO rag_knowledge_bases (kb_id, name, description, owner_user_id, department_id, visibility, status, chunking_profile, retrieval_profile) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$kbId, $name, $data['description'] ?? null, $ownerUserId, $departmentId, $this->visibility($data['visibility'] ?? 'department'), $this->kbStatus($data['status'] ?? 'active'), $this->json($data['chunking_profile'] ?? null), $this->json($data['retrieval_profile'] ?? null)]
        );
        $id = (int) App::db()->pdo()->lastInsertId();
        $this->grantApiKeyBinding('rag_knowledge_base_bindings', 'kb_id', $id, $apiKey);
        (new AuditService())->write('agent_management.rag_kb.create', 'rag_knowledge_bases', $id, ['department_id' => $departmentId, 'kb_id' => $kbId]);
        return $this->knowledgeBase($id, $apiKey);
    }

    public function updateKnowledgeBase(int $id, array $apiKey, array $data): array
    {
        $this->knowledgeBase($id, $apiKey);
        $allowed = ['name', 'description', 'visibility', 'status', 'chunking_profile', 'retrieval_profile'];
        $updates = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) continue;
            $updates[$field] = match ($field) {
                'visibility' => $this->visibility($data[$field]),
                'status' => $this->kbStatus($data[$field]),
                'chunking_profile', 'retrieval_profile' => $this->json($data[$field]),
                default => $data[$field],
            };
        }
        $this->update('rag_knowledge_bases', $id, $updates, 'agent_management.rag_kb.update');
        return $this->knowledgeBase($id, $apiKey);
    }

    public function deleteKnowledgeBase(int $id, array $apiKey): array
    {
        $this->knowledgeBase($id, $apiKey);
        App::db()->execute('UPDATE rag_knowledge_bases SET status = "disabled", deleted_at = NOW() WHERE id = ?', [$id]);
        (new AuditService())->write('agent_management.rag_kb.delete', 'rag_knowledge_bases', $id);
        return ['id' => $id, 'deleted' => true];
    }

    public function datasets(array $apiKey, array $query): array
    {
        $departmentId = $this->departmentId($apiKey);
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $rows = App::db()->select('SELECT d.* FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE k.department_id = ? AND k.deleted_at IS NULL AND d.deleted_at IS NULL ORDER BY d.id DESC LIMIT ? OFFSET ?', [$departmentId, $limit, $offset]);
        $total = (int) (App::db()->first('SELECT COUNT(*) AS total FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE k.department_id = ? AND k.deleted_at IS NULL AND d.deleted_at IS NULL', [$departmentId])['total'] ?? 0);
        return ['data' => $rows, 'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total]];
    }

    public function createDataset(array $apiKey, array $data): array
    {
        $kbId = (int) ($data['kb_id'] ?? 0);
        $this->knowledgeBase($kbId, $apiKey);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Dataset name is required', 422);
        $datasetId = 'ds_' . bin2hex(random_bytes(12));
        $prefix = trim((string) ($data['collection_prefix'] ?? '')) ?: 'tokenhub_' . substr($datasetId, 3);
        App::db()->execute('INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, description, dataset_type, collection_prefix, status, retrieval_profile) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$datasetId, $kbId, $name, $data['description'] ?? null, $this->datasetType($data['dataset_type'] ?? 'mixed'), $prefix, $this->datasetStatus($data['status'] ?? 'active'), $this->json($data['retrieval_profile'] ?? null)]);
        $id = (int) App::db()->pdo()->lastInsertId();
        $this->grantApiKeyBinding('rag_dataset_bindings', 'dataset_id', $id, $apiKey);
        (new AuditService())->write('agent_management.rag_dataset.create', 'rag_vector_datasets', $id, ['kb_id' => $kbId, 'dataset_id' => $datasetId]);
        return $this->dataset($id, $apiKey);
    }

    public function updateDataset(int $id, array $apiKey, array $data): array
    {
        $this->dataset($id, $apiKey);
        $updates = [];
        foreach (['name', 'description', 'collection_prefix', 'status', 'retrieval_profile'] as $field) {
            if (!array_key_exists($field, $data)) continue;
            $updates[$field] = match ($field) {
                'status' => $this->datasetStatus($data[$field]),
                'retrieval_profile' => $this->json($data[$field]),
                default => $data[$field],
            };
        }
        $this->update('rag_vector_datasets', $id, $updates, 'agent_management.rag_dataset.update');
        return $this->dataset($id, $apiKey);
    }

    public function deleteDataset(int $id, array $apiKey): array
    {
        $this->dataset($id, $apiKey);
        App::db()->execute('UPDATE rag_vector_datasets SET status = "disabled", deleted_at = NOW() WHERE id = ?', [$id]);
        (new AuditService())->write('agent_management.rag_dataset.delete', 'rag_vector_datasets', $id);
        return ['id' => $id, 'deleted' => true];
    }

    private function list(string $table, int $departmentId, array $query): array
    {
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50))); $offset = max(0, (int) ($query['offset'] ?? 0));
        $rows = App::db()->select("SELECT * FROM {$table} WHERE department_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT ? OFFSET ?", [$departmentId, $limit, $offset]);
        $total = (int) (App::db()->first("SELECT COUNT(*) AS total FROM {$table} WHERE department_id = ? AND deleted_at IS NULL", [$departmentId])['total'] ?? 0);
        return ['data' => $rows, 'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total]];
    }
    private function knowledgeBase(int $id, array $apiKey): array { $row = App::db()->first('SELECT * FROM rag_knowledge_bases WHERE id = ? AND department_id = ? AND deleted_at IS NULL', [$id, $this->departmentId($apiKey)]); if ($row === null) abort_json('MANAGEMENT_RESOURCE_NOT_FOUND', 'Knowledge base not found in API key department', 404); return $row; }
    private function dataset(int $id, array $apiKey): array { $row = App::db()->first('SELECT d.* FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = ? AND k.department_id = ? AND d.deleted_at IS NULL', [$id, $this->departmentId($apiKey)]); if ($row === null) abort_json('MANAGEMENT_RESOURCE_NOT_FOUND', 'Dataset not found in API key department', 404); return $row; }
    private function update(string $table, int $id, array $updates, string $audit): void { if ($updates === []) return; $sets = implode(', ', array_map(static fn (string $field): string => "{$field} = ?", array_keys($updates))); App::db()->execute("UPDATE {$table} SET {$sets} WHERE id = ?", [...array_values($updates), $id]); (new AuditService())->write($audit, $table, $id, $updates); }
    private function departmentId(array $apiKey): int { $id = (int) ($apiKey['department_id'] ?? 0); if ($id <= 0) abort_json('MANAGEMENT_FORBIDDEN', 'API key has no management department', 403); return $id; }
    private function visibility(mixed $value): string { $value = (string) $value; if (!in_array($value, ['private', 'department', 'shared'], true)) abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Invalid knowledge base visibility', 422); return $value; }
    private function kbStatus(mixed $value): string { $value = (string) $value; if (!in_array($value, ['active', 'disabled'], true)) abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Invalid knowledge base status', 422); return $value; }
    private function datasetStatus(mixed $value): string { $value = (string) $value; if (!in_array($value, ['draft', 'active', 'disabled'], true)) abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Invalid dataset status', 422); return $value; }
    private function datasetType(mixed $value): string { $value = (string) $value; if (!in_array($value, ['text', 'image', 'multimodal', 'mixed'], true)) abort_json('MANAGEMENT_INVALID_PAYLOAD', 'Invalid dataset type', 422); return $value; }
    private function json(mixed $value): ?string { return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private function grantApiKeyBinding(string $table, string $resourceColumn, int $resourceId, array $apiKey): void { App::db()->execute("INSERT INTO {$table} ({$resourceColumn}, subject_type, subject_id, permission) VALUES (?, 'api_key', ?, 'admin')", [$resourceId, (int) ($apiKey['id'] ?? 0)]); }
}
