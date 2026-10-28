<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$compose = file_get_contents($root . '/docker-compose.yml') ?: '';
$envExample = file_get_contents($root . '/.env.example') ?: '';

foreach ([
    'qdrant:',
    'qdrant/qdrant:v',
    'container_name: tokenhub-qdrant',
    '${QDRANT_FORWARD_PORT:-63330}:6333',
    'qdrant_data:/qdrant/storage',
    'qdrant_data:',
    'QDRANT_URL: ${QDRANT_URL:-http://qdrant:6333}',
    'QDRANT_COLLECTION_PREFIX: ${QDRANT_COLLECTION_PREFIX:-tokenhub_rag}',
] as $needle) {
    assert_true(str_contains($compose, $needle), 'docker-compose.yml should include ' . $needle . '.');
}

assert_true(substr_count($compose, 'qdrant:') >= 3, 'PHP and queue-worker should depend on or configure qdrant.');

foreach ([
    'QDRANT_URL=http://qdrant:6333',
    'QDRANT_API_KEY=',
    'QDRANT_COLLECTION_PREFIX=tokenhub_rag',
    'QDRANT_FORWARD_PORT=63330',
] as $needle) {
    assert_true(str_contains($envExample, $needle), '.env.example should include ' . $needle . '.');
}
