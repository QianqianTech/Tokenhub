<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagVectorConfigService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$providerId = null;
$modelId = null;

try {
    $db->execute('INSERT INTO providers (name, protocol, base_url, encrypted_secret, status) VALUES (?, "openai", "https://probe.example/v1", "", "active")', ['Vector probe provider ' . $suffix]);
    $providerId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO models (provider_id, name, external_name, capability, status) VALUES (?, ?, ?, "embedding", "active")', [$providerId, 'Probe model ' . $suffix, 'probe-external-' . $suffix]);
    $modelId = (int) $db->pdo()->lastInsertId();

    $captured = [];
    $provider = new RagEmbeddingProviderService(null, static function (array $route, string $path, array $payload) use (&$captured): array {
        $captured = compact('route', 'path', 'payload');
        return ['ok' => true, 'status' => 200, 'body' => '', 'json' => ['data' => [['embedding' => [0.1, 0.2, 0.3]]]]];
    });
    $service = new RagVectorConfigService(null, $provider);
    $beforeConfigCount = (int) (($db->first('SELECT COUNT(*) AS c FROM rag_vector_configs')['c'] ?? 0));
    $beforeVectorCount = (int) (($db->first('SELECT COUNT(*) AS c FROM rag_vector_records')['c'] ?? 0));

    $result = $service->probe([
        'provider_id' => $providerId,
        'model_id' => $modelId,
        'modality' => 'text',
        'dimension' => 3,
        'distance_metric' => 'cosine',
        'vector_store' => 'mysql_fallback',
        'collection_name' => 'probe_' . $suffix,
    ]);

    assert_same('/v1/embeddings', $captured['path'] ?? null, 'Probe should use the provider embedding endpoint.');
    assert_same('TokenHub RAG embedding probe', $captured['payload']['input'] ?? null, 'Probe should use a fixed non-user probe string.');
    assert_same('probe-external-' . $suffix, $result['model_name'] ?? null, 'Probe should return the resolved embedding model name.');
    assert_same(3, $result['dimension'] ?? null, 'Probe should return the observed vector dimension.');
    assert_same($beforeConfigCount, (int) (($db->first('SELECT COUNT(*) AS c FROM rag_vector_configs')['c'] ?? 0)), 'Probe must not persist vector configurations.');
    assert_same($beforeVectorCount, (int) (($db->first('SELECT COUNT(*) AS c FROM rag_vector_records')['c'] ?? 0)), 'Probe must not persist vectors.');
} finally {
    if ($modelId !== null) {
        $db->execute('DELETE FROM models WHERE id = ?', [$modelId]);
    }
    if ($providerId !== null) {
        $db->execute('DELETE FROM providers WHERE id = ?', [$providerId]);
    }
}
