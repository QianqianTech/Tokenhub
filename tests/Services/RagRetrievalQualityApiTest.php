<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Bootstrap\Kernel;
use TokenHub\Services\AuthService;
use TokenHub\Support\App;
use TokenHub\Support\Response;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$superUsername = 'rag_quality_super_' . $suffix;
$ragAdminUsername = 'rag_quality_admin_' . $suffix;
$password = 'Rag-Quality-Api-Password-123';
$departmentAId = 0;
$departmentBId = 0;
$superUserId = 0;
$ragAdminUserId = 0;
$departmentARetrievalId = 'rtr_quality_a_' . $suffix;
$departmentBRetrievalId = 'rtr_quality_b_' . $suffix;
$departmentBQuery = 'private department B quality query ' . $suffix;
$departmentBDatasetId = 'ds_quality_b_' . $suffix;

try {
    unset($_COOKIE['tokenhub_admin_session']);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/admin-api/rag/retrieval-quality';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = [];

    $anonymous = Kernel::router()->dispatch('GET', '/admin-api/rag/retrieval-quality');
    assert_true($anonymous instanceof Response, 'Retrieval quality route should return an HTTP response.');
    assert_same(401, $anonymous->status, 'Retrieval quality route must reject anonymous access.');

    $db->execute(
        'INSERT INTO departments (parent_id, name, path, level, sort_order, status) VALUES (NULL, ?, ?, 0, 0, "active")',
        ['RAG Quality A ' . $suffix, '/rag-quality-a-' . $suffix . '/']
    );
    $departmentAId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO departments (parent_id, name, path, level, sort_order, status) VALUES (NULL, ?, ?, 0, 0, "active")',
        ['RAG Quality B ' . $suffix, '/rag-quality-b-' . $suffix . '/']
    );
    $departmentBId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status, admin_type, admin_scope) VALUES (?, ?, ?, "admin", "active", "rag_admin", "departments")',
        [$departmentAId, $ragAdminUsername, password_hash($password, PASSWORD_DEFAULT)]
    );
    $ragAdminUserId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status, admin_type, admin_scope) VALUES (1, ?, ?, "super_admin", "active", "super_admin", "global")',
        [$superUsername, password_hash($password, PASSWORD_DEFAULT)]
    );
    $superUserId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO rag_retrieval_logs (retrieval_id, request_id, department_id, kb_ids, dataset_ids, query, query_modality, top_k, hit_count, max_score, sources, latency_ms, fallback_used, created_at) VALUES (?, ?, ?, "[]", ?, ?, "text", 5, 0, NULL, "[]", 15, 0, NOW())',
        [$departmentARetrievalId, 'req_quality_a_' . $suffix, $departmentAId, json_encode(['ds_quality_a_' . $suffix], JSON_UNESCAPED_UNICODE), 'department A quality query']
    );
    $db->execute(
        'INSERT INTO rag_retrieval_logs (retrieval_id, request_id, department_id, kb_ids, dataset_ids, query, query_modality, top_k, hit_count, max_score, sources, latency_ms, fallback_used, created_at) VALUES (?, ?, ?, "[]", ?, ?, "text", 5, 0, NULL, "[]", 15, 0, NOW())',
        [$departmentBRetrievalId, 'req_quality_b_' . $suffix, $departmentBId, json_encode([$departmentBDatasetId], JSON_UNESCAPED_UNICODE), $departmentBQuery]
    );

    $ragAdminLogin = (new AuthService())->login($ragAdminUsername, $password, '127.0.0.1', 'RagRetrievalQualityApiTest/1.0');
    assert_true(is_array($ragAdminLogin), 'Synthetic department RAG administrator should authenticate for the quality API contract.');
    $_COOKIE['tokenhub_admin_session'] = (string) $ragAdminLogin['session'];
    $_GET = ['window_hours' => '1', 'low_confidence' => '0.71'];

    $response = Kernel::router()->dispatch('GET', '/admin-api/rag/retrieval-quality');
    assert_true($response instanceof Response, 'Authenticated retrieval quality request should return an HTTP response.');
    assert_same(200, $response->status, 'Authenticated administrator should receive the retrieval quality summary.');
    $payload = json_decode($response->body, true);
    assert_true(is_array($payload), 'Retrieval quality response should be JSON.');
    assert_same(true, $payload['ok'] ?? null, 'Retrieval quality response should be successful.');
    assert_same(1, $payload['data']['window_hours'] ?? null, 'Retrieval quality response should forward the requested window.');
    assert_same(0.71, $payload['data']['low_confidence_threshold'] ?? null, 'Retrieval quality response should forward the requested confidence threshold.');
    foreach (['metrics_scope', 'capacity', 'sampled', 'truncated'] as $field) {
        assert_true(array_key_exists($field, $payload['data'] ?? []), 'Retrieval quality response should disclose ' . $field . '.');
    }

    $problems = $payload['data']['problematic_queries'] ?? [];
    $problem = array_values(array_filter($problems, static fn (array $item): bool => ($item['retrieval_id'] ?? null) === $departmentARetrievalId))[0] ?? null;
    assert_true(is_array($problem), 'Quality response should include the seeded problematic retrieval.');
    assert_same(['ds_quality_a_' . $suffix], $problem['dataset_ids'] ?? null, 'Problem query should expose its dataset IDs.');
    assert_true(!str_contains($response->body, $departmentBRetrievalId), 'Department RAG administrator must not receive another department retrieval ID.');
    assert_true(!str_contains($response->body, $departmentBQuery), 'Department RAG administrator must not receive another department query text.');
    assert_true(!str_contains($response->body, $departmentBDatasetId), 'Department RAG administrator must not receive another department dataset IDs.');

    $superLogin = (new AuthService())->login($superUsername, $password, '127.0.0.1', 'RagRetrievalQualityApiTest/1.0');
    assert_true(is_array($superLogin), 'Synthetic super administrator should authenticate for the quality API contract.');
    $_COOKIE['tokenhub_admin_session'] = (string) $superLogin['session'];
    $superResponse = Kernel::router()->dispatch('GET', '/admin-api/rag/retrieval-quality');
    assert_true($superResponse instanceof Response, 'Super administrator retrieval quality request should return an HTTP response.');
    $superPayload = json_decode($superResponse->body, true);
    assert_true(is_array($superPayload), 'Super administrator retrieval quality response should be JSON.');
    assert_true(
        in_array($departmentBRetrievalId, array_column($superPayload['data']['problematic_queries'] ?? [], 'retrieval_id'), true),
        'Super administrator should receive the other department retrieval within global scope.'
    );
} finally {
    unset($_COOKIE['tokenhub_admin_session']);
    $_GET = [];
    $db->execute('DELETE FROM rag_retrieval_logs WHERE retrieval_id IN (?, ?)', [$departmentARetrievalId, $departmentBRetrievalId]);
    foreach ([$ragAdminUserId, $superUserId] as $userId) {
        if ($userId > 0) {
            $db->execute('DELETE FROM admin_sessions WHERE user_id = ?', [$userId]);
            $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
        }
    }
    foreach ([$departmentAId, $departmentBId] as $departmentId) {
        if ($departmentId > 0) {
            $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
        }
    }
}
