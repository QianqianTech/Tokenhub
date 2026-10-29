<?php

declare(strict_types=1);

/*
 * Shared in-memory SQLite stand-in for the RAG schema, used by the RAG unit
 * tests (tests/Unit/Rag*Test.php). Only the MySQL-isms the RAG services use are
 * translated. Not a test file itself (no "Test.php" suffix).
 */

use TokenHub\Support\App;
use TokenHub\Support\Database;
use TokenHub\Support\RequestContext;

final class RagSqlitePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query);
        $query = preg_replace('/\bFOR UPDATE SKIP LOCKED\b/', '', $query) ?? $query;
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
        }

        return parent::prepare($query, $options);
    }
}

function rag_sqlite_fixture(): RagSqlitePdo
{
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        echo "SKIP sqlite unavailable\n";
        exit(0);
    }
    (new ReflectionProperty(App::class, 'config'))->setValue(null, \TokenHub\Support\Config::default());
    $pdo = new RagSqlitePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('GREATEST', static fn (...$values) => max($values));
    $pdo->sqliteCreateFunction('IF', static fn ($condition, $then, $else) => $condition ? $then : $else);
    foreach ([
        'CREATE TABLE departments (id INTEGER PRIMARY KEY, parent_id INT, name TEXT, path TEXT, level INT DEFAULT 0, status TEXT DEFAULT "active", created_at TEXT, updated_at TEXT)',
        'CREATE TABLE users (id INTEGER PRIMARY KEY, department_id INT, username TEXT, password_hash TEXT, role TEXT DEFAULT "user", status TEXT DEFAULT "active", managed_department_ids TEXT, admin_scope TEXT, admin_type TEXT, admin_permission_mode TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, department_id INT, name TEXT, key_hash TEXT, status TEXT DEFAULT "active")',
        'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT, created_at TEXT)',
        'CREATE TABLE rag_knowledge_bases (id INTEGER PRIMARY KEY, kb_id TEXT UNIQUE, name TEXT, description TEXT, owner_user_id INT, department_id INT, visibility TEXT DEFAULT "private", status TEXT DEFAULT "active", default_text_vector_config_id INT, default_image_vector_config_id INT, default_multimodal_vector_config_id INT, chunking_profile TEXT, retrieval_profile TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT)',
        'CREATE TABLE rag_knowledge_base_bindings (id INTEGER PRIMARY KEY, kb_id INT, subject_type TEXT, subject_id INT, permission TEXT DEFAULT "read", is_default INT DEFAULT 0, include_descendants INT DEFAULT 1, expires_at TEXT, granted_by_user_id INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, UNIQUE (kb_id, subject_type, subject_id))',
        'CREATE TABLE rag_vector_configs (id INTEGER PRIMARY KEY, config_id TEXT, name TEXT, modality TEXT, provider_id INT, model_id INT, model_name TEXT, dimension INT DEFAULT 3, distance_metric TEXT DEFAULT "cosine", vector_store TEXT DEFAULT "mysql_fallback", collection_name TEXT, batch_size INT DEFAULT 32, max_input_tokens INT, image_max_pixels INT, normalize_vector INT DEFAULT 1, enabled INT DEFAULT 1, config_json TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE rag_vector_datasets (id INTEGER PRIMARY KEY, dataset_id TEXT UNIQUE, kb_id INT, name TEXT, description TEXT, dataset_type TEXT DEFAULT "mixed", text_vector_config_id INT, image_vector_config_id INT, multimodal_vector_config_id INT, collection_prefix TEXT DEFAULT "", status TEXT DEFAULT "active", acl_mode TEXT DEFAULT "inherit", chunk_count INT DEFAULT 0, vector_count INT DEFAULT 0, last_indexed_at TEXT, retrieval_profile TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT)',
        'CREATE TABLE rag_dataset_bindings (id INTEGER PRIMARY KEY, dataset_id INT, subject_type TEXT, subject_id INT, permission TEXT DEFAULT "read", is_default INT DEFAULT 0, priority INT DEFAULT 100, include_descendants INT DEFAULT 1, expires_at TEXT, granted_by_user_id INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, UNIQUE (dataset_id, subject_type, subject_id))',
        'CREATE TABLE rag_uploads (id INTEGER PRIMARY KEY, upload_id TEXT UNIQUE, kb_id INT, dataset_id INT, owner_user_id INT, department_id INT, api_key_id INT, device_id TEXT, source_type TEXT DEFAULT "api", content_type TEXT DEFAULT "text", original_filename TEXT, mime_type TEXT, size_bytes INT DEFAULT 0, sha256 TEXT DEFAULT "", file_hash TEXT DEFAULT "", storage_path TEXT, metadata TEXT, preview_status TEXT DEFAULT "pending", vectorize_status TEXT DEFAULT "not_started", status TEXT DEFAULT "uploaded", confirm_required INT DEFAULT 1, review_status TEXT DEFAULT "not_required", confirmed_by_user_id INT, confirmed_by_api_key_id INT, confirmed_at TEXT, total_items INT DEFAULT 0, text_items INT DEFAULT 0, image_items INT DEFAULT 0, processed_items INT DEFAULT 0, progress_percent REAL DEFAULT 0, progress REAL DEFAULT 0, current_stage TEXT, stage TEXT, error_code TEXT, error_message TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE rag_upload_preview_items (id INTEGER PRIMARY KEY, preview_item_id TEXT UNIQUE, upload_id INT, kb_id INT, dataset_id INT, item_type TEXT, title TEXT, content TEXT, question TEXT, answer TEXT, image_asset_id INT, purpose TEXT, description TEXT, detailed_description TEXT, "row_number" INT, sheet_name TEXT, validation_status TEXT DEFAULT "valid", validation_errors TEXT, metadata TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE rag_documents (id INTEGER PRIMARY KEY, document_id TEXT UNIQUE, kb_id INT, dataset_id INT, upload_id INT, source_preview_item_id TEXT, title TEXT, document_key TEXT, doc_type TEXT DEFAULT "plain_text", language TEXT, source_uri TEXT, version INT DEFAULT 1, parse_status TEXT DEFAULT "parsed", index_status TEXT DEFAULT "pending", metadata TEXT, effective_at TEXT, expires_at TEXT, superseded_by_document_id INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT, UNIQUE (upload_id, source_preview_item_id))',
        'CREATE TABLE rag_image_assets (id INTEGER PRIMARY KEY, image_id TEXT, kb_id INT, upload_id INT, document_id INT, original_filename TEXT, mime_type TEXT, width INT, height INT, size_bytes INT DEFAULT 0, sha256 TEXT DEFAULT "", storage_path TEXT DEFAULT "", thumbnail_path TEXT, purpose TEXT, short_description TEXT, detailed_description TEXT, ocr_text TEXT, labels TEXT, safety_flags TEXT, warnings TEXT, metadata TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE rag_chunks (id INTEGER PRIMARY KEY, chunk_id TEXT UNIQUE, kb_id INT, dataset_id INT, document_id INT, chunk_type TEXT DEFAULT "text", ordinal INT DEFAULT 0, title TEXT, content TEXT, question TEXT, answer TEXT, image_asset_id INT, token_count INT, metadata TEXT, content_hash TEXT DEFAULT "", enabled INT DEFAULT 1, lifecycle_status TEXT DEFAULT "active", effective_at TEXT, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE rag_embedding_jobs (id INTEGER PRIMARY KEY, job_id TEXT, kb_id INT, dataset_id INT, upload_id INT, document_id INT, chunk_id INT, vector_config_id INT, job_type TEXT, execution_backend TEXT DEFAULT "php_provider", status TEXT DEFAULT "queued", attempts INT DEFAULT 0, max_attempts INT DEFAULT 5, next_run_at TEXT, dispatched_at TEXT, locked_at TEXT, lock_token TEXT, error_code TEXT, error_message TEXT, metrics TEXT, total_items INT DEFAULT 0, processed_items INT DEFAULT 0, progress_percent REAL DEFAULT 0, created_at TEXT, updated_at TEXT, UNIQUE (upload_id, chunk_id, job_type))',
        'CREATE TABLE rag_vector_records (id INTEGER PRIMARY KEY, vector_id TEXT UNIQUE, collection_name TEXT, kb_id INT, dataset_id INT, chunk_id INT, image_id INT, vector_config_id INT, modality TEXT DEFAULT "text", dimension INT DEFAULT 3, vector_json TEXT, content_hash TEXT DEFAULT "", index_status TEXT DEFAULT "indexed", status TEXT DEFAULT "indexed", indexed_at TEXT, deleted_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE rag_retrieval_logs (id INTEGER PRIMARY KEY, retrieval_id TEXT, request_id TEXT, api_key_id INT, user_id INT, department_id INT, kb_ids TEXT, dataset_ids TEXT, query TEXT, query_modality TEXT, top_k INT, hit_count INT, max_score REAL, sources TEXT, latency_ms INT, fallback_used INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE rag_correction_tasks (id INTEGER PRIMARY KEY, correction_id TEXT, task_id TEXT, feedback_id TEXT, retrieval_id TEXT, kb_ids TEXT, dataset_ids TEXT, kb_id INT, dataset_id INT, issue_type TEXT, source_type TEXT, action TEXT, target_document_id INT, target_chunk_id INT, replacement_upload_id INT, status TEXT DEFAULT "pending", priority INT DEFAULT 50, reason TEXT, details TEXT, created_by_user_id INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, completed_at TEXT)',
        'CREATE TABLE rag_retention_policies (id INTEGER PRIMARY KEY, policy_id TEXT, kb_id INT, dataset_id INT, name TEXT, expires_after_days INT, inactive_after_days INT, action_on_expiry TEXT DEFAULT "mark_expired", require_approval INT DEFAULT 1, enabled INT DEFAULT 1, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE rag_eval_cases (id INTEGER PRIMARY KEY, case_id TEXT, kb_id INT, dataset_id INT, query TEXT, query_modality TEXT DEFAULT "text", expected_chunk_ids TEXT, status TEXT DEFAULT "draft", source_feedback_id TEXT, metadata TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE models (id INTEGER PRIMARY KEY, provider_id INT, name TEXT, external_name TEXT, capability TEXT, status TEXT DEFAULT "active")',
        'CREATE TABLE providers (id INTEGER PRIMARY KEY, name TEXT, protocol TEXT, base_url TEXT, encrypted_secret TEXT, default_headers TEXT, timeout_ms INT, retry_count INT DEFAULT 1, status TEXT DEFAULT "active")',
    ] as $ddl) {
        $pdo->exec($ddl);
    }
    $database = new Database(App::config());
    (new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
    (new ReflectionProperty(App::class, 'database'))->setValue(null, $database);
    (new ReflectionProperty(App::class, 'context'))->setValue(null, RequestContext::fromHeaders([]));

    return $pdo;
}

function rag_expect_abort(callable $callback, string $code, string $message): void
{
    try {
        $callback();
    } catch (\TokenHub\Support\HttpAbortException $exception) {
        assert_same($code, $exception->errorCode, $message);
        return;
    }
    throw new RuntimeException($message . ' (no abort raised)');
}

/**
 * Minimal in-memory Qdrant HTTP emulation for QdrantVectorStore's transport:
 * collections (dense + named sparse vectors), aliases, upsert, delete,
 * filtered dense search, sparse/dense /points/query and scroll.
 */
final class RagFakeQdrant
{
    /** @var array<string, array{config: array<string, mixed>, points: array<string, array<string, mixed>>, indexes: array<string, string>}> */
    public array $collections = [];
    /** @var array<string, string> alias => collection */
    public array $aliases = [];
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    public function __invoke(string $method, string $path, ?array $body = null): array
    {
        $this->calls[] = [$method, $path];
        $path = (string) parse_url($path, PHP_URL_PATH);
        if ($path === '/healthz') {
            return $this->ok(['title' => 'fake']);
        }
        if ($method === 'POST' && $path === '/collections/aliases') {
            foreach ($body['actions'] ?? [] as $action) {
                if (isset($action['create_alias'])) {
                    $this->aliases[$action['create_alias']['alias_name']] = $action['create_alias']['collection_name'];
                }
                if (isset($action['delete_alias'])) {
                    unset($this->aliases[$action['delete_alias']['alias_name']]);
                }
            }
            return $this->ok(true);
        }
        if (preg_match('#^/collections/([^/]+)(/.*)?$#', $path, $m) !== 1) {
            return $this->error(404, 'not found');
        }
        $name = rawurldecode($m[1]);
        $rest = $m[2] ?? '';
        $resolved = $this->aliases[$name] ?? $name;
        if ($rest === '') {
            if ($method === 'GET') {
                return isset($this->collections[$resolved]) ? $this->ok(['config' => $this->collections[$resolved]['config']]) : $this->error(404, 'Collection not found');
            }
            if ($method === 'PUT') {
                $this->collections[$name] = ['config' => $body ?? [], 'points' => [], 'indexes' => []];
                return $this->ok(true);
            }
            if ($method === 'DELETE') {
                unset($this->collections[$resolved]);
                return $this->ok(true);
            }
        }
        if (!isset($this->collections[$resolved])) {
            return $this->error(404, 'Collection ' . $name . ' not found');
        }
        $collection = &$this->collections[$resolved];
        if ($rest === '/index' && $method === 'PUT') {
            $collection['indexes'][(string) $body['field_name']] = (string) ($body['field_schema'] ?? 'keyword');
            return $this->ok(true);
        }
        if ($rest === '/points' && $method === 'PUT') {
            foreach ($body['points'] ?? [] as $point) {
                $collection['points'][(string) $point['id']] = $point;
            }
            return $this->ok(['status' => 'completed']);
        }
        if ($rest === '/points/delete') {
            foreach ($body['points'] ?? [] as $id) {
                unset($collection['points'][(string) $id]);
            }
            return $this->ok(['status' => 'completed']);
        }
        if ($rest === '/points/scroll') {
            $points = array_values($collection['points']);
            $offset = (int) ($body['offset'] ?? 0);
            $limit = (int) ($body['limit'] ?? 100);
            $page = array_slice($points, $offset, $limit);
            return $this->ok(['points' => array_map(static fn (array $p): array => ['id' => $p['id'], 'payload' => $p['payload'] ?? []], $page), 'next_page_offset' => $offset + $limit < count($points) ? $offset + $limit : null]);
        }
        if ($rest === '/points/search' || $rest === '/points/query') {
            $filter = $body['filter']['must'] ?? [];
            $limit = (int) ($body['limit'] ?? 10);
            $hits = [];
            foreach ($collection['points'] as $point) {
                if (!$this->matches($point['payload'] ?? [], $filter)) {
                    continue;
                }
                $score = $rest === '/points/search'
                    ? $this->cosine((array) $body['vector'], $this->denseOf($point))
                    : $this->queryScore($body, $point);
                if ($score === null) {
                    continue;
                }
                $hits[] = ['id' => $point['id'], 'score' => $score, 'payload' => $point['payload'] ?? []];
            }
            usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $hits = array_slice($hits, 0, $limit);
            return $this->ok($rest === '/points/query' ? ['points' => $hits] : $hits);
        }

        return $this->error(400, 'unsupported ' . $method . ' ' . $rest);
    }

    /** @return list<float> */
    private function denseOf(array $point): array
    {
        $vector = $point['vector'] ?? [];
        if (is_array($vector) && array_is_list($vector)) {
            return $vector;
        }
        foreach ((array) $vector as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }
        return [];
    }

    private function queryScore(array $body, array $point): ?float
    {
        $query = $body['query'] ?? null;
        $using = $body['using'] ?? null;
        if (is_array($query) && isset($query['indices'])) {
            $sparse = $point['vector'][$using] ?? null;
            if (!is_array($sparse)) {
                return null;
            }
            $weights = array_combine($sparse['indices'], $sparse['values']) ?: [];
            $score = 0.0;
            foreach ($query['indices'] as $i => $index) {
                $score += ($weights[$index] ?? 0.0) * (float) $query['values'][$i];
            }
            return $score > 0 ? $score : null;
        }

        return $this->cosine((array) $query, $this->denseOf($point));
    }

    /** @param list<array<string, mixed>> $must */
    private function matches(array $payload, array $must): bool
    {
        foreach ($must as $condition) {
            $value = $payload[$condition['key']] ?? null;
            if (isset($condition['match']['value']) && $value !== $condition['match']['value']) {
                return false;
            }
            if (isset($condition['match']['any']) && !in_array($value, $condition['match']['any'], true)) {
                return false;
            }
        }
        return true;
    }

    private function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $value) {
            $dot += (float) $value * (float) ($b[$i] ?? 0);
            $na += (float) $value ** 2;
        }
        foreach ($b as $value) {
            $nb += (float) $value ** 2;
        }
        return $na > 0 && $nb > 0 ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    private function ok(mixed $result): array
    {
        return ['ok' => true, 'status' => 200, 'body' => json_encode(['result' => $result]), 'json' => ['result' => $result]];
    }

    private function error(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'body' => json_encode(['status' => ['error' => $message]]), 'json' => ['status' => ['error' => $message]]];
    }
}
