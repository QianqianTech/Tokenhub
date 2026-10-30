<?php

declare(strict_types=1);

return [
    'vector_store' => [
        'primary' => env('RAG_VECTOR_STORE', 'qdrant'),
        'fallback' => env('RAG_VECTOR_FALLBACK', 'mysql_fallback'),
    ],
    'embedding' => [
        'allow_local_fallback' => (bool) env('RAG_ALLOW_LOCAL_EMBEDDING_FALLBACK', false),
        'job_lease_seconds' => max(300, (int) env('RAG_EMBEDDING_JOB_LEASE_SECONDS', 900)),
        'recovery_interval_seconds' => max(1, (int) env('RAG_EMBEDDING_RECOVERY_INTERVAL_SECONDS', 5)),
        'dispatch_lease_seconds' => max(10, (int) env('RAG_EMBEDDING_DISPATCH_LEASE_SECONDS', 60)),
        'retry_base_seconds' => max(1, (int) env('RAG_EMBEDDING_RETRY_BASE_SECONDS', 5)),
        'batch_size' => max(1, min(64, (int) env('RAG_EMBEDDING_BATCH_SIZE', 16))),
    ],
    'qdrant' => [
        'url' => rtrim((string) env('QDRANT_URL', 'http://qdrant:6333'), '/'),
        'api_key' => env('QDRANT_API_KEY', ''),
        'collection_prefix' => env('QDRANT_COLLECTION_PREFIX', 'tokenhub_rag'),
        'timeout_seconds' => (float) env('QDRANT_TIMEOUT_SECONDS', 5),
    ],
];
