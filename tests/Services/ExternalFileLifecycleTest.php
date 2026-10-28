<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\ExternalFileService;

$service = new ExternalFileService();
assert_same(42, $service->cursorId('id_42'), 'File cursors should decode the last asset id.');
assert_same(null, $service->cursorId('bad'), 'Malformed file cursors should be ignored.');
