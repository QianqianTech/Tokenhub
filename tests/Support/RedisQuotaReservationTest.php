<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\App;

$key = 'test:quota-reservation:' . bin2hex(random_bytes(6));
App::redis()->delete($key);

$first = App::redis()->reserveCounters([['key' => $key, 'amount' => 6, 'limit' => 10, 'ttl' => 60]]);
$second = App::redis()->reserveCounters([['key' => $key, 'amount' => 5, 'limit' => 10, 'ttl' => 60]]);

assert_same(true, $first['allowed'], 'A reservation inside the quota limit should be accepted.');
assert_same(false, $second['allowed'], 'A reservation above the quota limit must be rejected atomically.');
assert_same(6.0, (float) App::redis()->get($key), 'A rejected reservation must leave all counters unchanged.');

App::redis()->delete($key);
