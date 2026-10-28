<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\WebhookTargetValidator;

$private = new WebhookTargetValidator(static fn (string $host): array => ['127.0.0.1']);
assert_same(false, $private->isAllowed('https://internal.example/hook'), 'Webhook targets resolving to loopback addresses must be rejected.');

$public = new WebhookTargetValidator(static fn (string $host): array => ['8.8.8.8']);
assert_same(true, $public->isAllowed('https://public.example/hook'), 'Public HTTPS webhook targets should be accepted.');
assert_same(false, $public->isAllowed('http://public.example/hook'), 'Plain HTTP webhook targets must be rejected.');
