<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$kernelSource = file_get_contents($root . '/app/Bootstrap/Kernel.php') ?: '';
$adminControllerSource = file_get_contents($root . '/app/Http/Controllers/AdminController.php') ?: '';
$serviceSource = file_get_contents($root . '/app/Services/AdminChatSessionService.php') ?: '';

assert_true(str_contains($kernelSource, "get('/admin-api/chat-session/models'"), 'Kernel should expose the admin chat model list endpoint.');
assert_true(str_contains($kernelSource, "post('/admin-api/chat-session/messages'"), 'Kernel should expose the admin chat message endpoint.');
assert_true(str_contains($kernelSource, "post('/admin-api/chat-session/stream'"), 'Kernel should expose the admin chat streaming endpoint.');
assert_true(str_contains($kernelSource, '[$admin, \'chatSessionModels\'], [new AdminAuthMiddleware()]'), 'Model list endpoint should require admin session auth.');
assert_true(str_contains($kernelSource, '[$admin, \'chatSessionMessage\'], [new AdminAuthMiddleware(), new CsrfMiddleware()]'), 'Message endpoint should require admin session auth and CSRF.');
assert_true(str_contains($kernelSource, '[$admin, \'chatSessionStream\'], [new AdminAuthMiddleware(), new CsrfMiddleware()]'), 'Streaming endpoint should require admin session auth and CSRF.');

assert_true(str_contains($adminControllerSource, 'AdminChatSessionService'), 'Admin controller should use the admin chat session service.');
assert_true(str_contains($adminControllerSource, 'chatSessionModels'), 'Admin controller should expose chat session model listing.');
assert_true(str_contains($adminControllerSource, 'chatSessionMessage'), 'Admin controller should expose chat session message sending.');
assert_true(str_contains($adminControllerSource, 'chatSessionStream'), 'Admin controller should expose chat session streaming.');
assert_true(str_contains($adminControllerSource, 'Response::sseHeaders()'), 'Admin chat streaming should respond as SSE.');
assert_true(str_contains($serviceSource, 'public function stream('), 'Admin chat service should support true provider streaming.');
assert_true(str_contains($adminControllerSource, '->stream($request->json()'), 'Admin chat streaming should use the true provider streaming path.');
assert_true(!str_contains($adminControllerSource, '$result = $service->send($request->json());'), 'Admin chat streaming must not fake streaming from a buffered JSON response.');

assert_true(str_contains($serviceSource, 'chatModelWhereClause'), 'Chat session service should share model visibility rules between listing and routing.');
assert_true(str_contains($serviceSource, 'm.capability = \"text_generation\"'), 'Chat session service should only list text generation models.');
assert_true(str_contains($serviceSource, 'p.protocol = \"openai\"'), 'Chat session service should only list OpenAI-compatible providers.');
assert_true(str_contains($serviceSource, 'm.name NOT LIKE \"gap-chat-%\"'), 'Chat session service should hide synthetic gap test models.');
assert_true(str_contains($serviceSource, 'base_url) NOT LIKE \"%example%\"'), 'Chat session service should hide placeholder example providers.');
assert_true(str_contains($serviceSource, 'ProviderHttpClient'), 'Chat session service should reuse the existing provider HTTP client.');
assert_true(str_contains($adminControllerSource, 'data: [DONE]'), 'Admin chat streaming should terminate with an SSE done marker.');
assert_true(str_contains($serviceSource, 'temperature'), 'Chat session service should forward a temperature setting.');
assert_true(str_contains($serviceSource, 'max_tokens'), 'Chat session service should forward max_tokens.');
