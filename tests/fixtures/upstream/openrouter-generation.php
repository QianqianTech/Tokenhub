<?php

declare(strict_types=1);

// Fake OpenRouter GET /api/v1/generation for OpenRouterGenerationServiceTest.
$id = (string) ($_GET['id'] ?? '');
header('Content-Type: application/json');
if ($id === 'gen-lagging') {
    http_response_code(404);
    echo '{"error":{"message":"not found"}}';
    return;
}
if ($id === 'gen-broken') {
    http_response_code(500);
    echo '{"error":{"message":"oops"}}';
    return;
}
echo json_encode(['data' => [
    'id' => $id,
    'total_cost' => 0.00123,
    'provider_name' => 'FakeVendor',
    'model' => 'openai/gpt-x',
    'native_tokens_prompt' => 12,
    'native_tokens_completion' => 8,
    'native_tokens_reasoning' => 3,
]]);
