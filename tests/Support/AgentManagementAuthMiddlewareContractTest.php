<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$source = file_get_contents(__DIR__ . '/../../app/Middleware/AgentManagementAuthMiddleware.php') ?: '';

assert_true(str_contains($source, 'final class AgentManagementAuthMiddleware'), 'Agent management authentication middleware should exist.');
assert_true(str_contains($source, 'new ApiKeyAuthMiddleware()'), 'Agent management authentication should reuse API key authentication semantics.');
assert_true(str_contains($source, 'App::context()->apiKey()'), 'Agent management authentication should authorize the API key in request context.');
assert_true(str_contains($source, "Response::error('MANAGEMENT_SCOPE_REQUIRED'"), 'Missing or invalid management scopes must return MANAGEMENT_SCOPE_REQUIRED.');
assert_true(str_contains($source, 'hasScope'), 'Agent management authentication should enforce the requested management scope.');
