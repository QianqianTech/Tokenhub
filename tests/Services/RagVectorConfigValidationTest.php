<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagVectorConfigService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$providerId = null;
$otherProviderId = null;
$embeddingModelId = null;
$textModelId = null;

try {
    $db->execute('INSERT INTO providers (name, protocol, base_url, encrypted_secret, status) VALUES (?, "openai", "https://embedding.example/v1", "", "active")', ['Vector validation provider ' . $suffix]);
    $providerId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO providers (name, protocol, base_url, encrypted_secret, status) VALUES (?, "openai", "https://other.example/v1", "", "active")', ['Other vector validation provider ' . $suffix]);
    $otherProviderId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO models (provider_id, name, external_name, capability, status) VALUES (?, ?, ?, "embedding", "active")', [$providerId, 'Embedding model ' . $suffix, 'embedding-external-' . $suffix]);
    $embeddingModelId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO models (provider_id, name, external_name, capability, status) VALUES (?, ?, ?, "text_generation", "active")', [$providerId, 'Text model ' . $suffix, 'text-external-' . $suffix]);
    $textModelId = (int) $db->pdo()->lastInsertId();

    $service = new RagVectorConfigService();
    $valid = $service->normalizeForWrite([
        'provider_id' => $providerId,
        'model_id' => $embeddingModelId,
        'model_name' => 'ignored',
        'modality' => 'text',
        'dimension' => 3,
        'distance_metric' => 'cosine',
        'vector_store' => 'mysql_fallback',
        'collection_name' => 'validation_' . $suffix,
    ]);
    assert_same('embedding-external-' . $suffix, $valid['model_name'] ?? null, 'Provider-backed vector configs should persist the model external name.');

    foreach ([
        ['provider_id' => $providerId, 'model_id' => null],
        ['provider_id' => $otherProviderId, 'model_id' => $embeddingModelId],
        ['provider_id' => $providerId, 'model_id' => $textModelId],
        ['provider_id' => $providerId, 'model_id' => $embeddingModelId, 'dimension' => 0],
        ['provider_id' => null, 'model_id' => null, 'config_json' => '{}'],
    ] as $invalid) {
        try {
            $service->normalizeForWrite([
                'modality' => 'text',
                'dimension' => 3,
                'distance_metric' => 'cosine',
                'vector_store' => 'mysql_fallback',
                'collection_name' => 'invalid_' . $suffix,
                ...$invalid,
            ]);
            throw new RuntimeException('Expected invalid vector configuration to be rejected.');
        } catch (RuntimeException $exception) {
            assert_true(in_array($exception->getMessage(), ['RAG_VECTOR_CONFIG_INVALID', 'RAG_MODEL_NOT_CONFIGURED'], true), 'Invalid vector config should expose a normalized RAG validation code.');
        }
    }
} finally {
    if ($embeddingModelId !== null) {
        $db->execute('DELETE FROM models WHERE id IN (?, ?)', [$embeddingModelId, $textModelId]);
    }
    if ($providerId !== null) {
        $db->execute('DELETE FROM providers WHERE id IN (?, ?)', [$providerId, $otherProviderId]);
    }
}
