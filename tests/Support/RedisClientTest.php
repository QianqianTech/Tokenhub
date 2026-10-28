<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Support\Config;
use TokenHub\Support\RedisClient;

$redis = new RedisClient(new Config(__DIR__ . '/../fixtures/config', __DIR__ . '/../fixtures/.env'));

if (!$redis->available()) {
    $redis->set('tokenhub:test:ttl', 'value', 1);
    assert_same('value', $redis->get('tokenhub:test:ttl'), 'Memory Redis should return value before TTL expires.');
    sleep(2);
    assert_same(null, $redis->get('tokenhub:test:ttl'), 'Memory Redis should remove value after TTL expires.');
}
