<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\CrudService;
use TokenHub\Support\App;

App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin']);

$db = App::db();
$suffix = bin2hex(random_bytes(5));
$providerId = null;
$modelId = null;
$providerName = "provider-list-{$suffix}";
$modelName = "model-list-{$suffix}";

try {
    $db->execute(
        'INSERT INTO providers (name, protocol, base_url, status) VALUES (?, "openai", "https://provider-list.example", "active")',
        [$providerName]
    );
    $providerId = (int) $db->pdo()->lastInsertId();

    $db->execute(
        'INSERT INTO models (provider_id, name, external_name, capability, context_length, status) VALUES (?, ?, ?, "text_generation", 8192, "active")',
        [$providerId, $modelName, $modelName]
    );
    $modelId = (int) $db->pdo()->lastInsertId();

    $rows = (new CrudService())->list('models', ['name' => $modelName, 'limit' => 5]);

    assert_same(1, count($rows), 'Model list should include the inserted model only.');
    assert_same($modelId, (int) $rows[0]['id'], 'Model list should return the inserted model.');
    assert_same($providerId, (int) $rows[0]['provider_id'], 'Model list should keep provider_id for writes and filters.');
    assert_same($providerName, $rows[0]['provider_name'] ?? null, 'Model list should expose the provider display name.');
} finally {
    if ($modelId !== null) {
        $db->execute('DELETE FROM models WHERE id = ?', [$modelId]);
    }
    if ($providerId !== null) {
        $db->execute('DELETE FROM providers WHERE id = ?', [$providerId]);
    }
}
