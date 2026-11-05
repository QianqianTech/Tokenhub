<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/BusinessSqliteFixture.php';

/*
 * 会话记录 · 多模态: image / video / speech / music generation and speech
 * recognition calls become turns (prompt, reference media, parameters,
 * recognised text); images, audio and video sent with a call are copied into
 * the media directory (encrypted in chunks, byte ranges), generated results
 * are copied from the finished task, oversize files are skipped, failed tasks
 * are shown, deletions / retention / purge remove the files, orphans are swept.
 */

use TokenHub\Services\ConversationLogDb;
use TokenHub\Services\ConversationLogMediaStore;
use TokenHub\Services\ConversationLogService;
use TokenHub\Services\ConversationMediaCapture;
use TokenHub\Services\Gateway\ConversationExtractor;
use TokenHub\Services\Gateway\MultipartUpload;
use TokenHub\Support\App;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\Response;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}
$_ENV['APP_KEY'] = $_ENV['APP_KEY'] ?? 'unit-test-key-for-conversation-log-media-0123456789';
putenv('APP_KEY=' . $_ENV['APP_KEY']);
$base = sys_get_temp_dir() . '/tokenhub-conversation-media-' . getmypid();
$logDir = $base . '/logs';
$mediaRoot = $base . '/media';
@mkdir($mediaRoot, 0777, true);
$_ENV['CONVERSATION_LOG_PATH'] = $logDir;
putenv('CONVERSATION_LOG_PATH=' . $logDir);
$_ENV['MEDIA_STORAGE_PATH'] = $mediaRoot;
putenv('MEDIA_STORAGE_PATH=' . $mediaRoot);
register_shutdown_function(static function () use ($base): void {
    if (is_dir($base)) {
        exec('rm -rf ' . escapeshellarg($base));
    }
});

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
$pngData = 'data:image/png;base64,' . base64_encode($png);

// ---------------------------------------------------------------- extraction (no capture)
$image = ConversationExtractor::extract('openai', 'image_generation', [
    'model' => 'gpt-image-1', 'prompt' => '一只橘猫', 'size' => '1024x1024', 'n' => 2, 'quality' => 'high',
    'image' => [$pngData, 'https://cdn.example.com/ref.png'], 'response_format' => 'b64_json',
], ['task_id' => 'task_img_1', 'status' => 'succeeded', 'data' => [['revised_prompt' => '一只坐着的橘猫', 'asset_id' => 'asset_x']]]);
assert_same('image_generation', $image['format'], 'Image generation calls become turns.');
assert_same('一只橘猫', $image['messages'][0]['parts'][0]['text'], 'The prompt is the question.');
assert_same('image', $image['messages'][0]['parts'][1]['type'], 'Reference images are kept as parts.');
assert_true(str_contains($image['messages'][0]['parts'][2]['ref'], 'cdn.example.com'), 'Linked references show their link.');
assert_same(['size' => '1024x1024', 'n' => '2', 'quality' => 'high'], $image['params'], 'Generation parameters are listed (not the prompt, model or media).');
assert_true(str_contains($image['reply']['note'], '坐着的橘猫'), 'The revised prompt is shown.');
assert_same('task_img_1', $image['reply']['task']['id'], 'The task id links the generated files.');
assert_true(!str_contains(json_encode($image), base64_encode($png)) && !str_contains(json_encode($image), '_raw'), 'Media bytes and sources never stay in the turn.');
assert_same('一只橘猫', $image['title'], 'The prompt is the title.');

$video = ConversationExtractor::extract('openai', 'video_generation', [
    'model' => 'seedance', 'duration' => 5, 'ratio' => '16:9', 'generate_audio' => true,
    'content' => [['type' => 'text', 'text' => '海边日落'], ['type' => 'image_url', 'image_url' => ['url' => 'https://cdn.example.com/first.png'], 'role' => 'first_frame']],
    'reference_videos' => ['https://cdn.example.com/ref.mp4'],
], ['task_id' => 'task_vid_1', 'status' => 'processing']);
assert_same('海边日落', $video['title'], 'The text of a vendor content array is the prompt.');
assert_same(['text', 'image', 'video'], array_column($video['messages'][0]['parts'], 'type'), 'Content images and reference videos are parts.');
assert_same('true', $video['params']['generate_audio'], 'Boolean parameters are shown.');
assert_same('processing', $video['reply']['task']['status'], 'The submission status is kept.');

$tts = ConversationExtractor::extract('openai', 'tts', ['model' => 'tts-1', 'input' => '你好，欢迎', 'voice' => 'alloy'], ['audio_bytes' => 2048]);
assert_same('你好，欢迎', $tts['messages'][0]['parts'][0]['text'], 'Speech input text is the question.');
assert_same('alloy', $tts['params']['voice'], 'The voice is a parameter.');

$audioFile = $base . '/upload.mp3';
file_put_contents($audioFile, "ID3\x03\x00\x00\x00" . str_repeat("\x00", 200));
$asrPayload = ['model' => 'whisper-1', 'language' => 'zh', MultipartUpload::FILES_KEY => ['file' => ['path' => $audioFile, 'filename' => '会议.mp3', 'mime' => 'audio/mpeg', 'size' => filesize($audioFile)]]];
$asr = ConversationExtractor::extract('openai', 'asr', $asrPayload, ['text' => '今天开会讨论预算', 'task_id' => 'task_asr_1']);
assert_same('今天开会讨论预算', $asr['reply']['text'], 'Recognised text is the reply.');
assert_same('audio', $asr['messages'][0]['parts'][0]['type'], 'The uploaded audio is the question.');
assert_same('会议.mp3', $asr['messages'][0]['parts'][0]['ref'], 'The upload shows its file name.');
assert_same('会议.mp3', $asr['title'], 'Without a prompt the file name is the title.');
assert_same(['language' => 'zh'], $asr['params'], 'Internal upload fields are not parameters.');

// ---------------------------------------------------------------- media store
$store = new ConversationLogMediaStore($logDir);
$rel = $store->relativePath('2026-11-04 01:00:00', 'Asia/Shanghai', 3, ConversationLogMediaStore::newKey());
assert_true(str_starts_with($rel, 'media/2026/11/04/key-3/'), 'Media files are kept per day and key.');
$blob = random_bytes(2621440 + 123);
assert_same(strlen($blob), $store->writeString($rel, $blob), 'The plain size is returned.');
$onDisk = (string) file_get_contents($logDir . '/' . $rel);
assert_true(!str_contains($onDisk, substr($blob, 1000, 64)), 'Media files are encrypted.');
assert_same(strlen($blob), $store->size($rel), 'The size is read from the header.');
assert_same($blob, $store->readAll($rel), 'Files decrypt back across chunks.');
$part = '';
$store->read($rel, 1048570, 1048600, static function (string $bytes) use (&$part): void {
    $part .= $bytes;
});
assert_same(substr($blob, 1048570, 31), $part, 'A byte range across a chunk boundary is served.');
$store->rewrap($rel);
assert_same($blob, $store->readAll($rel), 'Re-wrapping the file key (APP_KEY rotation) keeps the file readable.');
$damaged = $onDisk;
$damaged[strlen($damaged) - 10] = $damaged[strlen($damaged) - 10] === 'a' ? 'b' : 'a';
file_put_contents($logDir . '/' . $rel, $damaged);
try {
    $store->readAll($rel);
    throw new RuntimeException('A tampered media file must not decrypt.');
} catch (RuntimeException $exception) {
    assert_true(str_contains($exception->getMessage(), 'decrypted'), 'Tampering is detected by the chunk tag.');
}
$store->delete($rel);
$failedInvalid = false;
try {
    $store->relativePath('2026-11-04 01:00:00', 'UTC', 3, '../../etc');
} catch (RuntimeException) {
    $failedInvalid = true;
}
assert_true($failedInvalid, 'Media paths are validated.');

// ---------------------------------------------------------------- databases
final class MediaTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', trim($query), $m) === 1) {
            $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
        }

        return parent::prepare(BusinessSqlitePdo::translate($query), $options);
    }
}
$pdo = new MediaTestPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
foreach ([
    'CREATE TABLE system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT, updated_at TEXT)',
    'CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INT, actor_type TEXT, action TEXT, target_type TEXT, target_id TEXT, request_id TEXT, trace_id TEXT, client_ip TEXT, details TEXT)',
    'CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, path TEXT)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, department_id INT, username TEXT, status TEXT)',
    'CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, department_id INT, name TEXT, key_prefix TEXT, key_suffix TEXT, key_hash TEXT, status TEXT DEFAULT "active", conversation_log_enabled INT NOT NULL DEFAULT 0, conversation_log_retention_days INT NULL)',
    'CREATE TABLE multimodal_tasks (id INTEGER PRIMARY KEY, task_id TEXT UNIQUE, status TEXT, error_code TEXT, error_message TEXT, created_at TEXT)',
    'CREATE TABLE media_assets (id INTEGER PRIMARY KEY, asset_id TEXT UNIQUE, task_id INT, media_type TEXT, storage_path TEXT, mime_type TEXT, size_bytes INT)',
    "INSERT INTO departments (id, name, path) VALUES (1, '研发部', '/1/')",
    "INSERT INTO users (id, department_id, username, status) VALUES (7, 1, 'alice', 'active')",
    "INSERT INTO api_keys (id, user_id, department_id, name, key_prefix, key_suffix, key_hash, conversation_log_enabled) VALUES (3, 7, 1, 'alice-key', 'tkh_aaaa', 'a1b2', 'h3', 1)",
] as $sql) {
    $pdo->exec($sql);
}
business_use_sqlite($pdo);
$logPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
ConversationLogDb::useConnection($logPdo);
$media = static fn (string $where = '1 = 1'): array => $logPdo->query("SELECT * FROM conversation_log_media WHERE {$where} ORDER BY id")->fetchAll();
$expect = static function (callable $callback, string $code, int $status, string $message): void {
    try {
        $callback();
    } catch (HttpAbortException $exception) {
        assert_same($code, $exception->errorCode, $message . ' (code)');
        assert_same($status, $exception->status, $message . ' (status)');
        return;
    }
    throw new RuntimeException($message . ' (no abort raised)');
};

App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin']);
$service = new ConversationLogService();
assert_same(true, $service->settings()['record_media'], 'Media are recorded by default.');
assert_same(100, $service->settings()['media_max_mb'], 'The default media size limit is 100 MB.');
$expect(static fn () => $service->saveSettings(['media_max_mb' => 0]), 'CONVERSATION_LOG_INVALID_MEDIA_SIZE', 422, 'The media size limit is checked.');
$service->saveSettings(['enabled' => true, 'media_max_mb' => 1, 'timezone' => 'Asia/Shanghai']);

$record = static fn (int $usageId, array $conversation, array $extra = []): array => [
    'request_id' => 'req_' . $usageId, 'user_id' => 7, 'department_id' => 1, 'api_key_id' => 3, 'model_id' => 5,
    'model_name' => 'gen-test', 'protocol' => 'openai', 'status' => 'success', 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
    'cost_amount' => 0.04, 'cost_currency' => 'USD', 'latency_ms' => 3000, 'created_at' => gmdate('Y-m-d H:i:s'), 'conversation' => $conversation, ...$extra,
];
$capture = static function () use ($service): ConversationMediaCapture {
    $capture = $service->mediaCapture(3, gmdate('Y-m-d H:i:s'));
    assert_true($capture instanceof ConversationMediaCapture, 'The gateway gets a media collector.');
    return $capture;
};

// A vision chat: only the new turn's image is kept; earlier images are not copied again.
$chatCapture = $capture();
$chat = ConversationExtractor::extract('openai', 'text_generation', [
    'messages' => [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => '旧图'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('old-image-bytes')]]]],
        ['role' => 'assistant', 'content' => '看到了'],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => '这张呢'], ['type' => 'image_url', 'image_url' => ['url' => $pngData]], ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode(str_repeat('a', 1048577)), 'format' => 'wav']]]],
    ],
], ['choices' => [['message' => ['content' => '是一个像素', 'images' => [['type' => 'image_url', 'image_url' => ['url' => $pngData]]]]]]], [], $chatCapture);
$chat['media'] = $chatCapture->items();
assert_same(3, count($chat['media']), 'The new image, the audio and the generated image are captured; history is not.');
assert_same('stored', $chat['media'][0]['status'], 'Inline images are stored at once.');
assert_same('image/png', $chat['media'][0]['mime'], 'The data URL type is kept.');
assert_same('skipped', $chat['media'][1]['status'], 'Files above the size limit are skipped.');
assert_same('too_large', $chat['media'][1]['error'], 'The reason is kept.');
assert_same('output', $chat['media'][2]['direction'], 'Images in the reply are output media.');
assert_same($chat['media'][0]['key'], $chat['messages'][0]['parts'][1]['media'], 'The message part points at its media.');
assert_true(!isset($chat['messages'][0]['parts'][1]['_raw']), 'Sources do not stay in the turn.');
$service->writeForRecord(['id' => 1], $record(1, $chat));
assert_same(3, count($media()), 'Media rows are created with the record.');

// Image generation: reference image inline, a link (copied later), and the task's results.
$genCapture = $capture();
$gen = ConversationExtractor::extract('openai', 'image_generation', ['prompt' => '一只橘猫', 'size' => '512x512', 'image' => [$pngData, 'http://127.0.0.1/private.png']], ['task_id' => 'task_img_1', 'status' => 'succeeded'], [], $genCapture);
$gen['media'] = $genCapture->items();
assert_same(['stored', 'pending'], array_column($gen['media'], 'status'), 'Inline references are stored, links wait for the copier.');
assert_true(str_contains((string) json_encode($gen['media']), '127.0.0.1'), 'The link to copy is noted for the queue (the turn is encrypted).');
$pdo->exec("INSERT INTO multimodal_tasks (id, task_id, status, created_at) VALUES (11, 'task_img_1', 'succeeded', '2026-11-04 01:00:00')");
$assetFile = $mediaRoot . '/2026/11/generated.png';
@mkdir(dirname($assetFile), 0777, true);
file_put_contents($assetFile, $png);
$pdo->exec("INSERT INTO media_assets (id, asset_id, task_id, media_type, storage_path, mime_type, size_bytes) VALUES (1, 'asset_generated_1', 11, 'image', " . $pdo->quote($assetFile) . ", 'image/png', " . strlen($png) . ")");
$pdo->exec("INSERT INTO media_assets (id, asset_id, task_id, media_type, storage_path, mime_type, size_bytes) VALUES (2, 'asset_generated_2', 11, 'image', " . $pdo->quote($assetFile) . ", 'image/png', 5000000)");
$service->writeForRecord(['id' => 2], $record(2, $gen, ['task_id' => 'task_img_1']));
$genLogId = (int) $logPdo->query('SELECT id FROM conversation_logs WHERE usage_record_id = 2')->fetchColumn();
assert_same(1, count($media("status = 'waiting' AND log_id = {$genLogId}")), 'Generation calls wait for their task.');

// Video generation still running, and one that fails.
$pdo->exec("INSERT INTO multimodal_tasks (id, task_id, status, created_at) VALUES (12, 'task_vid_1', 'processing', '2026-11-04 01:00:00')");
$pdo->exec("INSERT INTO multimodal_tasks (id, task_id, status, error_code, error_message, created_at) VALUES (13, 'task_vid_2', 'failed', 'PROVIDER_TASK_FAILED', '内容审核未通过', '2026-11-04 01:00:00')");
$service->writeForRecord(['id' => 3], $record(3, ConversationExtractor::extract('openai', 'video_generation', ['prompt' => '海边'], ['task_id' => 'task_vid_1', 'status' => 'processing']), ['task_id' => 'task_vid_1']));
$service->writeForRecord(['id' => 4], $record(4, ConversationExtractor::extract('openai', 'video_generation', ['prompt' => '山顶'], ['task_id' => 'task_vid_2']), ['task_id' => 'task_vid_2']));

$result = $service->processMedia();
assert_same(2, $result['tasks'], 'Finished tasks are resolved; running ones keep waiting.');
$outputs = $media("log_id = {$genLogId} AND direction = 'output' AND source = 'asset'");
assert_same(2, count($outputs), 'Every file of a finished task gets a row.');
assert_same('stored', $outputs[0]['status'], 'Generated files are copied from the media library.');
assert_same('skipped', $outputs[1]['status'], 'Generated files above the limit are skipped.');
assert_same(1, count($media("task_id = 'task_vid_1' AND status = 'waiting'")), 'A running task keeps waiting.');
$failed = $media("task_id = 'task_vid_2'");
assert_same('failed', $failed[0]['status'], 'A failed task is shown as failed.');
assert_true(str_contains((string) $failed[0]['error'], '内容审核未通过'), 'The task error is kept.');
assert_same('pending', $media("source = 'url'")[0]['status'], 'A link that could not be fetched is retried.');
$service->processMedia();
$service->processMedia();
assert_same('failed', $media("source = 'url'")[0]['status'], 'After three attempts a link is given up.');

// The copied file is the generated image, encrypted on disk.
$storedFile = (string) $outputs[0]['file'];
assert_same($png, $service->mediaStore()->readAll($storedFile), 'The generated image is kept in the conversation log.');
unlink($assetFile);
assert_same($png, $service->mediaStore()->readAll($storedFile), 'It stays after the media library deleted its copy.');

// Task finishes later.
$pdo->exec("UPDATE multimodal_tasks SET status = 'succeeded' WHERE task_id = 'task_vid_1'");
$videoFile = $mediaRoot . '/2026/11/video.mp4';
file_put_contents($videoFile, str_repeat('v', 3000));
$pdo->exec("INSERT INTO media_assets (id, asset_id, task_id, media_type, storage_path, mime_type, size_bytes) VALUES (3, 'asset_video_1', 12, 'video', " . $pdo->quote($videoFile) . ", 'video/mp4', 3000)");
$service->processMedia();
$videoRow = $media("task_id = 'task_vid_1' AND source = 'asset'")[0];
assert_same('stored', $videoRow['status'], 'A video that finishes later is copied too.');

// Views: calls list the media with a link; the transcript parts point at them.
$calls = $service->calls(3, []);
$byId = array_column($calls['items'], null, 'id');
$genCall = $byId[$genLogId];
assert_same(['512x512'], array_values(array_intersect_key($genCall['params'], ['size' => 1])), 'Parameters are shown with the call.');
$genOutputs = array_values(array_filter($genCall['media'], static fn (array $item): bool => $item['direction'] === 'output' && $item['source'] === 'asset'));
assert_same('/admin-api/conversation-logs/media/' . $genOutputs[0]['key'], $genOutputs[0]['url'], 'Stored media have an admin link.');
assert_same(null, $genOutputs[1]['url'], 'Skipped media have no link.');
$chatCall = $byId[(int) $logPdo->query('SELECT id FROM conversation_logs WHERE usage_record_id = 1')->fetchColumn()];
assert_same($chatCall['messages'][0]['parts'][1]['media'], $chatCall['media'][0]['key'], 'Message parts and media rows match.');

// Serving: full file and byte ranges.
$serve = static function (Response $response): string {
    $out = '';
    ob_start(static function (string $buffer) use (&$out): string {
        $out .= $buffer;
        return '';
    });
    $response->send();
    ob_end_clean();
    return $out;
};
$full = $service->mediaResponse($genOutputs[0]['key'], null);
assert_same(200, $full->status, 'Media are served.');
assert_same('image/png', $full->headers['Content-Type'], 'With their type.');
assert_same($png, $serve($full), 'The decrypted bytes are sent.');
$videoKey = (string) $videoRow['media_key'];
$range = $service->mediaResponse($videoKey, 'bytes=100-199');
assert_same(206, $range->status, 'Byte ranges are answered with 206.');
assert_same('bytes 100-199/3000', $range->headers['Content-Range'], 'The range is announced.');
assert_same(str_repeat('v', 100), $serve($range), 'Only the range is sent.');
assert_same(416, $service->mediaResponse($videoKey, 'bytes=5000-')->status, 'Ranges beyond the end are refused.');
assert_same("default-src 'none'; sandbox", $full->headers['Content-Security-Policy'], 'Media responses cannot run scripts.');
$expect(static fn () => $service->mediaResponse('nope', null), 'CONVERSATION_MEDIA_NOT_FOUND', 404, 'Unknown media are 404.');
App::context()->setAdminUser(['id' => 2, 'role' => 'admin', 'admin_scope' => 'global']);
$expect(static fn () => $service->mediaResponse($videoKey, null), 'ADMIN_PERMISSION_DENIED', 403, 'Only super administrators can open media.');
App::context()->setAdminUser(['id' => 1, 'role' => 'super_admin']);

$stats = $service->settingsView()['media'];
assert_true($stats['stored'] >= 4 && $stats['stored_bytes'] > 0, 'The settings page shows stored media.');
assert_true($stats['skipped'] >= 2 && $stats['failed'] >= 2, 'Skipped and failed media are counted.');

// Deleting a call removes its media files and rows.
$genFiles = array_filter(array_column($media("log_id = {$genLogId}"), 'file'));
assert_true(count($genFiles) >= 2, 'The generation call has files.');
$service->deleteCall($genLogId);
assert_same([], $media("log_id = {$genLogId}"), 'Media rows go with the call.');
foreach ($genFiles as $file) {
    assert_true(!is_file($logDir . '/' . $file), 'Media files go with the call: ' . $file);
}

// Orphans: a file the gateway wrote but no record points at.
$orphan = $service->mediaStore()->relativePath(gmdate('Y-m-d H:i:s'), 'Asia/Shanghai', 3, ConversationLogMediaStore::newKey());
$service->mediaStore()->writeString($orphan, 'orphan');
touch($logDir . '/' . $orphan, time() - 3 * 86400);
$fresh = $service->mediaStore()->relativePath(gmdate('Y-m-d H:i:s'), 'Asia/Shanghai', 3, ConversationLogMediaStore::newKey());
$service->mediaStore()->writeString($fresh, 'fresh');
$service->cleanup(true);
assert_true(!is_file($logDir . '/' . $orphan), 'Old media files without a record are swept.');
assert_true(is_file($logDir . '/' . $fresh), 'Files written in the last day are left for the queue.');

// Media switched off: no collector, placeholders only.
$service->saveSettings(['record_media' => false]);
assert_same(null, $service->mediaCapture(3, gmdate('Y-m-d H:i:s')), 'With media recording off the gateway keeps placeholders only.');
$service->writeForRecord(['id' => 9], $record(9, ConversationExtractor::extract('openai', 'image_generation', ['prompt' => '狗'], ['task_id' => 'task_img_9']), ['task_id' => 'task_img_9']));
assert_same([], $media("task_id = 'task_img_9'"), 'Task results are not copied when media recording is off.');

// Purge removes every media file.
$service->purgeAll();
assert_same([], $media(), 'Purge deletes the media rows.');
assert_same([], $service->mediaStore()->files(), 'Purge deletes the media files.');

echo "OK\n";
