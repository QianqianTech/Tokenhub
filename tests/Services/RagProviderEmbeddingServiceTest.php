<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$providerId = null;
$modelId = null;

try {
    $db->execute(
        'INSERT INTO providers (name, protocol, base_url, encrypted_secret, status, timeout_ms, retry_count) VALUES (?, "openai", "https://embedding.example/v1", "", "active", 1500, 2)',
        ['RAG Embedding Provider ' . $suffix]
    );
    $providerId = (int) $db->pdo()->lastInsertId();
    $db->execute(
        'INSERT INTO models (provider_id, name, external_name, capability, status, route_priority) VALUES (?, ?, ?, "embedding", "active", 1)',
        [$providerId, 'rag-embedding-' . $suffix, 'embedding-external-' . $suffix]
    );
    $modelId = (int) $db->pdo()->lastInsertId();

    $captured = [];
    $responseVector = [0.1, 0.2, 0.3];
    $requester = static function (array $route, string $path, array $payload) use (&$captured, &$responseVector): array {
        $captured = compact('route', 'path', 'payload');
        return [
            'ok' => true,
            'status' => 200,
            'body' => '',
            'json' => ['data' => [['embedding' => $responseVector]]],
        ];
    };

    $service = new RagEmbeddingProviderService(null, $requester);
    $config = ['provider_id' => $providerId, 'model_id' => $modelId, 'dimension' => 3];
    $vector = $service->embed('text', ['text' => '设备温度过高'], $config);

    assert_same([0.1, 0.2, 0.3], $vector, 'Provider-backed embedding should return the upstream vector.');
    assert_same('/v1/embeddings', $captured['path'] ?? null, 'Provider-backed embedding should use the embeddings endpoint.');
    assert_same('设备温度过高', $captured['payload']['input'] ?? null, 'Provider-backed embedding should send the normalized text input.');
    assert_same('embedding-external-' . $suffix, $captured['payload']['model'] ?? null, 'Provider-backed embedding should use the model external name.');

    $responseVector = [0.1, 0.2];
    $dimensionMismatch = false;
    try {
        $service->embed('text', ['text' => 'dimension mismatch'], $config);
    } catch (RuntimeException $exception) {
        $dimensionMismatch = $exception->getMessage() === 'RAG_EMBEDDING_DIMENSION_MISMATCH';
    }
    assert_true($dimensionMismatch, 'Provider-backed embedding should reject vectors with the wrong configured dimension.');

    $attempts = 0;
    $retryingRequester = static function () use (&$attempts): array {
        $attempts++;
        if ($attempts === 1) {
            return ['ok' => false, 'status' => 503, 'body' => '', 'json' => null, 'error_code' => 'PROVIDER_ERROR'];
        }
        return ['ok' => true, 'status' => 200, 'body' => '', 'json' => ['data' => [['embedding' => [0.3, 0.2, 0.1]]]]];
    };
    $retried = (new RagEmbeddingProviderService(null, $retryingRequester))->embed('text', ['text' => 'retry once'], $config);
    assert_same(2, $attempts, 'Provider embedding should honor the configured retry count for transient failures.');
    assert_same([0.3, 0.2, 0.1], $retried, 'Provider embedding should return the successful retry response.');
} finally {
    if ($modelId !== null) {
        $db->execute('DELETE FROM models WHERE id = ?', [$modelId]);
    }
    if ($providerId !== null) {
        $db->execute('DELETE FROM providers WHERE id = ?', [$providerId]);
    }
}
