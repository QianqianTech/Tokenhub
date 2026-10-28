<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\RequestContext;

$context = RequestContext::fromHeaders([]);
assert_true(strlen($context->requestId) >= 16, 'Request id should be generated when missing.');
assert_true(strlen($context->traceId) >= 16, 'Trace id should be generated when missing.');

$provided = RequestContext::fromHeaders([
    'HTTP_X_REQUEST_ID' => 'req-from-client',
    'HTTP_X_TRACE_ID' => 'trace-from-client',
]);
assert_same('req-from-client', $provided->requestId, 'Request id should use incoming header.');
assert_same('trace-from-client', $provided->traceId, 'Trace id should use incoming header.');

