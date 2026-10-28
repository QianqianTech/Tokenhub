<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagEmbeddingProviderService;

unset($_ENV['RAG_ALLOW_LOCAL_EMBEDDING_FALLBACK'], $_SERVER['RAG_ALLOW_LOCAL_EMBEDDING_FALLBACK']);
putenv('RAG_ALLOW_LOCAL_EMBEDDING_FALLBACK');

$provider = new RagEmbeddingProviderService();

$thrown = false;
try {
    $provider->embed('text', ['text' => 'alpha beta'], ['dimension' => 3]);
} catch (RuntimeException $exception) {
    $thrown = $exception->getMessage() === 'RAG_MODEL_NOT_CONFIGURED';
}
assert_true($thrown, 'Default RAG embedding provider should not silently use local fallback.');

$explicitFallback = $provider->embed('text', ['text' => 'alpha beta'], ['dimension' => 3, 'allow_local_fallback' => true]);
assert_same(3, count($explicitFallback), 'Explicit local fallback should return configured dimension.');

$configJsonFallback = $provider->embed('text', ['text' => 'alpha beta'], ['dimension' => 4, 'config_json' => json_encode(['allow_local_fallback' => true])]);
assert_same(4, count($configJsonFallback), 'config_json allow_local_fallback should opt in to local fallback.');

$smallDimensionFallback = $provider->embed('text', ['text' => 'alpha beta'], ['dimension' => 2, 'allow_local_fallback' => true]);
assert_same(2, count($smallDimensionFallback), 'Local fallback should honor the same minimum dimension contract as provider vectors.');
