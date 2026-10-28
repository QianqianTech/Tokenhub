<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AdminChatSessionService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$providerIds = [];
$modelIds = [];

try {
    $db->execute(
        'INSERT INTO providers (name, protocol, base_url, encrypted_secret, status, timeout_ms) VALUES (?, "openai", "https://api.openai.com/v1", "", "active", 1500)',
        ['Admin Chat Provider ' . $suffix]
    );
    $providerIds[] = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO models (provider_id, name, external_name, capability, status, route_priority, context_length) VALUES (?, ?, ?, "text_generation", "active", 1, 8192)',
        [$providerIds[0], 'admin-chat-' . $suffix, 'admin-chat-ext']
    );
    $modelIds[] = (int) $db->pdo()->lastInsertId();

    $db->execute(
        'INSERT INTO providers (name, protocol, base_url, encrypted_secret, status, timeout_ms) VALUES (?, "openai", "https://provider.example/v1", "", "active", 1500)',
        ['Gap Provider ' . $suffix]
    );
    $providerIds[] = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO models (provider_id, name, external_name, capability, status, route_priority, context_length) VALUES (?, ?, ?, "text_generation", "active", 1, 8192)',
        [$providerIds[1], 'gap-chat-' . $suffix, 'gap-chat-ext']
    );
    $modelIds[] = (int) $db->pdo()->lastInsertId();

    $models = (new AdminChatSessionService())->models();
    $names = array_map(static fn (array $model): string => (string) $model['name'], $models);

    assert_true(in_array('admin-chat-' . $suffix, $names, true), 'Admin chat model list should include active OpenAI-compatible chat models.');
    assert_true(!in_array('gap-chat-' . $suffix, $names, true), 'Admin chat model list should not expose synthetic gap test models.');
} finally {
    if ($modelIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($modelIds), '?'));
        $db->execute("DELETE FROM api_key_model_permissions WHERE model_id IN ({$placeholders})", $modelIds);
        $db->execute("DELETE FROM api_key_model_quotas WHERE model_id IN ({$placeholders})", $modelIds);
        $db->execute("DELETE FROM model_prices WHERE model_id IN ({$placeholders})", $modelIds);
        $db->execute("DELETE FROM models WHERE id IN ({$placeholders})", $modelIds);
    }
    if ($providerIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($providerIds), '?'));
        $db->execute("DELETE FROM providers WHERE id IN ({$placeholders})", $providerIds);
    }
}
