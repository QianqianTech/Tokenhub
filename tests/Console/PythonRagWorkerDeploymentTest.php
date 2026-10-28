<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$compose = file_get_contents($root . '/docker-compose.yml') ?: '';

foreach ([
    'rag-worker:',
    'build:',
    'context: ./python-rag-worker',
    'container_name: tokenhub-rag-worker',
    'python -m tokenhub_rag_worker',
    'mysql:',
    'redis:',
    'qdrant:',
    'DB_HOST: mysql',
    'DB_DATABASE: ${DB_DATABASE:-tokenhub}',
    'DB_USERNAME: ${DB_USERNAME:-tokenhub}',
    'DB_PASSWORD: ${DB_PASSWORD:-tokenhub_secret}',
    'REDIS_HOST: redis',
    'QDRANT_URL: ${QDRANT_URL:-http://qdrant:6333}',
    'QDRANT_COLLECTION_PREFIX: ${QDRANT_COLLECTION_PREFIX:-tokenhub_rag}',
    'RAG_WORKER_POLL_INTERVAL_SECONDS: ${RAG_WORKER_POLL_INTERVAL_SECONDS:-2}',
    'RAG_WORKER_BATCH_SIZE: ${RAG_WORKER_BATCH_SIZE:-5}',
    './storage:/srv/app/storage',
] as $needle) {
    assert_true(str_contains($compose, $needle), 'docker-compose.yml should include Python RAG worker setting: ' . $needle);
}

$workerDir = $root . '/python-rag-worker';
foreach ([
    'Dockerfile',
    'requirements.txt',
    'README.md',
    'tokenhub_rag_worker/__main__.py',
    'tokenhub_rag_worker/config.py',
    'tokenhub_rag_worker/db.py',
    'tokenhub_rag_worker/tasks.py',
    'tokenhub_rag_worker/worker.py',
] as $workerRelativePath) {
    assert_true(is_file($workerDir . '/' . $workerRelativePath), 'python-rag-worker should include ' . $workerRelativePath . '.');
}

$dockerfile = file_get_contents($workerDir . '/Dockerfile') ?: '';
foreach ([
    'FROM python:',
    'WORKDIR /worker',
    'pip install --no-cache-dir -r requirements.txt',
    'CMD ["python", "-m", "tokenhub_rag_worker"]',
] as $needle) {
    assert_true(str_contains($dockerfile, $needle), 'Python worker Dockerfile should include ' . $needle . '.');
}

$requirements = file_get_contents($workerDir . '/requirements.txt') ?: '';
foreach (['mysql-connector-python', 'redis', 'requests', 'Pillow'] as $needle) {
    assert_true(str_contains($requirements, $needle), 'Python worker requirements should include ' . $needle . '.');
}

$source = '';
foreach (glob($workerDir . '/tokenhub_rag_worker/*.py') ?: [] as $pythonSourcePath) {
    $source .= "\n" . (file_get_contents($pythonSourcePath) ?: '');
}

foreach ([
    'rag_embedding_jobs',
    'rag_image_assets',
    'rag_vector_records',
    'rag_chunks',
    'QDRANT_URL',
    'claim_next_job',
    'process_text_embedding',
    'process_image_embedding',
    'process_multimodal_embedding',
    'process_ocr',
    'process_caption',
    'upsert_qdrant',
    'refresh_upload_status',
    'UPDATE rag_uploads',
    'UPDATE rag_vector_datasets',
    'deterministic_embedding',
    'RAG_MODEL_NOT_CONFIGURED',
    'RAG_IMAGE_EMBEDDING_FAILED',
    'RAG_MULTIMODAL_EMBEDDING_FAILED',
    "execution_backend = 'python_local'",
    'vector_config_id',
    'collection_name',
    'external_dataset_id',
    'external_kb_id',
    'qdrant_id',
    'zlib.crc32',
    'sanitize_identifier',
    'lock_token',
    'FOR UPDATE SKIP LOCKED',
    'job_lease_seconds',
    'TIMESTAMPDIFF(SECOND, locked_at, NOW())',
] as $needle) {
    assert_true(str_contains($source, $needle), 'Python worker source should include ' . $needle . '.');
}

assert_true(str_contains($source, 'qdrant_id(vector_id)'), 'Python worker should map business vector IDs to Qdrant UUID point IDs.');
assert_true(str_contains($source, 'def qdrant_id('), 'Python worker should use the shared deterministic UUID v5 mapping.');
assert_true(!str_contains($source, 'hashlib.sha256'), 'Python fallback embeddings should not diverge from the PHP CRC32 vector space.');
assert_true(str_contains($source, 'redis_password: str = os.getenv("REDIS_PASSWORD", "")'), 'Python worker config should load Redis authentication credentials.');
assert_true(str_contains($source, 'password=self.config.redis_password'), 'Python worker Redis connections should use configured authentication credentials.');

$docs = file_get_contents($root . '/docs/development-tasks/15-rag-python-worker-phase4-summary.md') ?: '';
foreach ([
    'PHP 继续做主系统',
    'Python RAG Worker',
    'rag_embedding_jobs',
    'Qdrant',
    '不新增普通用户登录',
] as $needle) {
    assert_true(str_contains($docs, $needle), 'Phase 4 summary should include ' . $needle . '.');
}
