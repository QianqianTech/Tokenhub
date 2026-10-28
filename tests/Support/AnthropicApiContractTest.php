<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$gateway = file_get_contents(__DIR__ . '/../../app/Http/Controllers/GatewayController.php') ?: '';
$providerClient = file_get_contents(__DIR__ . '/../../app/Services/ProviderHttpClient.php') ?: '';
$adapter = file_get_contents(__DIR__ . '/../../app/Services/Providers/AnthropicAdapter.php') ?: '';
$openapi = json_decode((string) file_get_contents(__DIR__ . '/../../public/openapi.json'), true);

assert_true(str_contains($gateway, "'/v1/messages/count_tokens'"), 'GatewayController should expose Anthropic count_tokens endpoint.');
assert_true(str_contains($kernel, 'GatewayController::ENDPOINTS'), 'Kernel should mount all gateway endpoints.');
assert_true(str_contains($providerClient, "str_contains(\$path, '/messages/count_tokens')"), 'Provider endpoint mapping should handle count_tokens before generic messages.');
assert_true(str_contains($adapter, "'anthropic-version' => '2023-06-01'"), 'Anthropic adapter should set the default Anthropic version header.');

assert_true(is_array($openapi), 'OpenAPI document should be valid JSON.');
assert_true(isset($openapi['paths']['/v1/messages']), 'OpenAPI should document Anthropic messages.');
assert_true(isset($openapi['paths']['/v1/messages/count_tokens']), 'OpenAPI should document Anthropic count_tokens.');

$schemas = $openapi['components']['schemas'] ?? [];
foreach ([
    'AnthropicMessageRequest',
    'AnthropicMessageResponse',
    'AnthropicMessageContentBlock',
    'AnthropicTool',
    'AnthropicToolUseBlock',
    'AnthropicToolResultBlock',
    'AnthropicStreamEvent',
    'AnthropicCountTokensResponse',
] as $schema) {
    assert_true(isset($schemas[$schema]), 'OpenAPI should define schema ' . $schema . '.');
}

$messageRequest = $schemas['AnthropicMessageRequest'] ?? [];
assert_true(in_array('model', $messageRequest['required'] ?? [], true), 'Anthropic message request should require model.');
assert_true(in_array('max_tokens', $messageRequest['required'] ?? [], true), 'Anthropic message request should require max_tokens.');
assert_true(in_array('messages', $messageRequest['required'] ?? [], true), 'Anthropic message request should require messages.');
assert_true(isset($messageRequest['properties']['tools']), 'Anthropic message request should document tools.');
assert_true(isset($messageRequest['properties']['tool_choice']), 'Anthropic message request should document tool_choice.');
assert_true(isset($messageRequest['properties']['stream']), 'Anthropic message request should document stream.');

$streamEvent = $schemas['AnthropicStreamEvent'] ?? [];
$eventTypes = $streamEvent['properties']['type']['enum'] ?? [];
foreach (['message_start', 'content_block_start', 'content_block_delta', 'content_block_stop', 'message_delta', 'message_stop', 'ping', 'error'] as $eventType) {
    assert_true(in_array($eventType, $eventTypes, true), 'Anthropic stream event schema should include ' . $eventType . '.');
}

$contentBlock = $schemas['AnthropicMessageContentBlock'] ?? [];
$contentTypes = $contentBlock['properties']['type']['enum'] ?? [];
foreach (['text', 'image', 'tool_use', 'tool_result'] as $contentType) {
    assert_true(in_array($contentType, $contentTypes, true), 'Anthropic content block schema should include ' . $contentType . '.');
}
