<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * Vector features (2026-11-04) on SQLite + fake Qdrant:
 *  1. thresholds: per vector config (config_json.min_vector_score), a knowledge base value wins,
 *     a rerank model with a minimum score is the final gate, and `calibrate` suggests a value;
 *  2. image vectors from multimodal embedding models (Jina / Ark / DashScope request shapes,
 *     stored image pixels, text-to-image and image-to-image queries);
 *  3. inputs over the model's max_input_tokens become several vectors; chunks are capped;
 *  4. compact float32 storage of vector copies, readable next to old JSON rows.
 */

use TokenHub\Services\ProviderHttpClient;
use TokenHub\Services\QdrantVectorStore;
use TokenHub\Services\RagChunkingService;
use TokenHub\Services\RagEmbeddingJobService;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagIndexMaintenanceService;
use TokenHub\Services\RagMultimodalEmbedding;
use TokenHub\Services\RagRerankService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Services\RagUploadService;
use TokenHub\Services\RagVectorCodec;
use TokenHub\Services\RagVectorConfigService;

$pdo = rag_sqlite_fixture();
$qdrant = new RagFakeQdrant();
RagVectorConfigService::useQdrantFactory(static fn (): QdrantVectorStore => new QdrantVectorStore($qdrant));
RagVectorCodec::useCompact(true);
$storage = sys_get_temp_dir() . '/rag-features-' . bin2hex(random_bytes(4));
putenv('STORAGE_PATH=' . $storage);
$_ENV['STORAGE_PATH'] = $storage;

$pdo->exec("INSERT INTO departments (id, name, path) VALUES (1, 'D', '/1/')");
$pdo->exec("INSERT INTO users (id, department_id, username) VALUES (10, 1, 'u')");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (100, 10, 1, 'k')");
$pdo->exec("INSERT INTO providers (id, name, protocol, base_url, status) VALUES (1, 'p', 'openai', 'https://ark.cn-beijing.volces.com/api/v3', 'active')");
$pdo->exec("INSERT INTO models (id, provider_id, name, external_name, capability, status) VALUES (1, 1, 'emb', 'emb', 'embedding', 'active'), (2, 1, 'rr', 'rr', 'rerank', 'active'), (3, 1, 'doubao-embedding-vision', 'doubao-embedding-vision', 'embedding', 'active')");
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id, visibility) VALUES (1, 'kb_1', 'KB', 1, 'private')");
$pdo->exec("INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (1, 'user', 10, 'write')");
$pdo->exec("INSERT INTO rag_vector_configs (id, config_id, name, modality, provider_id, model_id, model_name, dimension, distance_metric, vector_store, collection_name, batch_size, enabled) VALUES (1, 'cfg', 'text', 'text', 1, 1, 'emb', 4, 'cosine', 'qdrant', 'col_text', 16, 1)");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name, text_vector_config_id) VALUES (1, 'ds_1', 1, 'DS1', 1)");

$embedded = [];
$embedder = new RagEmbeddingProviderService(static function (string $modality, array $input) use (&$embedded): array {
    $embedded[] = (string) $input['text'];
    $hash = crc32((string) $input['text']);
    return [1.0, ($hash % 97) / 97, ($hash % 89) / 89, 0.5];
});
$jobs = new RagEmbeddingJobService($embedder, static function (array $message): void {
});
$uploads = new RagUploadService($jobs);
$apiKey = ['id' => 100, 'user_id' => 10, 'department_id' => 1];
$dataset = static fn (): array => $pdo->query("SELECT d.*, k.kb_id AS external_kb_id, k.retrieval_profile AS kb_retrieval_profile FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = 1")->fetch();
$index = static function (string $file, string $markdown) use ($uploads, $jobs, $apiKey, $dataset, $pdo): void {
    $upload = $uploads->upload($apiKey, 'kb_1', ['filename' => $file, 'content_base64' => base64_encode($markdown), 'processing' => ['target_dataset_id' => 'ds_1']]);
    $uploads->confirm($apiKey, (string) $upload['upload_id'], ['dataset_id' => 'ds_1']);
    $jobs->processUpload((int) $pdo->query("SELECT id FROM rag_uploads WHERE upload_id = '{$upload['upload_id']}'")->fetchColumn(), $dataset());
};
$index('cooling.md', "# 冷却手册\n\n## 冷却\n\n温度超过 80 度时停机检查冷却单元。\n\n## 风扇\n\n风扇异响时更换轴承。\n");

// ------------------------------------------------------------------ 4. compact storage
$row = $pdo->query('SELECT vector_json, vector_f32, dimension FROM rag_vector_records LIMIT 1')->fetch();
assert_same(null, $row['vector_json'], 'New vectors are not stored as JSON (V-12).');
assert_same(4, count(RagVectorCodec::decode($row) ?? []), 'The compact copy decodes to the full vector.');
assert_true(strlen((string) $row['vector_f32']) < strlen((string) json_encode(RagVectorCodec::decode($row))), 'The compact form is smaller than JSON.');
$roundTrip = RagVectorCodec::decode(['vector_f32' => RagVectorCodec::encode([0.1, -0.25, 1.0])]);
assert_true(abs($roundTrip[0] - 0.1) < 1e-6 && $roundTrip[1] === -0.25 && $roundTrip[2] === 1.0, 'float32 round trip keeps embedding precision.');
$pdo->exec("INSERT INTO rag_vector_records (vector_id, collection_name, kb_id, dataset_id, chunk_id, vector_config_id, modality, dimension, vector_json, content_hash, status, index_status) VALUES ('vec_legacy', 'col_text', 1, 1, 999, 1, 'text', 3, '[0.5, 0.25, 0.125]', 'h_legacy', 'indexed', 'indexed')");
assert_same([0.5, 0.25, 0.125], RagVectorCodec::decode($pdo->query("SELECT vector_json, vector_f32 FROM rag_vector_records WHERE vector_id = 'vec_legacy'")->fetch()), 'Old JSON rows stay readable.');
assert_same(['converted' => 1, 'invalid' => 0], RagVectorCodec::compactStoredVectors(), 'compact-vectors converts old JSON rows.');
assert_same(null, $pdo->query("SELECT vector_json FROM rag_vector_records WHERE vector_id = 'vec_legacy'")->fetchColumn(), 'The JSON copy is dropped after conversion.');
$before = count($embedded);
$index('cooling-copy.md', "# 冷却手册\n\n## 冷却\n\n温度超过 80 度时停机检查冷却单元。\n");
assert_true(count($embedded) - $before < 2, 'Identical inputs are reused from compact copies.');

// ------------------------------------------------------------------ 1. thresholds
$apply = new ReflectionMethod(RagRetrievalService::class, 'applyConfidenceThresholds');
$source = ['chunk_id' => 'c', 'dataset_id' => 'ds_1', 'retrieval_strategy' => 'vector', 'title' => '', 'content' => '', 'rank_signals' => ['vector_score' => 0.30]];
$retrieval = new RagRetrievalService($embedder);
$profile = ['original_query' => 'q', 'expanded_query' => 'q', 'terms' => [], 'rewrite_terms' => [], 'phrases' => [], 'codes' => []];
assert_same(0, count($apply->invoke($retrieval, [$source], [$dataset()], $profile)), 'Default threshold 0.35 drops a 0.30 hit.');
assert_same(1, count($apply->invoke($retrieval, [[...$source, 'config_min_vector_score' => 0.25]], [$dataset()], $profile)), 'The vector config threshold applies (calibrated per model).');
assert_same(1, count($apply->invoke($retrieval, [$source], [$dataset()], $profile, true)), 'As a rerank pre-filter the threshold is loosened.');
$pdo->exec("UPDATE rag_knowledge_bases SET retrieval_profile = '{\"min_vector_score\": 0.5}' WHERE id = 1");
assert_same(0, count($apply->invoke($retrieval, [[...$source, 'rank_signals' => ['vector_score' => 0.4], 'config_min_vector_score' => 0.25]], [$dataset()], $profile)), 'A knowledge base threshold wins over the vector config.');
$pdo->exec("UPDATE rag_knowledge_bases SET retrieval_profile = NULL WHERE id = 1");
assert_same(0.2, RagVectorConfigService::minVectorScore(['modality' => 'image', 'config_json' => '{"embedding_api": "ark_multimodal"}']), 'Cross-modal configs default to a lower threshold.');

// Rerank with a minimum score is the final gate: candidates under the vector threshold reach it.
$pdo->exec("UPDATE rag_vector_configs SET config_json = '{\"min_vector_score\": 0.999}' WHERE id = 1");
$strict = (new RagRetrievalService($embedder))->searchSourcesForDatasets([$dataset()], 'xyz 无关 查询', 5);
assert_same([], array_filter($strict, static fn (array $s): bool => $s['retrieval_strategy'] === 'vector'), 'Without rerank, a strict vector threshold filters vector-only hits.');
$reranked = [];
$rerank = new RagRerankService(static function (array $route, string $path, array $payload) use (&$reranked): array {
    $reranked[] = count($payload['documents']);
    return ['ok' => true, 'status' => 200, 'json' => ['results' => array_map(static fn (int $i): array => ['index' => $i, 'relevance_score' => 0.9 - $i * 0.1], array_keys($payload['documents']))]];
});
$pdo->exec("UPDATE rag_knowledge_bases SET retrieval_profile = '{\"rerank\": {\"model_id\": 2, \"min_score\": 0.5}}' WHERE id = 1");
$gated = (new RagRetrievalService($embedder, null, null, null, $rerank))->searchSourcesForDatasets([$dataset()], 'xyz 无关 查询', 5);
assert_true(($reranked[0] ?? 0) >= 2, 'The rerank model sees candidates the strict vector threshold would have dropped.');
assert_true($gated !== [] && isset($gated[0]['rank_signals']['model_rerank_score']), 'Results come from the rerank gate.');
$pdo->exec("UPDATE rag_knowledge_bases SET retrieval_profile = NULL WHERE id = 1");
$pdo->exec("UPDATE rag_vector_configs SET config_json = NULL WHERE id = 1");

// calibrate: relevant hits score 0.9 / 0.8, everything else lower.
$chunks = $pdo->query("SELECT chunk_id FROM rag_chunks WHERE lifecycle_status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
for ($i = 0; $i < 12; $i++) {
    $pdo->exec("INSERT INTO rag_eval_cases (case_id, kb_id, dataset_id, query, expected_chunk_ids, status) VALUES ('case_{$i}', 1, 1, 'q{$i}', '[\"{$chunks[$i % 2]}\"]', 'active')");
}
$maintenance = new RagIndexMaintenanceService($jobs, null, null, static fn (string $query): array => [1.0, 0.0, 0.0, 0.0]);
$points = &$qdrant->collections['col_text']['points'];
$targets = [$chunks[0] => [0.9, 0.436, 0.0, 0.0], $chunks[1] => [0.8, 0.6, 0.0, 0.0]];
foreach ($points as &$point) {
    $point['vector'] = $targets[$point['payload']['chunk_id']] ?? [0.3, 0.954, 0.0, 0.0];
}
unset($point, $points);
$report = $maintenance->calibrate(1, 0.95);
assert_same(0.8, $report['suggested_threshold'], 'The suggestion keeps 95% of the relevant hits.');
assert_same(false, $report['applied'], 'Without --apply nothing is stored.');
$report = $maintenance->calibrate(1, 0.95, true);
assert_same(0.8, RagVectorConfigService::minVectorScore($pdo->query('SELECT * FROM rag_vector_configs WHERE id = 1')->fetch()), '--apply stores the threshold on the vector config.');
$few = $maintenance->calibrate(1, 0.95, false, 100);
assert_same(null, $few['suggested_threshold'], 'Too few samples give no suggestion.');
$pdo->exec("UPDATE rag_vector_configs SET config_json = NULL WHERE id = 1");

// ------------------------------------------------------------------ 3. input length
$pdo->exec('UPDATE rag_vector_configs SET max_input_tokens = 40 WHERE id = 1');
$embedded = [];
$long = str_repeat('冷却液需要每季度检查一次液位。', 12);
$index('long.md', "# 维护规程\n\n## 冷却液\n\n{$long}\n");
$parts = array_values(array_filter($embedded, static fn (string $text): bool => str_contains($text, '冷却液需要')));
assert_true(count($parts) >= 3, 'A chunk over max_input_tokens becomes several vectors.');
foreach ($parts as $part) {
    assert_true(str_starts_with($part, '维护规程 > 冷却液'), 'Every part keeps the document > section context.');
    assert_true(\TokenHub\Services\RagStructuredChunkerService::estimateTokens($part) <= 40, 'Every part fits the model input.');
}
$suffixes = $pdo->query("SELECT vector_id FROM rag_vector_records WHERE vector_id LIKE '%\\_p2' ESCAPE '\\'")->fetchAll(PDO::FETCH_COLUMN);
assert_true($suffixes !== [], 'Extra parts are stored as _p2, _p3 ... vectors of the same chunk.');
$cap = new ReflectionMethod(RagChunkingService::class, 'capToModel');
$capped = $cap->invoke(new RagChunkingService(), ['target_tokens' => 400, 'max_tokens' => 600, 'overlap_tokens' => 60, 'min_tokens' => 40, 'version' => 'p2-v1'], 512);
assert_same(412, $capped['max_tokens'], 'Chunks are capped below the model input limit, leaving room for the context line.');
assert_true($capped['target_tokens'] <= $capped['max_tokens'], 'The target stays under the cap.');
$pdo->exec('UPDATE rag_vector_configs SET max_input_tokens = NULL WHERE id = 1');

// ------------------------------------------------------------------ 2. multimodal embeddings
$multimodal = new RagMultimodalEmbedding();
$route = ['base_url' => 'https://ark.cn-beijing.volces.com/api/v3', 'name' => 'doubao-embedding-vision', 'external_name' => ''];
$parts = [['type' => 'image', 'value' => 'data:image/png;base64,AAAA'], ['type' => 'text', 'value' => '接线图']];
$ark = $multimodal->request($route, ['config_json' => '{"embedding_api": "ark_multimodal"}'], $parts, 'document');
assert_same('https://ark.cn-beijing.volces.com/api/v3/embeddings/multimodal', $ark['endpoint'], 'Ark multimodal endpoint.');
assert_same([['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']], ['type' => 'text', 'text' => '接线图']], $ark['payload']['input'], 'Ark input items.');
$dash = $multimodal->request(['base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1', 'name' => 'tongyi-embedding-vision-plus'], ['config_json' => '{"embedding_api": "dashscope_multimodal", "request_params": {"dimension": 1024}}'], $parts, 'document');
assert_same('https://dashscope.aliyuncs.com/api/v1/services/embeddings/multimodal-embedding/multimodal-embedding', $dash['endpoint'], 'DashScope endpoint on the provider host.');
assert_same(['contents' => [['image' => 'data:image/png;base64,AAAA'], ['text' => '接线图']]], $dash['payload']['input'], 'DashScope contents.');
assert_same(['dimension' => 1024], $dash['payload']['parameters'], 'DashScope parameters.');
$jina = $multimodal->request(['base_url' => 'https://api.jina.ai/v1', 'name' => 'jina-clip-v2'], ['config_json' => '{"embedding_api": "openai_mixed", "query_params": {"task": "retrieval.query"}}'], [['type' => 'text', 'value' => 'q']], 'query');
assert_same(['endpoint' => '/v1/embeddings', 'payload' => ['model' => 'jina-clip-v2', 'input' => [['text' => 'q']], 'task' => 'retrieval.query']], $jina, 'Jina-style request with query parameters.');
assert_same([[0.1, 0.2]], $multimodal->vectors(['config_json' => '{"embedding_api": "ark_multimodal"}'], ['data' => ['embedding' => [0.1, 0.2]]]), 'Ark single fused vector.');
assert_same([[1.0], [2.0]], $multimodal->vectors(['config_json' => '{"embedding_api": "dashscope_multimodal"}'], ['output' => ['embeddings' => [['index' => 1, 'embedding' => [2]], ['index' => 0, 'embedding' => [1]]]]]), 'DashScope vectors in input order.');
$combined = RagMultimodalEmbedding::combine([[1.0, 0.0], [0.0, 1.0]]);
assert_true(abs($combined[0] - sqrt(0.5)) < 1e-9 && abs($combined[1] - sqrt(0.5)) < 1e-9, 'Separate image and text vectors combine into one unit vector.');
assert_same('https://x/api', (new ProviderHttpClient())->endpointFor('https://x/api'), 'Absolute vendor endpoints pass through.');

// Stored image pixels are sent (downscaled), the query image too; text alone finds images.
$imageDir = $storage . '/rag/images';
mkdir($imageDir, 0775, true);
$image = imagecreatetruecolor(1600, 1200);
imagefilledrectangle($image, 0, 0, 1599, 1199, imagecolorallocate($image, 30, 120, 200));
imagejpeg($image, $imageDir . '/wiring.jpg');
$sent = [];
$visionProvider = new RagEmbeddingProviderService(null, static function (array $route, string $endpoint, array $payload) use (&$sent): array {
    $sent[] = ['endpoint' => $endpoint, 'payload' => $payload];
    return ['ok' => true, 'status' => 200, 'json' => ['data' => ['embedding' => [0.6, 0.8, 0.0, 0.0]]]];
}, new \TokenHub\Services\RagEmbeddingMeter(), [$storage]);
$imageConfig = ['id' => 5, 'modality' => 'image', 'provider_id' => 1, 'model_id' => 3, 'dimension' => 4, 'image_max_pixels' => 300000, 'config_json' => '{"embedding_api": "ark_multimodal"}'];
$vector = $visionProvider->embed('image', ['text' => '配电柜接线图', 'image_path' => $imageDir . '/wiring.jpg'], $imageConfig);
assert_same([0.6, 0.8, 0.0, 0.0], $vector, 'The multimodal model returns the image vector.');
assert_same('https://ark.cn-beijing.volces.com/api/v3/embeddings/multimodal', $sent[0]['endpoint'], 'Sent to the Ark multimodal endpoint.');
$item = $sent[0]['payload']['input'][0];
assert_same('image_url', $item['type'], 'The stored image itself is sent.');
assert_same(1, count($sent[0]['payload']['input']), 'An image config sends only the image.');
$size = getimagesizefromstring((string) base64_decode(substr($item['image_url']['url'], strpos($item['image_url']['url'], ',') + 1)));
assert_true($size[0] * $size[1] <= 300000, 'The image is downscaled to image_max_pixels.');
$visionProvider->embed('multimodal', ['text' => '配电柜接线图', 'image_path' => $imageDir . '/wiring.jpg'], [...$imageConfig, 'modality' => 'multimodal']);
assert_same(['image_url', 'text'], array_column($sent[1]['payload']['input'], 'type'), 'A multimodal config sends image and text.');
$visionProvider->embed('image', ['text' => '接线图在哪里', 'purpose' => 'query'], $imageConfig);
assert_same([['type' => 'text', 'text' => '接线图在哪里']], $sent[2]['payload']['input'], 'A text query is embedded by the same model (text-to-image).');
$visionProvider->embed('image', ['text' => '', 'image' => 'https://example.com/q.jpg', 'purpose' => 'query'], $imageConfig);
assert_same('https://example.com/q.jpg', $sent[3]['payload']['input'][0]['image_url']['url'], 'A query image is embedded (image-to-image).');

assert_same('data:image/png;base64,QUJD', RagMultimodalEmbedding::queryImage(['messages' => [
    ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,OLD']]]],
    ['role' => 'assistant', 'content' => 'ok'],
    ['role' => 'user', 'content' => [['type' => 'text', 'text' => '这个零件是什么？'], ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'QUJD']]]],
]]), 'The query image comes from the latest user message (Anthropic shape).');
assert_same(null, RagMultimodalEmbedding::queryImage(['messages' => [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'file:///etc/passwd']]]]]]), 'Only http(s) and data:image URLs are accepted.');

$pdo->exec("INSERT INTO rag_vector_configs (id, config_id, name, modality, provider_id, model_id, model_name, dimension, distance_metric, vector_store, collection_name, enabled, config_json) VALUES (5, 'img', 'image', 'image', 1, 3, 'doubao-embedding-vision', 4, 'cosine', 'qdrant', 'col_image', 1, '{\"embedding_api\": \"ark_multimodal\"}')");
$pdo->exec('UPDATE rag_vector_datasets SET image_vector_config_id = 5 WHERE id = 1');
$configsFor = new ReflectionMethod(RagRetrievalService::class, 'retrievalConfigs');
assert_same(['text', 'image'], array_column($configsFor->invoke(new RagRetrievalService(), new RagVectorConfigService(), $dataset(), 'text'), 'modality'), 'Text queries also search image vectors of a multimodal model.');
rag_expect_abort(static fn () => (new RagVectorConfigService())->normalizeForWrite(['config_id' => 'bad', 'name' => 'bad', 'modality' => 'text', 'provider_id' => 1, 'model_id' => 1, 'dimension' => 4, 'distance_metric' => 'cosine', 'vector_store' => 'qdrant', 'collection_name' => 'col_text', 'config_json' => '{"embedding_api": "ark_multimodal"}']), 'RAG_VECTOR_CONFIG_INVALID', 'A text config cannot use a multimodal API.');

echo "PASS\n";
