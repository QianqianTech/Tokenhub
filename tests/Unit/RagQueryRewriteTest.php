<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * Multi-turn query rewriting: a follow-up question that depends on the conversation is
 * rewritten into a standalone question before retrieval (model), falls back to the previous
 * user question as context when the model is unavailable, and is left alone otherwise.
 */

use TokenHub\Services\QdrantVectorStore;
use TokenHub\Services\RagEmbeddingJobService;
use TokenHub\Services\RagEmbeddingProviderService;
use TokenHub\Services\RagQueryRewriteService;
use TokenHub\Services\RagRetrievalService;
use TokenHub\Services\RagUploadService;
use TokenHub\Services\RagVectorConfigService;

$pdo = rag_sqlite_fixture();
$qdrant = new RagFakeQdrant();
RagVectorConfigService::useQdrantFactory(static fn (): QdrantVectorStore => new QdrantVectorStore($qdrant));
$storage = sys_get_temp_dir() . '/rag-rewrite-' . bin2hex(random_bytes(4));
putenv('STORAGE_PATH=' . $storage);
$_ENV['STORAGE_PATH'] = $storage;

$pdo->exec("INSERT INTO departments (id, name, path) VALUES (1, 'D', '/1/')");
$pdo->exec("INSERT INTO users (id, department_id, username) VALUES (10, 1, 'u')");
$pdo->exec("INSERT INTO api_keys (id, user_id, department_id, name) VALUES (100, 10, 1, 'k')");
$pdo->exec("INSERT INTO providers (id, name, protocol, base_url, status) VALUES (1, 'p', 'openai', 'https://llm.example.com/v1', 'active'), (2, 'a', 'anthropic', 'https://claude.example.com', 'active')");
$pdo->exec("INSERT INTO models (id, provider_id, name, external_name, capability, status) VALUES (1, 1, 'emb', 'emb', 'embedding', 'active'), (7, 1, 'small-chat', 'small-chat', 'text_generation', 'active'), (8, 2, 'small-claude', 'small-claude', 'text_generation', 'active')");
$pdo->exec("INSERT INTO rag_knowledge_bases (id, kb_id, name, department_id, visibility, retrieval_profile) VALUES (1, 'kb_1', 'KB', 1, 'private', '{\"query_rewrite\": {\"model_id\": 7}}')");
$pdo->exec("INSERT INTO rag_knowledge_base_bindings (kb_id, subject_type, subject_id, permission) VALUES (1, 'user', 10, 'write')");
$pdo->exec("INSERT INTO rag_vector_configs (id, config_id, name, modality, provider_id, model_id, model_name, dimension, distance_metric, vector_store, collection_name, enabled) VALUES (1, 'cfg', 'text', 'text', 1, 1, 'emb', 4, 'cosine', 'qdrant', 'col_text', 1)");
$pdo->exec("INSERT INTO rag_vector_datasets (id, dataset_id, kb_id, name, text_vector_config_id) VALUES (1, 'ds_1', 1, 'DS1', 1)");

$queries = [];
$embedder = new RagEmbeddingProviderService(static function (string $modality, array $input) use (&$queries): array {
    if (($input['purpose'] ?? '') === 'query') {
        $queries[] = (string) $input['text'];
    }
    $hash = crc32((string) $input['text']);
    return [1.0, ($hash % 97) / 97, ($hash % 89) / 89, 0.5];
});
$jobs = new RagEmbeddingJobService($embedder, static function (array $message): void {
});
$uploads = new RagUploadService($jobs);
$apiKey = ['id' => 100, 'user_id' => 10, 'department_id' => 1];
$upload = $uploads->upload($apiKey, 'kb_1', ['filename' => 'travel.md', 'content_base64' => base64_encode("# 差旅制度\n\n## 住宿费报销\n\n住宿费凭发票在返回后 15 日内报销。\n"), 'processing' => ['target_dataset_id' => 'ds_1']]);
$uploads->confirm($apiKey, (string) $upload['upload_id'], ['dataset_id' => 'ds_1']);
$jobs->processUpload(1, $pdo->query("SELECT d.*, k.kb_id AS external_kb_id FROM rag_vector_datasets d JOIN rag_knowledge_bases k ON k.id = d.kb_id WHERE d.id = 1")->fetch());

$conversation = static fn (string $last): array => [
    'model' => 'chat',
    'rag' => ['enabled' => true, 'dataset_ids' => ['ds_1']],
    'messages' => [
        ['role' => 'system', 'content' => '你是助手'],
        ['role' => 'user', 'content' => '出差住宿标准是多少？'],
        ['role' => 'assistant', 'content' => '一线城市每晚 600 元。'],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => $last]]],
    ],
];
$calls = [];
$model = static function (array $route, string $path, array $payload) use (&$calls): array {
    $calls[] = ['route' => $route, 'path' => $path, 'payload' => $payload];
    return ['ok' => true, 'status' => 200, 'json' => ['choices' => [['message' => ['content' => '改写后的问题：出差住宿费怎么报销？']]]]];
};
$retrieval = new RagRetrievalService($embedder, null, null, null, null, null, new RagQueryRewriteService($model));

// A dependent follow-up is rewritten by the model and the rewritten question is searched.
$result = $retrieval->retrieve($apiKey, $conversation('那个怎么报？'));
assert_same('出差住宿费怎么报销？', $result['rewritten_query'], 'The follow-up is rewritten into a standalone question.');
assert_same('出差住宿费怎么报销？', end($queries), 'Retrieval searches the rewritten question.');
assert_same('/v1/chat/completions', $calls[0]['path'], 'An OpenAI-protocol model is called through chat completions.');
assert_true(str_contains($calls[0]['payload']['messages'][1]['content'], '用户：出差住宿标准是多少？') && str_contains($calls[0]['payload']['messages'][1]['content'], '助手：一线城市每晚 600 元。'), 'The conversation history is sent.');
assert_true(!str_contains($calls[0]['payload']['messages'][1]['content'], '你是助手'), 'System prompts are not part of the history.');
assert_same(3000, $calls[0]['route']['timeout_ms'], 'The rewrite call has a short timeout.');
assert_same(1, $calls[0]['route']['retry_count'], 'The rewrite call is not retried.');
assert_same('那个怎么报？', $result['payload']['messages'][3]['content'][0]['text'], 'The messages sent to the answering model are unchanged.');

// A complete, specific question is not rewritten in auto mode; nor is a first question.
$calls = [];
$result = $retrieval->retrieve($apiKey, $conversation('请问公司的差旅住宿费用报销需要提供哪些票据和审批材料'));
assert_same(null, $result['rewritten_query'], 'A standalone question is searched as is.');
assert_same([], $calls, 'No model call for a standalone question.');
$result = $retrieval->retrieve($apiKey, ['rag' => ['enabled' => true, 'dataset_ids' => ['ds_1']], 'messages' => [['role' => 'user', 'content' => '那个怎么报？']]]);
assert_same(null, $result['rewritten_query'], 'Without earlier conversation nothing is rewritten.');

// Model unavailable or answering instead of rewriting: the previous question is used as context.
$failing = new RagRetrievalService($embedder, null, null, null, null, null, new RagQueryRewriteService(static fn (): array => ['ok' => false, 'status' => 503, 'error_code' => 'PROVIDER_ERROR']));
$result = $failing->retrieve($apiKey, $conversation('那个怎么报？'));
assert_same('出差住宿标准是多少？ 那个怎么报？', $result['rewritten_query'], 'A failing model falls back to the previous question as context.');
$chatty = new RagRetrievalService($embedder, null, null, null, null, null, new RagQueryRewriteService(static fn (): array => ['ok' => true, 'status' => 200, 'json' => ['choices' => [['message' => ['content' => "住宿费需要发票。\n另外还要审批单。"]]]]]));
assert_same('出差住宿标准是多少？ 那个怎么报？', $chatty->retrieve($apiKey, $conversation('那个怎么报？'))['rewritten_query'], 'An answer instead of a question is rejected.');

// An explicit rag.query is never rewritten.
$explicit = $conversation('那个怎么报？');
$explicit['rag']['query'] = '住宿费报销';
assert_same(null, $retrieval->retrieve($apiKey, $explicit)['rewritten_query'], 'rag.query is used verbatim.');

// Anthropic-protocol rewrite model; "always" mode.
$pdo->exec("UPDATE rag_knowledge_bases SET retrieval_profile = '{\"query_rewrite\": {\"model_id\": 8, \"mode\": \"always\", \"timeout_ms\": 2000}}' WHERE id = 1");
$calls = [];
$claude = static function (array $route, string $path, array $payload) use (&$calls): array {
    $calls[] = ['path' => $path, 'payload' => $payload, 'route' => $route];
    return ['ok' => true, 'status' => 200, 'json' => ['content' => [['type' => 'text', 'text' => '公司差旅住宿费用报销需要哪些票据和审批材料？']]]];
};
$result = (new RagRetrievalService($embedder, null, null, null, null, null, new RagQueryRewriteService($claude)))->retrieve($apiKey, $conversation('请问公司的差旅住宿费用报销需要提供哪些票据和审批材料'));
assert_same('/v1/messages', $calls[0]['path'] ?? null, 'An Anthropic-protocol model is called through messages.');
assert_true(isset($calls[0]['payload']['system']), 'The instruction goes into the Anthropic system field.');
assert_same(2000, $calls[0]['route']['timeout_ms'], 'The configured timeout is used.');
assert_same('公司差旅住宿费用报销需要哪些票据和审批材料？', $result['rewritten_query'], '"always" rewrites every question that has history.');

// Settings and history helpers.
$service = new RagQueryRewriteService();
assert_same(null, $service->settings([['kb_retrieval_profile' => '{"query_rewrite": {"enabled": false, "model_id": 7}}']]), 'A disabled rewrite returns no settings.');
assert_same([['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b']], $service->history(['messages' => [['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b'], ['role' => 'tool', 'content' => 'x'], ['role' => 'user', 'content' => 'c']]], 3), 'History keeps user/assistant turns before the last question.');

echo "PASS\n";
