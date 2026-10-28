<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TokenHub\Support\App;

App::bootstrap(dirname(__DIR__));

$mode = $argv[1] ?? 'seed';
$suffix = $argv[2] ?? ('smoke_' . bin2hex(random_bytes(4)));
$db = App::db();

if ($mode === 'seed') {
    $kbId = 'kb_py_' . $suffix;
    $datasetId = 'ds_py_' . $suffix;
    $documentId = 'doc_py_' . $suffix;
    $chunkId = 'chk_py_' . $suffix;
    $jobId = 'job_py_' . $suffix;

    $db->execute(
        'INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")',
        [$kbId, 'Python Worker Smoke KB ' . $suffix]
    );
    $kb = $db->first('SELECT * FROM rag_knowledge_bases WHERE kb_id = ?', [$kbId]);

    $collection = 'tokenhub_rag_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $datasetId) . '_text';
    $db->execute(
        'INSERT INTO rag_vector_datasets (dataset_id, kb_id, name, collection_prefix, status) VALUES (?, ?, ?, ?, "active")',
        [$datasetId, $kb['id'], 'Python Worker Smoke DS ' . $suffix, 'py_' . $suffix]
    );
    $dataset = $db->first('SELECT * FROM rag_vector_datasets WHERE dataset_id = ?', [$datasetId]);

    $db->execute(
        'INSERT INTO rag_documents (document_id, kb_id, upload_id, title, doc_type, index_status, metadata) VALUES (?, ?, 0, ?, "plain_text", "pending", ?)',
        [$documentId, $kb['id'], 'Python Worker Smoke Document', json_encode(['smoke' => true], JSON_UNESCAPED_SLASHES)]
    );
    $document = $db->first('SELECT * FROM rag_documents WHERE document_id = ?', [$documentId]);

    $content = 'TokenHub Python RAG worker smoke test content. It proves text embedding, MySQL vector record, and Qdrant upsert.';
    $db->execute(
        'INSERT INTO rag_chunks (chunk_id, kb_id, document_id, chunk_type, ordinal, title, content, metadata, content_hash, enabled, lifecycle_status) VALUES (?, ?, ?, "text", 1, ?, ?, ?, ?, 1, "active")',
        [
            $chunkId,
            $kb['id'],
            $document['id'],
            'Python Worker Smoke Chunk',
            $content,
            json_encode(['source_uri' => 'smoke://python-rag-worker'], JSON_UNESCAPED_SLASHES),
            hash('sha256', $content),
        ]
    );
    $chunk = $db->first('SELECT * FROM rag_chunks WHERE chunk_id = ?', [$chunkId]);

    $db->execute(
        'INSERT INTO rag_embedding_jobs (job_id, kb_id, dataset_id, document_id, chunk_id, job_type, status, total_items, processed_items, progress_percent) VALUES (?, ?, ?, ?, ?, "text_embedding", "queued", 1, 0, 0)',
        [$jobId, $kb['id'], $dataset['id'], $document['id'], $chunk['id']]
    );

    echo json_encode([
        'suffix' => $suffix,
        'job_id' => $jobId,
        'dataset_id' => $datasetId,
        'chunk_id' => $chunkId,
        'collection' => $collection,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($mode === 'verify') {
    $jobId = 'job_py_' . $suffix;
    $chunkId = 'chk_py_' . $suffix;
    $vectorId = 'vec_text_' . $chunkId;

    $job = $db->first('SELECT job_id, status, error_code, error_message, progress_percent FROM rag_embedding_jobs WHERE job_id = ?', [$jobId]);
    $vector = $db->first('SELECT vector_id, collection_name, modality, dimension, status FROM rag_vector_records WHERE vector_id = ?', [$vectorId]);
    $qdrant = qdrantPoint((string) ($vector['collection_name'] ?? ''), qdrantUuidV5($vectorId), $vectorId);

    $ok = ($job['status'] ?? null) === 'succeeded'
        && ($vector['status'] ?? null) === 'indexed'
        && ($qdrant['ok'] ?? false) === true;

    echo json_encode([
        'ok' => $ok,
        'suffix' => $suffix,
        'job' => $job,
        'vector' => $vector,
        'qdrant' => $qdrant,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit($ok ? 0 : 1);
}

fwrite(STDERR, "Usage: php scripts/rag-python-worker-smoke.php seed|verify [suffix]\n");
exit(2);

/**
 * @return array<string, mixed>
 */
function qdrantPoint(string $collection, string $pointId, string $businessVectorId): array
{
    $baseUrl = rtrim((string) App::config()->get('rag.qdrant.url', 'http://qdrant:6333'), '/');
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $apiKey = (string) App::config()->get('rag.qdrant.api_key', '');
    if ($apiKey !== '') {
        $headers[] = 'api-key: ' . $apiKey;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode(['ids' => [$pointId], 'with_payload' => true, 'with_vector' => false], JSON_UNESCAPED_SLASHES),
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($baseUrl . '/collections/' . rawurlencode($collection) . '/points', false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $result = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];

    return [
        'ok' => count($result) === 1 && (($result[0]['payload']['vector_id'] ?? null) === $businessVectorId),
        'collection' => $collection,
        'point_id' => $pointId,
        'vector_id' => $businessVectorId,
        'result_count' => count($result),
    ];
}

function qdrantUuidV5(string $name): string
{
    $namespace = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';
    $namespaceBytes = hex2bin(str_replace('-', '', $namespace));
    if ($namespaceBytes === false) {
        throw new RuntimeException('Invalid UUID namespace.');
    }
    $hash = sha1($namespaceBytes . $name);
    return sprintf(
        '%08s-%04s-%04x-%04x-%012s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
        (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
        substr($hash, 20, 12)
    );
}
