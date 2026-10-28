<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\CrudService;
use TokenHub\Support\App;

App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin']);

$db = App::db();
$suffix = bin2hex(random_bytes(5));
$departmentId = null;
$userId = null;
$providerId = null;
$apiKeyId = null;
$modelIds = [];

try {
    $db->execute('INSERT INTO departments (name, path, level, status) VALUES (?, "/", 0, "active")', ["batch-models-{$suffix}"]);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute('UPDATE departments SET path = ? WHERE id = ?', ["/{$departmentId}/", $departmentId]);

    $db->execute(
        'INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, ?, "user", "active")',
        [$departmentId, "batch-models-{$suffix}", password_hash('secret', PASSWORD_DEFAULT)]
    );
    $userId = (int) $db->pdo()->lastInsertId();

    $db->execute(
        'INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, "bt", ?, ?, "active")',
        [$userId, $departmentId, "batch-models-{$suffix}", substr($suffix, 0, 8), hash('sha256', "batch-models-{$suffix}")]
    );
    $apiKeyId = (int) $db->pdo()->lastInsertId();

    $db->execute(
        'INSERT INTO providers (name, protocol, base_url, status) VALUES (?, "openai", "https://provider.example", "active")',
        ["batch-models-{$suffix}"]
    );
    $providerId = (int) $db->pdo()->lastInsertId();

    foreach (['alpha', 'beta'] as $name) {
        $db->execute(
            'INSERT INTO models (provider_id, name, external_name, capability, context_length, status) VALUES (?, ?, ?, "text_generation", 8192, "active")',
            [$providerId, "batch-{$name}-{$suffix}", "batch-{$name}-{$suffix}"]
        );
        $modelIds[] = (int) $db->pdo()->lastInsertId();
    }

    $db->execute('INSERT INTO model_prices (model_id, input_token_price, output_token_price, currency) VALUES (?, 0.01000000, 0.02000000, "USD")', [$modelIds[0]]);
    $db->execute('INSERT INTO api_key_model_permissions (api_key_id, model_id) VALUES (?, ?)', [$apiKeyId, $modelIds[0]]);
    $db->execute('INSERT INTO api_key_model_quotas (api_key_id, model_id, period, request_limit, status) VALUES (?, ?, "day", 10, "active")', [$apiKeyId, $modelIds[0]]);

    $service = new CrudService();
    $disabled = $service->batchModels('disable', $modelIds);
    assert_same(2, $disabled['updated'], 'Batch pause should update both selected models.');
    assert_same('disabled', $db->first('SELECT status FROM models WHERE id = ?', [$modelIds[0]])['status'] ?? null, 'Batch pause should disable selected models.');

    $enabled = $service->batchModels('enable', $modelIds);
    assert_same(2, $enabled['updated'], 'Batch enable should update both selected models.');
    assert_same('active', $db->first('SELECT status FROM models WHERE id = ?', [$modelIds[1]])['status'] ?? null, 'Batch enable should activate selected models.');

    $deleted = $service->batchModels('delete', $modelIds);
    assert_same(2, $deleted['deleted'], 'Batch delete should delete both selected models.');
    assert_same(0, (int) ($db->first('SELECT COUNT(*) AS count FROM models WHERE id IN (?, ?)', $modelIds)['count'] ?? -1), 'Batch delete should remove selected models.');
    assert_same(0, (int) ($db->first('SELECT COUNT(*) AS count FROM model_prices WHERE model_id = ?', [$modelIds[0]])['count'] ?? -1), 'Batch delete should remove model prices.');
    assert_same(0, (int) ($db->first('SELECT COUNT(*) AS count FROM api_key_model_permissions WHERE model_id = ?', [$modelIds[0]])['count'] ?? -1), 'Batch delete should remove API key model permissions.');
    assert_same(0, (int) ($db->first('SELECT COUNT(*) AS count FROM api_key_model_quotas WHERE model_id = ?', [$modelIds[0]])['count'] ?? -1), 'Batch delete should remove API key model quotas.');
} finally {
    foreach (['api_key_model_quotas', 'api_key_model_permissions', 'model_prices'] as $table) {
        foreach ($modelIds as $modelId) {
            $db->execute("DELETE FROM {$table} WHERE model_id = ?", [$modelId]);
        }
    }
    foreach ($modelIds as $modelId) {
        $db->execute('DELETE FROM models WHERE id = ?', [$modelId]);
    }
    if ($providerId !== null) {
        $db->execute('DELETE FROM providers WHERE id = ?', [$providerId]);
    }
    if ($apiKeyId !== null) {
        $db->execute('DELETE FROM api_keys WHERE id = ?', [$apiKeyId]);
    }
    if ($userId !== null) {
        $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    }
    if ($departmentId !== null) {
        $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
    }
}

