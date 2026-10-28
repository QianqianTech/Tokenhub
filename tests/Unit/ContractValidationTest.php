<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AuditService;
use TokenHub\Services\ConfigWriteValidator;
use TokenHub\Services\Gateway\ToolCallContractValidator;
use TokenHub\Services\Gateway\UsageNormalizer;
use TokenHub\Services\RequestDiagnosticsService;

$validator = new ToolCallContractValidator();
$tools = [['type' => 'function', 'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']]]];

// Multi-turn tool calling (TH-020)
$valid = $validator->validate(UsageNormalizer::KIND_CHAT, ['tools' => $tools, 'messages' => [
    ['role' => 'user', 'content' => 'q'],
    ['role' => 'assistant', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}']]]],
    ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'r'],
]]);
assert_same(true, $valid['ok'], 'Tool results that follow their call are valid.');
$orphan = $validator->validate(UsageNormalizer::KIND_CHAT, ['messages' => [['role' => 'tool', 'tool_call_id' => 'call_x', 'content' => 'r']]]);
assert_same('TOOL_CALL_MISMATCH', $orphan['code'] ?? null, 'Orphan tool results are rejected.');
assert_same('TOOL_CHOICE_UNKNOWN', $validator->validate(UsageNormalizer::KIND_CHAT, ['tools' => $tools, 'tool_choice' => ['type' => 'function', 'function' => ['name' => 'other']]])['code'] ?? null, 'Forced tool must be declared.');
assert_same('RESPONSE_FORMAT_INVALID', $validator->validate(UsageNormalizer::KIND_CHAT, ['response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'x']]])['code'] ?? null, 'json_schema needs a schema.');
$anthropic = $validator->validate(UsageNormalizer::KIND_ANTHROPIC, ['messages' => [
    ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => []]]],
    ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'ok']]],
]]);
assert_same(true, $anthropic['ok'], 'Anthropic tool_result pairing is accepted.');
assert_same('TOOL_CALL_MISMATCH', $validator->validate(UsageNormalizer::KIND_ANTHROPIC, ['messages' => [['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_9']]]]])['code'] ?? null, 'Anthropic orphan tool_result is rejected.');
assert_same('TOOL_CALL_MISMATCH', $validator->validate(UsageNormalizer::KIND_RESPONSES, ['input' => [['type' => 'function_call_output', 'call_id' => 'c1', 'output' => 'x']]])['code'] ?? null, 'Responses orphan function_call_output is rejected.');
assert_same(true, $validator->validate(UsageNormalizer::KIND_RESPONSES, ['previous_response_id' => 'resp_1', 'input' => [['type' => 'function_call_output', 'call_id' => 'c1', 'output' => 'x']]])['ok'], 'With previous_response_id the call may live upstream.');

// Provider configuration validation (TH-027) - checks that need no database
$config = new ConfigWriteValidator();
$codes = static fn (array $errors): array => array_map(static fn (array $error): string => $error['field'] . ':' . $error['code'], $errors);
assert_same([], $config->provider(['name' => 'OpenRouter', 'protocol' => 'openai', 'base_url' => 'https://openrouter.ai/api/v1', 'timeout_ms' => 60000, 'retry_count' => 2, 'default_headers' => '{"HTTP-Referer":"https://tokenhub.example","tokenhub_client_routing":"restricted"}']), 'A valid provider passes.');
assert_true(in_array('base_url:ENDPOINT_IN_BASE_URL', $codes($config->provider(['name' => 'x', 'protocol' => 'openai', 'base_url' => 'https://api.openai.com/v1/chat/completions'])), true), 'Full endpoint paths are rejected as base_url.');
assert_true(in_array('base_url:CREDENTIALS_IN_URL', $codes($config->provider(['name' => 'x', 'protocol' => 'openai', 'base_url' => 'https://user:secret@api.example.com'])), true), 'Credentials in the URL are rejected.');
assert_true(in_array('protocol:UNSUPPORTED', $codes($config->provider(['name' => 'x', 'protocol' => 'native', 'base_url' => 'https://a.example'])), true), 'New native providers are refused (no executor).');
assert_true(in_array('default_headers:INVALID_HEADER', $codes($config->provider(['default_headers' => ['X-Bad' => "a\r\nInjected: 1"]], ['name' => 'x', 'protocol' => 'openai', 'base_url' => 'https://a.example'])), true), 'Header injection is rejected.');
assert_true(in_array('retry_count:OUT_OF_RANGE', $codes($config->provider(['retry_count' => 50], ['name' => 'x', 'protocol' => 'openai', 'base_url' => 'https://a.example'])), true), 'retry_count is bounded.');

// Config invalidation triggers (TH-022 / TH-026)
foreach (['api_key.update', 'api_key.models.replace', 'user.status.update', 'department.move', 'admin.update', 'admin.models.batch', 'provider.models.import'] as $action) {
    assert_true(AuditService::changesRuntimeConfig($action), "{$action} must invalidate runtime caches.");
}
assert_same(false, AuditService::changesRuntimeConfig('asset.download'), 'Read-only audit actions do not bump the config generation.');

// Diagnostics catalog covers every gateway error code (TH-028)
foreach (['MODEL_FORBIDDEN', 'QUOTA_EXCEEDED', 'OUTBOUND_BLOCKED', 'PROVIDER_RATE_LIMITED', 'PROVIDER_STREAM_INCOMPLETE', 'ROUTING_PARAMETER_FORBIDDEN', 'TOOL_CALL_MISMATCH', 'CAPABILITY_UNAVAILABLE', 'PRICE_UNKNOWN', 'CLIENT_CANCELLED'] as $code) {
    $entry = RequestDiagnosticsService::CATALOG[$code] ?? null;
    assert_true(is_array($entry) && $entry['cause'] !== '' && $entry['action'] !== '', "{$code} has a cause and an action.");
}
