<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ApiKeyService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

App::bootstrap(dirname(__DIR__, 2));

$db = App::db();
$service = new ApiKeyService();
$departmentId = 850201;
$userId = 850301;
$apiKeyId = 850401;
$otherApiKeyId = 850402;
$bucket = gmdate('Y-m-d 00:00:00');

$cleanup = static function () use ($db, $departmentId, $userId, $apiKeyId, $otherApiKeyId): void {
    foreach (['usage_aggregates_day', 'usage_aggregates_hour', 'usage_aggregates_minute'] as $table) {
        $db->execute("DELETE FROM {$table} WHERE api_key_id IN (?, ?)", [$apiKeyId, $otherApiKeyId]);
    }
    $db->execute('DELETE FROM api_keys WHERE id IN (?, ?)', [$apiKeyId, $otherApiKeyId]);
    $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
};

$cleanup();

try {
    $db->execute(
        'INSERT INTO departments (id, parent_id, name, path, level, sort_order, status) VALUES (?, NULL, "api-key-usage", ?, 0, 1, "active")',
        [$departmentId, '/' . $departmentId . '/']
    );
    $db->execute(
        'INSERT INTO users (id, department_id, username, password_hash, role, status) VALUES (?, ?, "api-key-usage-user", ?, "admin", "active")',
        [$userId, $departmentId, password_hash('secret', PASSWORD_DEFAULT)]
    );
    $db->execute(
        'INSERT INTO api_keys (id, user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, "summary key", "tkh_sum", "mary", ?, "active")',
        [$apiKeyId, $userId, $departmentId, Crypto::hashKey('summary-key')]
    );
    $db->execute(
        'INSERT INTO api_keys (id, user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, "other key", "tkh_oth", "ther", ?, "active")',
        [$otherApiKeyId, $userId, $departmentId, Crypto::hashKey('other-key')]
    );

    $db->execute(
        'INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, total_cost, latency_ms_sum) VALUES (?, ?, ?, ?, 1, 1, "text_generation", 3, 2, 1, 120, 30, 9, 5, 0.25, 900)',
        [$bucket, $departmentId, $userId, $apiKeyId]
    );
    $db->execute(
        'INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, total_cost, latency_ms_sum) VALUES (?, ?, ?, ?, 1, 2, "vision_understanding", 4, 4, 0, 80, 20, 11, 7, 0.75, 500)',
        [$bucket, $departmentId, $userId, $apiKeyId]
    );
    $db->execute(
        'INSERT INTO usage_aggregates_day (bucket_at, department_id, user_id, api_key_id, provider_id, model_id, capability, request_count, success_count, failed_count, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, total_cost, latency_ms_sum) VALUES (?, ?, ?, ?, 1, 1, "text_generation", 99, 99, 0, 990, 99, 99, 99, 9.99, 100)',
        [$bucket, $departmentId, $userId, $otherApiKeyId]
    );

    $rows = $service->listWithUsage(['api_key_id' => $apiKeyId]);
    assert_same(1, count($rows), 'API key usage list should support api_key_id filtering.');
    assert_same($apiKeyId, (int) $rows[0]['id'], 'API key usage row should preserve key id.');
    assert_same(7, (int) $rows[0]['request_count'], 'API key usage row should aggregate request count.');
    assert_same(6, (int) $rows[0]['success_count'], 'API key usage row should aggregate success count.');
    assert_same(1, (int) $rows[0]['failed_count'], 'API key usage row should aggregate failed count.');
    assert_same(200, (int) $rows[0]['input_tokens'], 'API key usage row should aggregate input tokens.');
    assert_same(50, (int) $rows[0]['output_tokens'], 'API key usage row should aggregate output tokens.');
    assert_same(20, (int) $rows[0]['cache_read_tokens'], 'API key usage row should aggregate cache read tokens.');
    assert_same(12, (int) $rows[0]['cache_write_tokens'], 'API key usage row should aggregate cache write tokens.');
    assert_same(32, (int) $rows[0]['cache_tokens'], 'API key usage row should expose total cache tokens.');
    assert_same(250, (int) $rows[0]['total_tokens'], 'API key usage row should expose total tokens.');

    $page = $service->listPageWithUsage(['api_key_id' => $apiKeyId, 'limit' => 1, 'offset' => 0]);
    assert_same(1, $page['total'], 'API key usage page should report the filtered total.');
    assert_same(1, count($page['items']), 'API key usage page should return the requested item.');
    assert_same($apiKeyId, (int) $page['items'][0]['id'], 'API key usage page item should preserve the usage-enriched key id.');

    $searchPage = $service->listPageWithUsage(['keyword' => 'summary', 'limit' => 20]);
    assert_same(1, $searchPage['total'], 'API key usage page should search API key names without widening its scope.');
    assert_same(1, count($searchPage['items']), 'API key usage search should return only the matching key.');
    assert_same($apiKeyId, (int) $searchPage['items'][0]['id'], 'API key usage search should return the summary key.');
    assert_same('api-key-usage', $searchPage['items'][0]['department_name'], 'API key usage search should include the owning department name.');
    assert_same('api-key-usage-user', $searchPage['items'][0]['user_name'], 'API key usage search should include the owning employee username.');
} finally {
    $cleanup();
}
