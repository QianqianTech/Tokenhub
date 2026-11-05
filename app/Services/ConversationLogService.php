<?php

declare(strict_types=1);

namespace TokenHub\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Response;

/**
 * Conversation logs (会话记录): readable chat transcripts of API key (Token) calls.
 *
 * - Two switches, both off by default: the feature itself (system setting
 *   "enabled") and then each API key (api_keys.conversation_log_enabled).
 *   Nothing is recorded unless both are on.
 * - Global settings (system_settings "conversation_log.settings"): enabled,
 *   default retention_days, per-key record cap (max_records_per_key, 0 = no
 *   cap), purge_deleted_keys, auto_cleanup, cleanup_time (HH:MM, daily) and
 *   timezone. Keys may override the retention
 *   (api_keys.conversation_log_retention_days).
 * - The gateway extracts each text request's new turn and reply
 *   (Gateway\ConversationExtractor) when the calling key has logging on; the
 *   usage.write consumer stores it outside the application database: the
 *   index (key, session, time, tokens, cost) in the conversation log's own
 *   database (ConversationLogDb, SQLite file by default), the questions and
 *   answers in the conversation log directory (ConversationLogStore: one
 *   encrypted file per key per day, compressed once a day). Only the settings
 *   and the per-key switches stay in the application database.
 * - Only super administrators can read the logs or change any of the
 *   settings; reads and changes are written to the audit log.
 * - cleanup() runs from the scheduler every few minutes and, once a day after
 *   cleanup_time in the configured timezone, deletes each key's rows older
 *   than its retention. Expired rows are deleted even after logging is off.
 * - Media (record_media, default on): images, audio and video sent with a
 *   call, generated images / video / speech / music and the audio of speech
 *   recognition are copied into the media directory
 *   (ConversationLogMediaStore, encrypted) and listed in the
 *   conversation_log_media table of the log database. Inline data is written
 *   by the gateway; links, media-library files and task results are copied by
 *   processMedia() (queue job conversation-logs.media, also every scheduler
 *   run). Media follow their record: deleted with it, uploaded to COS with it.
 */
final class ConversationLogService
{
    public const SETTING_KEY = 'conversation_log.settings';
    public const STATE_KEY = 'conversation_log.cleanup_state';
    public const HISTORY_KEY = 'conversation_log.cleanup_history';
    public const COS_KEY = 'conversation_log.cos';
    public const COS_STATE_KEY = 'conversation_log.cos_state';
    private const COS_STORAGE_CLASSES = ['STANDARD', 'STANDARD_IA'];
    private const INDEX_BACKUPS_KEPT = 7;
    private const HISTORY_SIZE = 30;
    private const ENABLED_CACHE_KEY = 'conversation_log:enabled';
    /** Pseudo capability added to /admin-api/me for super administrators (sidebar visibility only). */
    public const CAPABILITY = 'conversation_logs.read';
    private const DELETE_BATCH = 2000;
    private const MAX_DELETE_BATCHES = 500;
    private const MAX_RETENTION_DAYS = 3650;
    private const MAX_RECORDS_CAP = 10000000;

    private ?ConversationLogStore $store = null;
    private ?ConversationLogMediaStore $mediaStore = null;
    private ?ConversationLogDb $logs = null;

    public const MEDIA_JOB = 'conversation-logs.media';
    private const MEDIA_MAX_MB = 2048;
    private const MEDIA_ATTEMPTS = 3;
    private const KIND_FOR_CAPABILITY = ['image_generation' => 'image', 'video_generation' => 'video', 'tts' => 'audio', 'music_generation' => 'audio'];

    private static ?bool $enabledMemo = null;
    private static float $enabledMemoAt = 0.0;

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'enabled' => false,
            'compress_daily' => true,
            'retention_days' => 30,
            'max_records_per_key' => 0,
            'purge_deleted_keys' => true,
            'auto_cleanup' => true,
            'cleanup_time' => '03:00',
            'timezone' => 'Asia/Shanghai',
            // local: the conversation log directory; cos: 腾讯云 COS (today's file
            // is staged locally and uploaded every few minutes).
            'storage_driver' => 'local',
            // Images / audio / video of calls and generated results.
            'record_media' => true,
            'media_max_mb' => 100,
        ];
    }

    /**
     * The feature switch (系统设置 › 会话记录设置 › 启用会话记录功能). Cheap on
     * the gateway hot path: memoized per process for 10 s, cached in Redis for 30 s.
     */
    public static function enabled(): bool
    {
        if (self::$enabledMemo !== null && microtime(true) - self::$enabledMemoAt < 10) {
            return self::$enabledMemo;
        }
        $enabled = null;
        try {
            $cached = App::redis()->get(self::ENABLED_CACHE_KEY);
            if ($cached === '1' || $cached === 1 || $cached === true) {
                $enabled = true;
            } elseif ($cached === '0' || $cached === 0 || $cached === false) {
                $enabled = false;
            }
        } catch (Throwable) {
        }
        if ($enabled === null) {
            try {
                $enabled = (bool) (new self())->settings()['enabled'];
            } catch (Throwable) {
                $enabled = false;
            }
            try {
                App::redis()->set(self::ENABLED_CACHE_KEY, $enabled ? '1' : '0', 30);
            } catch (Throwable) {
            }
        }
        self::$enabledMemo = $enabled;
        self::$enabledMemoAt = microtime(true);

        return $enabled;
    }

    public static function forgetEnabled(): void
    {
        self::$enabledMemo = null;
        try {
            App::redis()->delete(self::ENABLED_CACHE_KEY);
        } catch (Throwable) {
        }
    }

    /**
     * Whether the calling API key has conversation logging on (gateway hot
     * path: reads the key row already loaded by authentication).
     *
     * @param array<string, mixed>|null $apiKey
     */
    public static function enabledForKey(?array $apiKey): bool
    {
        return is_array($apiKey) && (int) ($apiKey['conversation_log_enabled'] ?? 0) === 1 && self::enabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->normalize($this->readSetting(self::SETTING_KEY));
    }

    /**
     * Settings plus cleanup state and storage figures, for the settings page.
     *
     * @return array<string, mixed>
     */
    public function settingsView(): array
    {
        $this->requireSuperAdmin();
        $settings = $this->settings();
        $stats = ['records' => 0, 'keys' => 0, 'enabled_keys' => 0, 'oldest_at' => null, 'db_records' => 0];
        $database = ['driver' => null, 'location' => null, 'bytes' => null, 'error' => null];
        try {
            $enabled = App::db()->first("SELECT COUNT(*) AS total FROM api_keys WHERE conversation_log_enabled = 1 AND status <> 'deleted'");
            $stats['enabled_keys'] = (int) ($enabled['total'] ?? 0);
        } catch (Throwable) {
            $stats['columns_missing'] = true;
        }
        try {
            $database = [...$database, ...$this->logs()->describe()];
            $row = $this->logs()->first("SELECT COUNT(*) AS records, COUNT(DISTINCT api_key_id) AS api_keys, MIN(created_at) AS oldest_at, SUM(CASE WHEN storage = 'db' THEN 1 ELSE 0 END) AS db_records FROM conversation_logs");
            $stats = [...$stats, 'records' => (int) ($row['records'] ?? 0), 'keys' => (int) ($row['api_keys'] ?? 0), 'oldest_at' => $row['oldest_at'] ?? null, 'db_records' => (int) ($row['db_records'] ?? 0)];
        } catch (Throwable $throwable) {
            $database['error'] = $throwable->getMessage();
        }

        return [
            ...$settings,
            'last_cleanup' => $this->readSetting(self::STATE_KEY) ?: null,
            'next_cleanup_at' => $settings['auto_cleanup'] ? $this->nextCleanupAt($settings) : null,
            'history' => array_values($this->readSetting(self::HISTORY_KEY)),
            'stats' => $stats,
            'storage' => [
                'path' => $this->store()->root(),
                'writable' => $this->directoryWritable(),
                ...$this->store()->stats(),
            ],
            'database' => $database,
            'media' => $this->mediaStats(),
            'cos' => $this->cosView(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaStats(): array
    {
        $stats = ['stored' => 0, 'stored_bytes' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0, 'local_files' => 0, 'local_bytes' => 0];
        try {
            foreach ($this->logs()->select("SELECT status, COUNT(*) AS total, SUM(size_bytes) AS bytes FROM conversation_log_media WHERE source <> 'task' OR status IN ('waiting', 'failed') GROUP BY status") as $row) {
                $count = (int) $row['total'];
                $status = (string) $row['status'];
                if ($status === 'stored') {
                    $stats['stored'] = $count;
                    $stats['stored_bytes'] = (int) ($row['bytes'] ?? 0);
                } elseif (in_array($status, ['pending', 'working', 'waiting'], true)) {
                    $stats['pending'] += $count;
                } elseif (isset($stats[$status])) {
                    $stats[$status] += $count;
                }
            }
            $local = $this->mediaStore()->stats();
            $stats['local_files'] = $local['files'];
            $stats['local_bytes'] = $local['bytes'];
        } catch (Throwable $throwable) {
            $stats['error'] = $throwable->getMessage();
        }

        return $stats;
    }

    private function directoryWritable(): bool
    {
        $root = $this->store()->root();
        if (!is_dir($root)) {
            @mkdir($root, 0770, true);
        }

        return is_dir($root) && is_writable($root);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveSettings(array $input): array
    {
        $this->requireSuperAdmin();
        $before = $this->settings();
        $settings = $this->normalize(array_replace($before, array_intersect_key($input, $this->defaults())), true);
        if (isset($input['storage_driver']) && !in_array($input['storage_driver'], ['local', 'cos'], true)) {
            abort_json('CONVERSATION_LOG_INVALID_STORAGE', '存储方式只能是本地存储或腾讯云 COS', 422);
        }
        if ($settings['storage_driver'] === 'cos' && $this->cosClient() === null) {
            abort_json('CONVERSATION_LOG_COS_NOT_CONFIGURED', '请先填写并保存腾讯云 COS 配置，测试连接通过后再选择云端存储', 422);
        }
        $this->writeSetting(self::SETTING_KEY, $settings, 'Conversation log settings');
        self::forgetEnabled();
        (new AuditService())->write('conversation_log.settings_update', 'system_settings', self::SETTING_KEY, [
            'before' => $before,
            'after' => $settings,
        ]);
        // Turning the feature off may also delete what was recorded.
        if ($before['enabled'] && !$settings['enabled'] && filter_var($input['purge'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->purgeAll('disable');
        }

        return $this->settingsView();
    }

    /**
     * Stores one conversation turn (queue consumer, after the usage record exists).
     *
     * @param array<string, mixed> $usageRecord the usage_records row (id)
     * @param array<string, mixed> $record the usage.write record with "conversation"
     */
    public function writeForRecord(array $usageRecord, array $record): void
    {
        $conversation = $record['conversation'] ?? null;
        $apiKeyId = isset($record['api_key_id']) ? (int) $record['api_key_id'] : null;
        if (!is_array($conversation) || $apiKeyId === null) {
            return;
        }
        // Logging may have been switched off while the job sat in the queue.
        $key = App::db()->first('SELECT conversation_log_enabled FROM api_keys WHERE id = ?', [$apiKeyId]);
        if ((int) ($key['conversation_log_enabled'] ?? 0) !== 1 || !$this->settings()['enabled']) {
            return;
        }
        $usageRecordId = isset($usageRecord['id']) ? (int) $usageRecord['id'] : null;
        if ($usageRecordId !== null && $this->logs()->first('SELECT id FROM conversation_logs WHERE usage_record_id = ?', [$usageRecordId]) !== null) {
            return;
        }

        $userId = isset($record['user_id']) ? (int) $record['user_id'] : null;
        [$sessionKey, $sessionSource] = $this->sessionKey($conversation, $userId, $apiKeyId);

        $system = is_string($conversation['system'] ?? null) && $conversation['system'] !== '' ? $conversation['system'] : null;
        $systemHash = $system !== null ? hash('sha256', $system) : null;
        $storeSystem = $systemHash !== null && $this->logs()->first(
            'SELECT id FROM conversation_logs WHERE session_key = ? AND system_hash = ? AND (system_stored = 1 OR encrypted_system IS NOT NULL) LIMIT 1',
            [$sessionKey, $systemHash]
        ) === null;
        $createdAt = $this->utc($record['created_at'] ?? null);
        $store = $this->store();
        $contentFile = $store->relativePath($createdAt, $this->settings()['timezone'], $apiKeyId);
        $payload = [
            'system' => $storeSystem ? $system : null,
            'messages' => $conversation['messages'] ?? [],
            'reply' => $conversation['reply'] ?? [],
        ];
        if (is_array($conversation['params'] ?? null) && $conversation['params'] !== []) {
            $payload['params'] = $conversation['params'];
        }

        $id = $this->logs()->insert(
            'INSERT INTO conversation_logs (usage_record_id, request_id, user_id, department_id, api_key_id, model_id, model_name, protocol, format, session_key, session_source, status, input_tokens, output_tokens, total_tokens, cache_read_tokens, cost_amount, cost_currency, latency_ms, prior_count, response_id, system_hash, storage, content_file, system_stored, encrypted_title, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $usageRecordId,
                isset($record['request_id']) ? substr((string) $record['request_id'], 0, 80) : null,
                $userId,
                isset($record['department_id']) ? (int) $record['department_id'] : null,
                $apiKeyId,
                isset($record['model_id']) ? (int) $record['model_id'] : null,
                isset($record['model_name']) ? substr((string) $record['model_name'], 0, 190) : null,
                isset($record['protocol']) ? substr((string) $record['protocol'], 0, 40) : null,
                substr((string) ($conversation['format'] ?? ''), 0, 40),
                $sessionKey,
                $sessionSource,
                substr((string) ($record['status'] ?? 'success'), 0, 20),
                max(0, (int) ($record['input_tokens'] ?? 0)),
                max(0, (int) ($record['output_tokens'] ?? 0)),
                max(0, (int) ($record['total_tokens'] ?? 0)) ?: max(0, (int) ($record['input_tokens'] ?? 0)) + max(0, (int) ($record['output_tokens'] ?? 0)),
                max(0, (int) ($record['cache_read_tokens'] ?? 0)),
                isset($record['cost_amount']) && is_numeric($record['cost_amount']) ? (string) $record['cost_amount'] : null,
                isset($record['cost_currency']) && $record['cost_currency'] !== '' ? substr(strtoupper((string) $record['cost_currency']), 0, 3) : null,
                isset($record['latency_ms']) ? max(0, (int) $record['latency_ms']) : null,
                max(0, (int) ($conversation['prior_count'] ?? 0)),
                is_string($conversation['response_id'] ?? null) ? $conversation['response_id'] : null,
                $systemHash,
                'file',
                $contentFile,
                $storeSystem ? 1 : 0,
                Crypto::encrypt((string) ($conversation['title'] ?? '')),
                $createdAt,
            ]
        );
        try {
            $store->append($contentFile, $id, $payload);
        } catch (Throwable $throwable) {
            // Directory not writable: keep the content in the database rather than lose it.
            $this->logs()->execute(
                "UPDATE conversation_logs SET storage = 'db', content_file = NULL, encrypted_system = ?, encrypted_messages = ?, encrypted_reply = ? WHERE id = ?",
                [
                    $storeSystem ? Crypto::encrypt((string) $system) : null,
                    Crypto::encrypt($this->encode($payload['messages'])),
                    Crypto::encrypt($this->encode($payload['reply'])),
                    $id,
                ]
            );
            try {
                App::logger()->warning('worker', 'conversation log directory write failed; content kept in the database', ['id' => $id, 'error' => $throwable->getMessage()]);
            } catch (Throwable) {
            }
        }
        try {
            $this->insertMedia($id, $apiKeyId, $conversation, $record, $createdAt);
        } catch (Throwable $throwable) {
            try {
                App::logger()->warning('worker', 'conversation log media rows failed', ['id' => $id, 'error' => $throwable->getMessage()]);
            } catch (Throwable) {
            }
        }
    }

    // ---------------------------------------------------------------- media

    /**
     * Collector the gateway hands to the extractor (null when media are not recorded).
     */
    public function mediaCapture(?int $apiKeyId, string $createdAtUtc): ?ConversationMediaCapture
    {
        $settings = $this->settings();
        if (!$settings['record_media']) {
            return null;
        }

        return new ConversationMediaCapture(
            new ConversationLogMediaStore((new ConversationLogStore())->root()),
            $apiKeyId,
            $createdAtUtc,
            $settings['timezone'],
            $settings['media_max_mb'] * 1048576
        );
    }

    public function mediaStore(): ConversationLogMediaStore
    {
        if ($this->mediaStore === null) {
            $this->mediaStore = new ConversationLogMediaStore($this->store()->root());
            $client = $this->cosClient();
            if ($client !== null) {
                $cos = $this->cosConfig();
                $this->mediaStore->useRemote($client, $cos['prefix'], $cos['storage_class']);
            }
        }

        return $this->mediaStore;
    }

    /**
     * Wakes the media copier (queue job); the scheduler runs it anyway.
     */
    public static function nudgeMedia(): void
    {
        try {
            if (self::enabled()) {
                App::redis()->push((string) App::config()->get('queue.name', 'tokenhub:jobs'), ['type' => self::MEDIA_JOB]);
            }
        } catch (Throwable) {
        }
    }

    /**
     * Rows of the media captured with a turn, plus one row that waits for the
     * task of a generation call and then brings in its results.
     *
     * @param array<string, mixed> $conversation
     * @param array<string, mixed> $record
     */
    private function insertMedia(int $logId, int $apiKeyId, array $conversation, array $record, string $createdAt): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $pending = false;
        $position = 0;
        foreach (is_array($conversation['media'] ?? null) ? $conversation['media'] : [] as $item) {
            if (!is_array($item) || preg_match('/^[a-f0-9]{24}$/', (string) ($item['key'] ?? '')) !== 1) {
                continue;
            }
            $status = in_array($item['status'] ?? '', ['pending', 'stored', 'skipped', 'failed'], true) ? (string) $item['status'] : 'failed';
            $file = is_string($item['file'] ?? null) && $status === 'stored' ? $item['file'] : null;
            $this->logs()->insert(
                'INSERT INTO conversation_log_media (log_id, api_key_id, media_key, direction, kind, source, status, task_id, encrypted_ref, encrypted_name, file, mime, size_bytes, error, attempts, position, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)',
                [
                    $logId,
                    $apiKeyId,
                    (string) $item['key'],
                    ($item['direction'] ?? 'input') === 'output' ? 'output' : 'input',
                    in_array($item['kind'] ?? '', ['image', 'audio', 'video'], true) ? (string) $item['kind'] : 'file',
                    in_array($item['source'] ?? '', ['inline', 'url', 'asset'], true) ? (string) $item['source'] : 'inline',
                    $status,
                    is_string($item['ref'] ?? null) && $item['ref'] !== '' ? Crypto::encrypt($item['ref']) : null,
                    is_string($item['name'] ?? null) && $item['name'] !== '' ? Crypto::encrypt($item['name']) : null,
                    $file,
                    is_string($item['mime'] ?? null) ? substr($item['mime'], 0, 120) : null,
                    isset($item['size']) ? max(0, (int) $item['size']) : null,
                    is_string($item['error'] ?? null) ? mb_substr($item['error'], 0, 300) : null,
                    $position++,
                    $createdAt,
                    $now,
                ]
            );
            $pending = $pending || $status === 'pending';
        }

        $format = (string) ($conversation['format'] ?? '');
        $taskId = $record['task_id'] ?? ($conversation['reply']['task']['id'] ?? null);
        if (isset(self::KIND_FOR_CAPABILITY[$format]) && is_scalar($taskId) && (string) $taskId !== '' && $this->settings()['record_media']) {
            $this->logs()->insert(
                "INSERT INTO conversation_log_media (log_id, api_key_id, media_key, direction, kind, source, status, task_id, file, attempts, position, created_at, updated_at)
                 VALUES (?, ?, ?, 'output', ?, 'task', 'waiting', ?, NULL, 0, ?, ?, ?)",
                [$logId, $apiKeyId, ConversationLogMediaStore::newKey(), self::KIND_FOR_CAPABILITY[$format], substr((string) $taskId, 0, 80), 1000, $createdAt, $now]
            );
            $pending = true;
        }
        if ($pending) {
            self::nudgeMedia();
        }
    }

    /**
     * Copies what is still missing (queue job conversation-logs.media and every
     * scheduler run): results of finished generation tasks, linked images /
     * media-library files of requests.
     *
     * @return array{tasks: int, stored: int, failed: int, skipped: int}
     */
    public function processMedia(int $limit = 20): array
    {
        $result = ['tasks' => 0, 'stored' => 0, 'failed' => 0, 'skipped' => 0];
        $settings = $this->settings();
        // Copies a crashed worker left half done.
        $this->logs()->execute("UPDATE conversation_log_media SET status = 'pending' WHERE status = 'working' AND updated_at < ?", [gmdate('Y-m-d H:i:s', time() - 1800)]);

        foreach ($this->logs()->select("SELECT * FROM conversation_log_media WHERE status = 'waiting' ORDER BY updated_at ASC, id ASC LIMIT " . max(1, $limit)) as $row) {
            try {
                $result['tasks'] += $this->resolveTask($row) ? 1 : 0;
            } catch (Throwable $throwable) {
                try {
                    App::logger()->warning('worker', 'conversation log media task check failed', ['id' => $row['id'], 'error' => $throwable->getMessage()]);
                } catch (Throwable) {
                }
            }
        }
        foreach ($this->logs()->select("SELECT * FROM conversation_log_media WHERE status = 'pending' ORDER BY id ASC LIMIT " . max(1, $limit)) as $row) {
            $claimed = $this->logs()->execute(
                "UPDATE conversation_log_media SET status = 'working', attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = 'pending'",
                [gmdate('Y-m-d H:i:s'), (int) $row['id']]
            );
            if ($claimed !== 1) {
                continue;
            }
            $status = $this->copyMedia([...$row, 'attempts' => (int) $row['attempts'] + 1], $settings);
            if (isset($result[$status])) {
                $result[$status]++;
            }
        }

        return $result;
    }

    /**
     * Admin "立即保存待处理的媒体".
     *
     * @return array<string, mixed>
     */
    public function processMediaNow(): array
    {
        $this->requireSuperAdmin();

        return [...$this->processMedia(100), 'media' => $this->mediaStats()];
    }

    /**
     * A generation task finished: its files become output rows.
     *
     * @param array<string, mixed> $row
     */
    private function resolveTask(array $row): bool
    {
        $task = App::db()->first('SELECT id, status, error_code, error_message, created_at FROM multimodal_tasks WHERE task_id = ?', [(string) $row['task_id']]);
        $now = gmdate('Y-m-d H:i:s');
        if ($task === null) {
            $this->logs()->execute("UPDATE conversation_log_media SET status = 'failed', error = ?, updated_at = ? WHERE id = ? AND status = 'waiting'", ['task_missing', $now, (int) $row['id']]);
            return true;
        }
        $status = (string) $task['status'];
        if (in_array($status, ['failed', 'expired', 'cancelled'], true)) {
            $error = trim((string) ($task['error_message'] ?? '')) !== '' ? (string) $task['error_message'] : (string) ($task['error_code'] ?? $status);
            $this->logs()->execute("UPDATE conversation_log_media SET status = 'failed', error = ?, updated_at = ? WHERE id = ? AND status = 'waiting'", [mb_substr('生成失败：' . $error, 0, 300), $now, (int) $row['id']]);
            return true;
        }
        if ($status !== 'succeeded') {
            // Still running: checked again after the other waiting rows.
            $this->logs()->execute("UPDATE conversation_log_media SET updated_at = ? WHERE id = ? AND status = 'waiting'", [$now, (int) $row['id']]);
            return false;
        }
        if ($this->logs()->execute("UPDATE conversation_log_media SET status = 'done', updated_at = ? WHERE id = ? AND status = 'waiting'", [$now, (int) $row['id']]) !== 1) {
            return false;
        }
        $assets = App::db()->select('SELECT asset_id, media_type, mime_type, size_bytes FROM media_assets WHERE task_id = ? ORDER BY id ASC LIMIT 24', [(int) $task['id']]);
        if ($assets === []) {
            $this->logs()->execute('UPDATE conversation_log_media SET error = ? WHERE id = ?', ['no_assets', (int) $row['id']]);
        }
        foreach (array_values($assets) as $index => $asset) {
            $this->logs()->insert(
                "INSERT INTO conversation_log_media (log_id, api_key_id, media_key, direction, kind, source, status, task_id, encrypted_ref, file, mime, size_bytes, attempts, position, created_at, updated_at)
                 VALUES (?, ?, ?, 'output', ?, 'asset', 'pending', ?, ?, NULL, ?, ?, 0, ?, ?, ?)",
                [
                    (int) $row['log_id'],
                    isset($row['api_key_id']) ? (int) $row['api_key_id'] : null,
                    ConversationLogMediaStore::newKey(),
                    in_array($asset['media_type'] ?? '', ['image', 'audio', 'video'], true) ? (string) $asset['media_type'] : (string) $row['kind'],
                    (string) $row['task_id'],
                    Crypto::encrypt((string) $asset['asset_id']),
                    $asset['mime_type'] ?? null,
                    isset($asset['size_bytes']) ? (int) $asset['size_bytes'] : null,
                    (int) $row['position'] + 1 + $index,
                    (string) $row['created_at'],
                    $now,
                ]
            );
        }

        return true;
    }

    /**
     * Copies one linked / media-library file into the media directory.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     * @return string stored | failed | skipped | retry
     */
    private function copyMedia(array $row, array $settings): string
    {
        $max = (int) $settings['media_max_mb'] * 1048576;
        $finish = function (string $status, array $fields = []) use ($row): void {
            $fields += ['error' => null];
            $sets = ['status = ?', 'updated_at = ?'];
            $bindings = [$status, gmdate('Y-m-d H:i:s')];
            foreach ($fields as $column => $value) {
                $sets[] = $column . ' = ?';
                $bindings[] = $value;
            }
            $bindings[] = (int) $row['id'];
            $this->logs()->execute('UPDATE conversation_log_media SET ' . implode(', ', $sets) . ' WHERE id = ?', $bindings);
        };
        try {
            $ref = $this->decrypt($row['encrypted_ref'] ?? null);
            $relative = $this->mediaStore()->relativePath((string) $row['created_at'], $settings['timezone'], isset($row['api_key_id']) ? (int) $row['api_key_id'] : null, (string) $row['media_key']);
            $mime = is_string($row['mime'] ?? null) && $row['mime'] !== '' ? (string) $row['mime'] : null;
            if ($row['source'] === 'asset') {
                $asset = App::db()->first('SELECT storage_path, mime_type, size_bytes FROM media_assets WHERE asset_id = ?', [$ref]);
                if ($asset === null) {
                    $finish('failed', ['error' => 'asset_missing']);
                    return 'failed';
                }
                if (isset($asset['size_bytes']) && (int) $asset['size_bytes'] > $max) {
                    $finish('skipped', ['error' => 'too_large', 'size_bytes' => (int) $asset['size_bytes']]);
                    return 'skipped';
                }
                $mime ??= is_string($asset['mime_type'] ?? null) && $asset['mime_type'] !== '' ? (string) $asset['mime_type'] : null;
                $path = (string) $asset['storage_path'];
                if (preg_match('#^https?://#i', $path) === 1) {
                    $ref = $path;
                } else {
                    $local = new Media\LocalMediaStorage();
                    $real = $local->handles($path) ? Media\PathConfinement::resolve($path, $local->roots()) : null;
                    if ($real !== null) {
                        if ((int) filesize($real) > $max) {
                            $finish('skipped', ['error' => 'too_large', 'size_bytes' => (int) filesize($real)]);
                            return 'skipped';
                        }
                        $size = $this->mediaStore()->writeFile($relative, $real);
                        $mime ??= ConversationMediaCapture::sniff((string) file_get_contents($real, false, null, 0, 65536));
                    } else {
                        $driver = Media\MediaStorageManager::forPath($path);
                        $bytes = $driver !== null ? $driver->read($path) : false;
                        if ($bytes === false) {
                            throw new \RuntimeException('asset_unreadable');
                        }
                        $size = $this->mediaStore()->writeString($relative, $bytes);
                        $mime ??= ConversationMediaCapture::sniff($bytes);
                    }
                    $finish('stored', ['file' => $relative, 'size_bytes' => $size, 'mime' => $mime]);
                    return 'stored';
                }
            }
            if (preg_match('#^https?://#i', $ref) !== 1) {
                $finish('failed', ['error' => 'unsupported_source']);
                return 'failed';
            }
            $fetched = (new SafeRemoteFetcher(null, null, $max + 1))->get($ref, OutboundUrlPolicy::PURPOSE_REMOTE_ASSET);
            if (($fetched['ok'] ?? false) !== true) {
                throw new \RuntimeException((string) ($fetched['error'] ?? 'download_failed'));
            }
            $bytes = (string) ($fetched['body'] ?? '');
            if (strlen($bytes) > $max) {
                $finish('skipped', ['error' => 'too_large', 'size_bytes' => strlen($bytes)]);
                return 'skipped';
            }
            if ($bytes === '') {
                throw new \RuntimeException('empty_download');
            }
            $type = strtolower(trim(explode(';', (string) ($fetched['content_type'] ?? ''))[0]));
            $mime ??= preg_match('#^(image|video|audio)/[a-z0-9.+-]+$#', $type) === 1 ? $type : ConversationMediaCapture::sniff($bytes);
            $size = $this->mediaStore()->writeString($relative, $bytes);
            $finish('stored', ['file' => $relative, 'size_bytes' => $size, 'mime' => $mime]);

            return 'stored';
        } catch (Throwable $throwable) {
            $error = mb_substr($throwable->getMessage(), 0, 300);
            if ((int) $row['attempts'] >= self::MEDIA_ATTEMPTS) {
                $finish('failed', ['error' => $error]);
                return 'failed';
            }
            $finish('pending', ['error' => $error]);

            return 'retry';
        }
    }

    /**
     * Media of the given records, for the transcript views.
     *
     * @param list<int> $logIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function mediaFor(array $logIds): array
    {
        if ($logIds === []) {
            return [];
        }
        try {
            $rows = $this->logs()->select(
                'SELECT log_id, media_key, direction, kind, source, status, mime, encrypted_name, size_bytes, error, position FROM conversation_log_media WHERE log_id IN (' . implode(', ', array_map('intval', $logIds)) . ') ORDER BY log_id ASC, position ASC, id ASC'
            );
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['log_id']][] = [
                'key' => (string) $row['media_key'],
                'direction' => (string) $row['direction'],
                'kind' => (string) $row['kind'],
                'source' => (string) $row['source'],
                'status' => (string) $row['status'],
                'mime' => $row['mime'] ?? null,
                'name' => $this->decrypt($row['encrypted_name'] ?? null) ?: null,
                'size_bytes' => isset($row['size_bytes']) ? (int) $row['size_bytes'] : null,
                'error' => $row['error'] ?? null,
                'url' => $row['status'] === 'stored' ? '/admin-api/conversation-logs/media/' . $row['media_key'] : null,
            ];
        }

        return $out;
    }

    /**
     * Serves one stored media file (super admin), with byte ranges for video.
     */
    public function mediaResponse(string $mediaKey, ?string $range): Response
    {
        $this->requireSuperAdmin();
        $row = preg_match('/^[a-f0-9]{24}$/', $mediaKey) === 1
            ? $this->logs()->first("SELECT media_key, file, mime, kind FROM conversation_log_media WHERE media_key = ? AND status = 'stored'", [$mediaKey])
            : null;
        if ($row === null || empty($row['file'])) {
            abort_json('CONVERSATION_MEDIA_NOT_FOUND', '媒体文件不存在或已被清理', 404);
        }
        $store = $this->mediaStore();
        $file = (string) $row['file'];
        try {
            $size = $store->size($file);
        } catch (Throwable $throwable) {
            abort_json('CONVERSATION_MEDIA_UNAVAILABLE', '媒体文件无法读取：' . $throwable->getMessage(), 404);
        }
        $mime = strtolower((string) ($row['mime'] ?? ''));
        $inline = preg_match('#^(image|video|audio)/[a-z0-9.+-]+$#', $mime) === 1;
        $headers = [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $mediaKey . $this->extension($mime) . '"',
        ];
        $start = 0;
        $end = $size - 1;
        $status = 200;
        if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                // Open ranges are served in 8 MiB pieces; players ask for the next one.
                $end = $m[2] === '' ? min($size - 1, $start + 8388607) : min($size - 1, (int) $m[2]);
            }
            if ($start >= $size || $start > $end) {
                return Response::raw('', 416, ['Content-Range' => 'bytes */' . $size]);
            }
            $status = 206;
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }
        $headers['Content-Length'] = (string) max(0, $end - $start + 1);

        return Response::stream(static function () use ($store, $file, $start, $end, $size): void {
            if ($size === 0) {
                return;
            }
            $store->read($file, $start, $end, static function (string $bytes): void {
                echo $bytes;
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            });
        }, $status, $headers);
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/png' => '.png',
            'image/jpeg' => '.jpg',
            'image/webp' => '.webp',
            'image/gif' => '.gif',
            'video/mp4' => '.mp4',
            'video/webm' => '.webm',
            'audio/mpeg', 'audio/mp3' => '.mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave' => '.wav',
            'audio/ogg' => '.ogg',
            'audio/flac' => '.flac',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => '.m4a',
            default => '.bin',
        };
    }

    /**
     * Deletes the media rows (and files) of records about to be deleted.
     *
     * @param list<int> $logIds
     */
    private function deleteMediaFor(array $logIds, bool $files = true): void
    {
        if ($logIds === []) {
            return;
        }
        $in = implode(', ', array_map('intval', $logIds));
        if ($files) {
            foreach ($this->logs()->select("SELECT file FROM conversation_log_media WHERE log_id IN ({$in}) AND file IS NOT NULL") as $row) {
                try {
                    $this->mediaStore()->delete((string) $row['file']);
                } catch (Throwable $throwable) {
                    try {
                        App::logger()->warning('worker', 'conversation log media delete failed', ['file' => $row['file'], 'error' => $throwable->getMessage()]);
                    } catch (Throwable) {
                    }
                }
            }
        }
        $this->logs()->execute("DELETE FROM conversation_log_media WHERE log_id IN ({$in})");
    }

    /**
     * Media files no row points at (the gateway wrote them but the record was
     * never stored, or the rows are gone). Local files after a day, COS
     * objects of days before yesterday.
     */
    private function sweepOrphanMedia(string $today, ?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        $store = $this->mediaStore();
        $candidates = [];
        foreach ($store->files() as $relative => $info) {
            if ($info['mtime'] < $now - 86400) {
                $candidates[$relative] = true;
            }
        }
        try {
            $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            foreach (array_keys($store->remoteFiles()) as $relative) {
                if ($store->dayOf($relative) < $yesterday) {
                    $candidates[$relative] = true;
                }
            }
        } catch (Throwable) {
        }
        foreach (array_chunk(array_keys($candidates), 500) as $chunk) {
            $keys = array_map(static fn (string $relative): string => ConversationLogMediaStore::keyOf($relative), $chunk);
            $known = array_flip(array_column($this->logs()->select(
                'SELECT media_key FROM conversation_log_media WHERE media_key IN (' . implode(', ', array_fill(0, count($keys), '?')) . ')',
                $keys
            ), 'media_key'));
            foreach ($chunk as $relative) {
                if (!isset($known[ConversationLogMediaStore::keyOf($relative)])) {
                    try {
                        $store->delete($relative);
                        $removed++;
                    } catch (Throwable) {
                    }
                }
            }
        }

        return $removed;
    }

    public function store(): ConversationLogStore
    {
        if ($this->store === null) {
            $this->store = new ConversationLogStore();
            // With COS configured, reads and rewrites reach the cloud copies even
            // after switching back to local storage.
            $client = $this->cosClient();
            if ($client !== null) {
                $cos = $this->cosConfig();
                $this->store->useRemote($client, $cos['prefix'], $cos['storage_class']);
            }
        }

        return $this->store;
    }

    // ---------------------------------------------------------------- 腾讯云 COS

    /**
     * @return array<string, mixed> stored COS configuration (secret_key encrypted)
     */
    private function cosConfig(): array
    {
        $stored = $this->readSetting(self::COS_KEY);

        return [
            'secret_id' => (string) ($stored['secret_id'] ?? ''),
            'secret_key' => (string) ($stored['secret_key'] ?? ''),
            'bucket' => (string) ($stored['bucket'] ?? ''),
            'region' => (string) ($stored['region'] ?? ''),
            'prefix' => (string) ($stored['prefix'] ?? 'tokenhub/conversation-logs'),
            'endpoint' => (string) ($stored['endpoint'] ?? ''),
            'storage_class' => in_array($stored['storage_class'] ?? 'STANDARD', self::COS_STORAGE_CLASSES, true) ? (string) ($stored['storage_class'] ?? 'STANDARD') : 'STANDARD',
            'keep_local_days' => max(0, min(30, (int) ($stored['keep_local_days'] ?? 0))),
            'backup_index' => filter_var($stored['backup_index'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function cosClient(): ?TencentCosClient
    {
        $cos = $this->cosConfig();
        if ($cos['secret_id'] === '' || $cos['secret_key'] === '' || $cos['bucket'] === '' || $cos['region'] === '') {
            return null;
        }
        $secret = $this->decrypt($cos['secret_key']);

        return $secret === '' ? null : new TencentCosClient($cos['secret_id'], $secret, $cos['bucket'], $cos['region'], $cos['endpoint']);
    }

    /**
     * @return array<string, mixed>
     */
    public function cosSettings(): array
    {
        $this->requireSuperAdmin();

        return $this->cosView();
    }

    /**
     * COS settings for the page: never the secret key.
     *
     * @return array<string, mixed>
     */
    public function cosView(): array
    {
        $cos = $this->cosConfig();
        $id = $cos['secret_id'];

        return [
            'configured' => $this->cosClient() !== null,
            'secret_id' => $id === '' ? '' : substr($id, 0, 6) . str_repeat('*', max(0, strlen($id) - 10)) . substr($id, -4),
            'secret_key_set' => $cos['secret_key'] !== '',
            'bucket' => $cos['bucket'],
            'region' => $cos['region'],
            'prefix' => $cos['prefix'],
            'endpoint' => $cos['endpoint'],
            'storage_class' => $cos['storage_class'],
            'keep_local_days' => $cos['keep_local_days'],
            'backup_index' => $cos['backup_index'],
            'state' => $this->readSetting(self::COS_STATE_KEY) ?: null,
        ];
    }

    /**
     * @param array<string, mixed> $input secret_id, secret_key (empty = keep), bucket, region, prefix, endpoint, storage_class, keep_local_days, backup_index
     * @return array<string, mixed>
     */
    public function saveCos(array $input): array
    {
        $this->requireSuperAdmin();
        $current = $this->cosConfig();
        $secretId = trim((string) ($input['secret_id'] ?? ''));
        if ($secretId === '' || str_contains($secretId, '*')) {
            $secretId = $current['secret_id'];
        }
        $secretKey = trim((string) ($input['secret_key'] ?? ''));
        $bucket = trim((string) ($input['bucket'] ?? $current['bucket']));
        $region = trim((string) ($input['region'] ?? $current['region']));
        $prefix = trim((string) ($input['prefix'] ?? $current['prefix']), " /");
        $endpoint = rtrim(trim((string) ($input['endpoint'] ?? $current['endpoint'])), '/');
        $storageClass = strtoupper(trim((string) ($input['storage_class'] ?? $current['storage_class'])));
        $keepDays = $input['keep_local_days'] ?? $current['keep_local_days'];

        if ($secretId === '' || preg_match('/^[A-Za-z0-9]{8,128}$/', $secretId) !== 1) {
            abort_json('CONVERSATION_LOG_COS_INVALID', 'SecretId 格式不正确', 422);
        }
        if ($secretKey === '' && $current['secret_key'] === '') {
            abort_json('CONVERSATION_LOG_COS_INVALID', '请填写 SecretKey', 422);
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]*-\d{6,}$/', $bucket) !== 1) {
            abort_json('CONVERSATION_LOG_COS_INVALID', '存储桶名称格式应为 名称-APPID，例如 tokenhub-logs-1250000000', 422);
        }
        if (preg_match('/^[a-z]{2,}(-[a-z0-9]+)+$/', $region) !== 1) {
            abort_json('CONVERSATION_LOG_COS_INVALID', '地域格式不正确，例如 ap-guangzhou', 422);
        }
        if ($prefix !== '' && (preg_match('#^[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)*$#', $prefix) !== 1 || preg_match('#(^|/)\.{1,2}(/|$)#', $prefix) === 1)) {
            abort_json('CONVERSATION_LOG_COS_INVALID', '路径前缀只能包含字母、数字、-、_、. 和 /', 422);
        }
        if ($endpoint !== '' && (preg_match('#^https://[A-Za-z0-9.\-]+(:\d+)?$#', $endpoint) !== 1)) {
            abort_json('CONVERSATION_LOG_COS_INVALID', '自定义 Endpoint 需为 https:// 开头的域名，例如 https://tokenhub-logs-1250000000.cos.ap-guangzhou.myqcloud.com', 422);
        }
        if (!in_array($storageClass, self::COS_STORAGE_CLASSES, true)) {
            abort_json('CONVERSATION_LOG_COS_INVALID', '存储类型只支持标准存储或低频存储', 422);
        }
        if (!is_numeric($keepDays) || (int) $keepDays < 0 || (int) $keepDays > 30) {
            abort_json('CONVERSATION_LOG_COS_INVALID', '本地保留天数需为 0～30', 422);
        }

        $this->writeSetting(self::COS_KEY, [
            'secret_id' => $secretId,
            'secret_key' => $secretKey !== '' ? Crypto::encrypt($secretKey) : $current['secret_key'],
            'bucket' => $bucket,
            'region' => $region,
            'prefix' => $prefix,
            'endpoint' => $endpoint,
            'storage_class' => $storageClass,
            'keep_local_days' => (int) $keepDays,
            'backup_index' => filter_var($input['backup_index'] ?? $current['backup_index'], FILTER_VALIDATE_BOOLEAN),
        ], 'Conversation log Tencent COS settings');
        $this->store = null;
        (new AuditService())->write('conversation_log.cos_update', 'system_settings', self::COS_KEY, [
            'bucket' => $bucket, 'region' => $region, 'prefix' => $prefix, 'endpoint' => $endpoint,
            'storage_class' => $storageClass, 'keep_local_days' => (int) $keepDays, 'secret_key_changed' => $secretKey !== '',
        ]);

        return $this->cosView();
    }

    /**
     * Writes, reads, lists and deletes a probe object.
     *
     * @return array<string, mixed>
     */
    public function testCos(): array
    {
        $this->requireSuperAdmin();
        $client = $this->cosClient();
        if ($client === null) {
            abort_json('CONVERSATION_LOG_COS_NOT_CONFIGURED', '请先填写并保存腾讯云 COS 配置', 422);
        }
        $cos = $this->cosConfig();
        $key = ($cos['prefix'] === '' ? '' : $cos['prefix'] . '/') . '_probe/' . bin2hex(random_bytes(6)) . '.txt';
        $body = 'tokenhub conversation log probe ' . gmdate('c');
        $started = microtime(true);
        try {
            $client->put($key, $body);
            $read = $client->get($key);
            $listed = array_key_exists($key, $client->list(($cos['prefix'] === '' ? '' : $cos['prefix'] . '/') . '_probe/'));
            $client->delete($key);
        } catch (Throwable $throwable) {
            (new AuditService())->write('conversation_log.cos_test', 'system_settings', self::COS_KEY, ['ok' => false]);
            abort_json('CONVERSATION_LOG_COS_TEST_FAILED', $throwable->getMessage(), 502);
        }
        if ($read !== $body) {
            abort_json('CONVERSATION_LOG_COS_TEST_FAILED', '写入后读回的内容不一致', 502);
        }
        (new AuditService())->write('conversation_log.cos_test', 'system_settings', self::COS_KEY, ['ok' => true]);

        return ['ok' => true, 'host' => $client->host(), 'listed' => $listed, 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    /**
     * Uploads changed files to COS (every scheduler run in COS mode).
     *
     * @return array<string, mixed>
     */
    private function syncCos(string $today): array
    {
        $cos = $this->cosConfig();
        try {
            $result = $this->store()->sync($today, $cos['keep_local_days']);
        } catch (Throwable $throwable) {
            $result = ['uploaded' => 0, 'bytes' => 0, 'removed_local' => 0, 'errors' => [$throwable->getMessage()]];
        }
        try {
            $media = $this->mediaStore()->sync($today, $cos['keep_local_days']);
        } catch (Throwable $throwable) {
            $media = ['uploaded' => 0, 'bytes' => 0, 'removed_local' => 0, 'errors' => [$throwable->getMessage()]];
        }
        foreach (['uploaded', 'bytes', 'removed_local'] as $field) {
            $result[$field] += $media[$field];
        }
        $result['errors'] = [...$result['errors'], ...$media['errors']];
        $result['media_uploaded'] = $media['uploaded'];
        $state = $this->readSetting(self::COS_STATE_KEY);
        $state['last_sync_at'] = gmdate('Y-m-d H:i:s');
        $state['last_sync'] = $result;
        if ($result['uploaded'] > 0) {
            $state['last_upload_at'] = $state['last_sync_at'];
        }
        $state['last_error'] = $result['errors'][0] ?? null;
        $this->writeSetting(self::COS_STATE_KEY, $state, 'Conversation log Tencent COS state');
        if ($result['errors'] !== []) {
            try {
                App::logger()->warning('worker', 'conversation log COS sync errors', ['errors' => array_slice($result['errors'], 0, 5)]);
            } catch (Throwable) {
            }
        }

        return $result;
    }

    /**
     * Admin "立即同步到 COS".
     *
     * @return array<string, mixed>
     */
    public function syncCosNow(): array
    {
        $this->requireSuperAdmin();
        if ($this->cosClient() === null) {
            abort_json('CONVERSATION_LOG_COS_NOT_CONFIGURED', '请先填写并保存腾讯云 COS 配置', 422);
        }
        $settings = $this->settings();
        $today = (new DateTimeImmutable('now', new DateTimeZone($settings['timezone'])))->format('Y-m-d');
        $result = $this->syncCos($today);
        $this->refreshCosStats();
        (new AuditService())->write('conversation_log.cos_sync', 'conversation_logs', null, ['uploaded' => $result['uploaded'], 'errors' => count($result['errors'])]);

        return $result;
    }

    /**
     * Admin "迁回本地": downloads every COS file and removes it from COS.
     *
     * @return array<string, mixed>
     */
    public function pullFromCos(): array
    {
        $this->requireSuperAdmin();
        if ($this->settings()['storage_driver'] === 'cos') {
            abort_json('CONVERSATION_LOG_COS_IN_USE', '当前存储方式是腾讯云 COS，请先改为本地存储再迁回', 409);
        }
        if ($this->cosClient() === null) {
            abort_json('CONVERSATION_LOG_COS_NOT_CONFIGURED', '没有可用的腾讯云 COS 配置', 422);
        }
        $result = $this->store()->pullAll();
        $media = $this->mediaStore()->pullAll();
        $result['files'] += $media['files'];
        $result['errors'] = [...$result['errors'], ...$media['errors']];
        $result['media_files'] = $media['files'];
        $this->refreshCosStats();
        $this->remember('cos_pull', ['mode' => 'cos_pull', 'files' => $result['files']], 0);
        (new AuditService())->write('conversation_log.cos_pull', 'conversation_logs', null, ['files' => $result['files'], 'errors' => count($result['errors'])]);

        return $result;
    }

    /**
     * Daily copy of the SQLite index to COS (keeps the last 7).
     *
     * @return array<string, mixed>|null
     */
    private function backupIndexToCos(): ?array
    {
        $cos = $this->cosConfig();
        if (!$cos['backup_index'] || $this->logs()->driver() !== 'sqlite') {
            return null;
        }
        $temp = $this->store()->root() . DIRECTORY_SEPARATOR . '.index-backup-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $this->logs()->pdo()->exec('VACUUM INTO ' . $this->logs()->pdo()->quote($temp));
            $data = Crypto::encrypt((string) gzencode((string) file_get_contents($temp), 6));
            $prefix = $this->store()->remotePrefix() . '_index/';
            $this->store()->putObject($prefix . 'index-' . gmdate('Ymd-His') . '.sqlite.gz.enc', $data);
            $backups = array_keys($this->cosClient()?->list($prefix) ?? []);
            rsort($backups, SORT_STRING);
            foreach (array_slice($backups, self::INDEX_BACKUPS_KEPT) as $old) {
                $this->cosClient()?->delete($old);
            }

            return ['bytes' => strlen($data), 'kept' => min(count($backups), self::INDEX_BACKUPS_KEPT)];
        } catch (Throwable $throwable) {
            return ['error' => $throwable->getMessage()];
        } finally {
            @unlink($temp);
        }
    }

    private function refreshCosStats(): void
    {
        $state = $this->readSetting(self::COS_STATE_KEY);
        try {
            $files = $this->store()->remoteFiles();
            $bytes = 0;
            foreach ($files as $variants) {
                $bytes += ($variants['active'] ?? 0) + ($variants['packed'] ?? 0);
            }
            $media = $this->mediaStore()->remoteFiles();
            $state['remote'] = ['files' => count($files) + count($media), 'bytes' => $bytes + array_sum($media), 'media_files' => count($media), 'counted_at' => gmdate('Y-m-d H:i:s')];
        } catch (Throwable $throwable) {
            $state['last_error'] = $throwable->getMessage();
        }
        $this->writeSetting(self::COS_STATE_KEY, $state, 'Conversation log Tencent COS state');
    }

    /** The conversation log's own database (never the application database). */
    public function logs(): ConversationLogDb
    {
        return $this->logs ??= new ConversationLogDb();
    }

    /**
     * Content (system, messages, reply) of the given rows, from the directory
     * or, for older / fallback rows, from the encrypted columns.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function contents(array $rows): array
    {
        $byFile = [];
        $contents = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (($row['storage'] ?? 'db') === 'file' && !empty($row['content_file'])) {
                $byFile[(string) $row['content_file']][] = $id;
                continue;
            }
            $contents[$id] = [
                'system' => $this->decrypt($row['encrypted_system'] ?? null),
                'messages' => $this->decodeJson($row['encrypted_messages'] ?? null),
                'reply' => $this->decodeJson($row['encrypted_reply'] ?? null),
            ];
        }
        foreach ($byFile as $file => $ids) {
            try {
                $contents += $this->store()->read($file, $ids);
            } catch (Throwable $throwable) {
                foreach ($ids as $id) {
                    $contents[$id] = ['missing' => $throwable->getMessage()];
                }
            }
        }

        return $contents;
    }

    /**
     * API keys with logging on or with stored conversations, newest activity
     * first. keyword matches the key name, prefix or owner username.
     *
     * @param array<string, mixed> $query keyword, enabled (1/0), limit, offset
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function keys(array $query): array
    {
        $this->requireSuperAdmin();
        [$limit, $offset] = $this->page($query, 20);
        // Usage per key comes from the log database, key details from the application database.
        $stats = [];
        foreach ($this->logs()->select(
            'SELECT api_key_id, COUNT(*) AS turns, COUNT(DISTINCT session_key) AS sessions, MAX(id) AS last_id, MAX(created_at) AS last_at,
                    SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(total_tokens) AS total_tokens
             FROM conversation_logs WHERE api_key_id IS NOT NULL GROUP BY api_key_id'
        ) as $row) {
            $stats[(int) $row['api_key_id']] = $row;
        }
        $ids = array_keys($stats);
        foreach (App::db()->select('SELECT id FROM api_keys WHERE conversation_log_enabled = 1') as $row) {
            $ids[] = (int) $row['id'];
        }
        $ids = array_values(array_unique($ids));
        $keys = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            foreach (App::db()->select(
                "SELECT k.id, k.name, k.key_prefix, k.key_suffix, k.status, k.conversation_log_enabled, k.conversation_log_retention_days,
                        u.username, d.name AS department_name
                 FROM api_keys k
                 LEFT JOIN users u ON u.id = k.user_id
                 LEFT JOIN departments d ON d.id = k.department_id
                 WHERE k.id IN ({$placeholders})",
                $chunk
            ) as $row) {
                $keys[(int) $row['id']] = $row;
            }
        }
        $keyword = mb_strtolower(trim((string) ($query['keyword'] ?? '')), 'UTF-8');
        $onlyEnabled = isset($query['enabled']) && $query['enabled'] !== '' ? filter_var($query['enabled'], FILTER_VALIDATE_BOOLEAN) : null;
        $default = $this->settings()['retention_days'];
        $items = [];
        foreach ($ids as $id) {
            $key = $keys[$id] ?? null;
            $stat = $stats[$id] ?? [];
            $enabled = $key !== null && (int) $key['conversation_log_enabled'] === 1;
            if ($onlyEnabled !== null && $enabled !== $onlyEnabled) {
                continue;
            }
            if ($keyword !== '') {
                $haystack = mb_strtolower(implode(' ', [$key['name'] ?? '', $key['key_prefix'] ?? '', $key['username'] ?? '']), 'UTF-8');
                if ($key === null || !str_contains($haystack, $keyword)) {
                    continue;
                }
            }
            $items[] = [
                'api_key_id' => $id,
                'name' => $key['name'] ?? ('#' . $id . '（已删除的密钥）'),
                'display' => $key !== null ? ($key['key_prefix'] ?? '') . '****' . ($key['key_suffix'] ?? '') : '',
                'status' => $key['status'] ?? 'deleted',
                'username' => $key['username'] ?? null,
                'department_name' => $key['department_name'] ?? null,
                'enabled' => $enabled,
                'retention_days' => $this->retentionFor($key['conversation_log_retention_days'] ?? null, $default),
                'sessions' => (int) ($stat['sessions'] ?? 0),
                'turns' => (int) ($stat['turns'] ?? 0),
                'input_tokens' => (int) ($stat['input_tokens'] ?? 0),
                'output_tokens' => (int) ($stat['output_tokens'] ?? 0),
                'total_tokens' => (int) ($stat['total_tokens'] ?? 0),
                'last_at' => $stat['last_at'] ?? null,
                'last_id' => (int) ($stat['last_id'] ?? 0),
            ];
        }
        // Most recent conversation first, then keys that are on but have none yet.
        usort($items, static fn (array $a, array $b): int => [$b['last_id'], $b['api_key_id']] <=> [$a['last_id'], $a['api_key_id']]);

        return ['items' => array_slice($items, $offset, $limit), 'total' => count($items)];
    }

    /**
     * One key's conversation-log switch and retention.
     *
     * @return array<string, mixed>
     */
    public function keySettings(int $apiKeyId): array
    {
        $this->requireSuperAdmin();

        return $this->keySummary($apiKeyId) ?? abort_json('ADMIN_NOT_FOUND', 'API key not found', 404);
    }

    /**
     * @param array<string, mixed> $input enabled, retention_days (null/'' = default)
     * @return array<string, mixed>
     */
    public function saveKeySettings(int $apiKeyId, array $input): array
    {
        $this->requireSuperAdmin();
        $before = $this->keySummary($apiKeyId);
        if ($before === null) {
            abort_json('ADMIN_NOT_FOUND', 'API key not found', 404);
        }
        $enabled = array_key_exists('enabled', $input)
            ? (filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $before['enabled'])
            : $before['enabled'];
        $days = $input['retention_days'] ?? ($before['custom_retention'] ? $before['retention_days'] : null);
        if ($days === '' || $days === null) {
            $days = null;
        } elseif (!is_numeric($days) || (int) $days < 1 || (int) $days > self::MAX_RETENTION_DAYS) {
            abort_json('CONVERSATION_LOG_INVALID_RETENTION', '保存天数需为 1～3650 的整数', 422);
        } else {
            $days = (int) $days;
        }
        if ($enabled && !$before['enabled'] && !$this->settings()['enabled']) {
            abort_json('CONVERSATION_LOG_FEATURE_DISABLED', '请先在「系统设置 › 会话记录设置」中启用会话记录功能', 409);
        }
        App::db()->execute(
            'UPDATE api_keys SET conversation_log_enabled = ?, conversation_log_retention_days = ? WHERE id = ?',
            [$enabled ? 1 : 0, $days, $apiKeyId]
        );
        // The gateway reads the flag from the cached key row.
        try {
            $row = App::db()->first('SELECT key_hash FROM api_keys WHERE id = ?', [$apiKeyId]);
            if (isset($row['key_hash'])) {
                App::redis()->delete((new ApiKeyService())->cacheKey((string) $row['key_hash']));
            }
        } catch (Throwable) {
        }
        $after = $this->keySummary($apiKeyId);
        (new AuditService())->write('conversation_log.key_update', 'api_keys', $apiKeyId, [
            'before' => ['enabled' => $before['enabled'], 'retention_days' => $before['custom_retention'] ? $before['retention_days'] : null],
            'after' => ['enabled' => $after['enabled'] ?? $enabled, 'retention_days' => $days],
        ]);

        return $after ?? [];
    }

    /**
     * Sessions of one API key, most recent first, with their (decrypted) titles.
     *
     * @param array<string, mixed> $query from, to, limit, offset
     * @return array{api_key: array<string, mixed>|null, items: list<array<string, mixed>>, total: int}
     */
    public function sessions(int $apiKeyId, array $query): array
    {
        $this->requireSuperAdmin();
        [$where, $bindings] = $this->rangeWhere($query, 'c');
        array_unshift($where, 'c.api_key_id = ?');
        array_unshift($bindings, $apiKeyId);
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        [$limit, $offset] = $this->page($query, 20);

        $items = $this->logs()->select(
            "SELECT c.session_key, MIN(c.id) AS first_id, MAX(c.id) AS last_id, COUNT(*) AS turns,
                    MIN(c.created_at) AS started_at, MAX(c.created_at) AS last_at,
                    SUM(c.input_tokens) AS input_tokens, SUM(c.output_tokens) AS output_tokens, SUM(c.total_tokens) AS total_tokens,
                    SUM(CASE WHEN c.cost_currency IS NULL OR c.cost_currency = ? THEN c.cost_amount END) AS cost_amount,
                    GROUP_CONCAT(DISTINCT c.model_name) AS models
             FROM conversation_logs c
             {$sqlWhere}
             GROUP BY c.session_key
             ORDER BY MAX(c.id) DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$this->currency(), ...$bindings]
        );
        $total = $this->logs()->first("SELECT COUNT(DISTINCT c.session_key) AS total FROM conversation_logs c {$sqlWhere}", $bindings);

        $firstIds = array_map(static fn (array $row): int => (int) $row['first_id'], $items);
        $firsts = [];
        if ($firstIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($firstIds), '?'));
            foreach ($this->logs()->select(
                "SELECT c.id, c.encrypted_title, c.session_source FROM conversation_logs c WHERE c.id IN ({$placeholders})",
                $firstIds
            ) as $row) {
                $firsts[(int) $row['id']] = $row;
            }
        }
        $items = array_map(function (array $row) use ($firsts): array {
            $first = $firsts[(int) $row['first_id']] ?? [];
            $row = $this->numericRow($row);
            $row['title'] = $this->decrypt($first['encrypted_title'] ?? null) ?: '（无文本内容）';
            $row['session_source'] = $first['session_source'] ?? null;
            $row['models'] = array_values(array_filter(explode(',', (string) ($row['models'] ?? ''))));
            return $row;
        }, $items);

        (new AuditService())->write('conversation_log.list_sessions', 'api_keys', $apiKeyId);

        return ['api_key' => $this->keySummary($apiKeyId), 'summary' => $this->usageSummary($apiKeyId, $query), 'items' => $items, 'total' => (int) ($total['total'] ?? 0)];
    }

    /**
     * One API key's questions and answers, one row per call (newest first),
     * each with the tokens and cost it consumed, plus the totals.
     *
     * @param array<string, mixed> $query from, to, limit, offset
     * @return array<string, mixed>
     */
    public function calls(int $apiKeyId, array $query): array
    {
        $this->requireSuperAdmin();
        [$where, $bindings] = $this->rangeWhere($query, 'c');
        array_unshift($where, 'c.api_key_id = ?');
        array_unshift($bindings, $apiKeyId);
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        [$limit, $offset] = $this->page($query, 20);

        $rows = $this->logs()->select(
            "SELECT c.* FROM conversation_logs c {$sqlWhere} ORDER BY c.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
        $total = $this->logs()->first("SELECT COUNT(*) AS total FROM conversation_logs c {$sqlWhere}", $bindings);
        $contents = $this->contents($rows);
        $media = $this->mediaFor(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $items = array_map(fn (array $row): array => [
            ...$this->turnRow($row, $contents[(int) $row['id']] ?? null, $media[(int) $row['id']] ?? []),
            'session_key' => $row['session_key'],
            'system' => null,
        ], $rows);

        (new AuditService())->write('conversation_log.list_calls', 'api_keys', $apiKeyId, ['offset' => $offset]);

        return [
            'api_key' => $this->keySummary($apiKeyId),
            'summary' => $this->usageSummary($apiKeyId, $query),
            'items' => $items,
            'total' => (int) ($total['total'] ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Calls, tokens and cost of one key's logged conversations.
     *
     * @param array<string, mixed> $query from, to
     * @return array<string, mixed>
     */
    private function usageSummary(int $apiKeyId, array $query): array
    {
        [$where, $bindings] = $this->rangeWhere($query, 'c');
        array_unshift($where, 'c.api_key_id = ?');
        array_unshift($bindings, $apiKeyId);
        $row = $this->logs()->first(
            'SELECT COUNT(*) AS calls, COUNT(DISTINCT c.session_key) AS sessions, SUM(c.input_tokens) AS input_tokens, SUM(c.output_tokens) AS output_tokens,
                    SUM(c.total_tokens) AS total_tokens, SUM(c.cache_read_tokens) AS cache_read_tokens,
                    SUM(CASE WHEN c.cost_currency IS NULL OR c.cost_currency = ? THEN c.cost_amount END) AS cost_amount,
                    MIN(c.created_at) AS first_at, MAX(c.created_at) AS last_at
             FROM conversation_logs c WHERE ' . implode(' AND ', $where),
            [$this->currency(), ...$bindings]
        ) ?? [];

        return [
            'calls' => (int) ($row['calls'] ?? 0),
            'sessions' => (int) ($row['sessions'] ?? 0),
            'input_tokens' => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'total_tokens' => (int) ($row['total_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($row['cache_read_tokens'] ?? 0),
            'cost_amount' => $row['cost_amount'] ?? null,
            'currency' => $this->currency(),
            'first_at' => $row['first_at'] ?? null,
            'last_at' => $row['last_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function turnRow(array $row, ?array $content, array $media = []): array
    {
        return [
            'id' => (int) $row['id'],
            'request_id' => $row['request_id'],
            'created_at' => $row['created_at'],
            'model_name' => $row['model_name'],
            'format' => $row['format'],
            'status' => $row['status'],
            'input_tokens' => (int) $row['input_tokens'],
            'output_tokens' => (int) $row['output_tokens'],
            'total_tokens' => (int) ($row['total_tokens'] ?? 0) ?: (int) $row['input_tokens'] + (int) $row['output_tokens'],
            'cache_read_tokens' => (int) ($row['cache_read_tokens'] ?? 0),
            'cost_amount' => $row['cost_amount'] ?? null,
            'cost_currency' => $row['cost_currency'] ?? null,
            'latency_ms' => isset($row['latency_ms']) ? (int) $row['latency_ms'] : null,
            'system' => is_string($content['system'] ?? null) ? $content['system'] : '',
            'messages' => is_array($content['messages'] ?? null) ? $content['messages'] : null,
            'reply' => is_array($content['reply'] ?? null)
                ? $content['reply']
                : ($content === null || isset($content['missing']) ? ['error' => '会话记录文件缺失或无法读取'] : null),
            'params' => is_array($content['params'] ?? null) ? $content['params'] : null,
            'media' => $media,
        ];
    }

    private function currency(): string
    {
        return (new UsageService())->baseCurrency();
    }

    /**
     * One session's turns in chronological order, decrypted.
     *
     * @param array<string, mixed> $query limit, offset
     * @return array<string, mixed>
     */
    public function session(string $sessionKey, array $query): array
    {
        $this->requireSuperAdmin();
        if (preg_match('/^[0-9a-f]{64}$/', $sessionKey) !== 1) {
            abort_json('CONVERSATION_NOT_FOUND', 'Conversation not found', 404);
        }
        $meta = $this->logs()->first(
            'SELECT MIN(id) AS first_id, COUNT(*) AS turns, MIN(created_at) AS started_at, MAX(created_at) AS last_at, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens,
                    SUM(total_tokens) AS total_tokens, SUM(CASE WHEN cost_currency IS NULL OR cost_currency = ? THEN cost_amount END) AS cost_amount
             FROM conversation_logs WHERE session_key = ?',
            [$this->currency(), $sessionKey]
        );
        if ($meta === null || (int) ($meta['turns'] ?? 0) === 0) {
            abort_json('CONVERSATION_NOT_FOUND', 'Conversation not found', 404);
        }
        [$limit, $offset] = $this->page($query, 50, 200);
        $rows = $this->logs()->select(
            "SELECT c.* FROM conversation_logs c WHERE c.session_key = ? ORDER BY c.id ASC LIMIT {$limit} OFFSET {$offset}",
            [$sessionKey]
        );
        $keyNames = [];
        $keyIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['api_key_id'] ?? 0), $rows))));
        if ($keyIds !== []) {
            $keyNames = array_column(App::db()->select('SELECT id, name FROM api_keys WHERE id IN (' . implode(', ', array_fill(0, count($keyIds), '?')) . ')', $keyIds), 'name', 'id');
        }
        $firstId = (int) $meta['first_id'];
        $contents = $this->contents($rows);
        $media = $this->mediaFor(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $turns = array_map(function (array $row) use ($firstId, $contents, $keyNames, $media): array {
            return [
                ...$this->turnRow($row, $contents[(int) $row['id']] ?? null, $media[(int) $row['id']] ?? []),
                'api_key_id' => isset($row['api_key_id']) ? (int) $row['api_key_id'] : null,
                'api_key_name' => $keyNames[(int) ($row['api_key_id'] ?? 0)] ?? null,
                // History the client sent before logging saw this session.
                'prior_count' => (int) $row['id'] === $firstId ? (int) $row['prior_count'] : 0,
            ];
        }, $rows);
        $firstRow = $this->logs()->first('SELECT user_id, api_key_id FROM conversation_logs WHERE id = ?', [$firstId]) ?? [];
        $userId = (int) ($firstRow['user_id'] ?? 0);
        $apiKeyId = (int) ($firstRow['api_key_id'] ?? 0);

        (new AuditService())->write('conversation_log.view', 'conversation_logs', $firstId, ['session_key' => $sessionKey, 'api_key_id' => $apiKeyId, 'user_id' => $userId, 'offset' => $offset]);

        return [
            'session_key' => $sessionKey,
            'api_key' => $apiKeyId > 0 ? $this->keySummary($apiKeyId) : null,
            'user' => $this->userSummary($userId),
            'turns_total' => (int) $meta['turns'],
            'started_at' => $meta['started_at'],
            'last_at' => $meta['last_at'],
            'input_tokens' => (int) $meta['input_tokens'],
            'output_tokens' => (int) $meta['output_tokens'],
            'total_tokens' => (int) $meta['total_tokens'],
            'cost_amount' => $meta['cost_amount'] ?? null,
            'currency' => $this->currency(),
            'limit' => $limit,
            'offset' => $offset,
            'turns' => $turns,
        ];
    }

    /**
     * Scheduled cleanup (scheduler job cleanup.conversation-logs, every few
     * minutes): runs once a day after cleanup_time in the configured timezone
     * when auto_cleanup is on, and applies the retention rules.
     *
     * @return array<string, mixed>
     */
    public function cleanup(bool $force = false, ?int $now = null): array
    {
        $now ??= time();
        $settings = $this->settings();
        $zone = new DateTimeZone($settings['timezone']);
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
        $today = $local->format('Y-m-d');
        $state = $this->readSetting(self::STATE_KEY);
        $cosMode = $settings['storage_driver'] === 'cos' && $this->cosClient() !== null;
        if (!$force) {
            // Every scheduler run: results of finished generation tasks.
            try {
                $this->processMedia(50);
            } catch (Throwable $throwable) {
                try {
                    App::logger()->warning('worker', 'conversation log media copy failed', ['error' => $throwable->getMessage()]);
                } catch (Throwable) {
                }
            }
        }
        if ($cosMode && !$force) {
            // Every scheduler run: today's file and anything changed goes to COS.
            $this->syncCos($today);
        }
        if (!$force) {
            if (!$settings['auto_cleanup'] && !$settings['compress_daily'] && !$cosMode) {
                return ['skipped' => true, 'reason' => 'auto_cleanup_off'];
            }
            if ($local->format('H:i') < $settings['cleanup_time'] || ($state['last_date'] ?? null) === $today) {
                return ['skipped' => true, 'reason' => 'not_due'];
            }
        }

        // 1. Compress the files of earlier days. 2. Apply the retention rules.
        // 3. Remove files no row points at any more.
        // In COS mode files are always compressed before upload.
        $compressed = $settings['compress_daily'] || $cosMode ? $this->store()->compressDue($today) : ['files' => 0, 'bytes_before' => 0, 'bytes_after' => 0];
        $synced = $cosMode ? $this->syncCos($today) : null;
        $deleted = 0;
        if ($settings['auto_cleanup'] || $force) {
            [$where, $bindings] = $this->retentionWhere($settings, $now, null);
            $deleted = $where === '' ? 0 : $this->deleteBatched($where, $bindings);
        }
        $orphans = $this->sweepOrphanFiles();
        try {
            $orphans += $this->sweepOrphanMedia($today, $now);
        } catch (Throwable) {
        }
        $indexBackup = $cosMode ? $this->backupIndexToCos() : null;
        if ($cosMode) {
            $this->refreshCosStats();
        }
        $result = [
            'last_date' => $force ? ($state['last_date'] ?? null) : $today,
            'last_run_at' => gmdate('Y-m-d H:i:s', $now),
            'trigger' => $force ? 'manual' : 'schedule',
            'deleted' => $deleted,
            'compressed_files' => $compressed['files'],
            'compressed_bytes_before' => $compressed['bytes_before'],
            'compressed_bytes_after' => $compressed['bytes_after'],
            'orphan_files' => $orphans,
            'cos_uploaded' => $synced['uploaded'] ?? null,
            'cos_errors' => $synced !== null ? count($synced['errors']) : null,
            'index_backup' => $indexBackup,
        ];
        $this->writeSetting(self::STATE_KEY, $result, 'Conversation log cleanup state');
        if ($settings['auto_cleanup'] || $force) {
            $this->remember($force ? 'manual' : 'schedule', ['mode' => 'expired'], $deleted, $now);
        }
        if ($synced !== null && ($synced['uploaded'] > 0 || $synced['errors'] !== [])) {
            $this->remember('cos_sync', ['mode' => 'cos_sync', 'files' => $synced['uploaded'], 'bytes_after' => $synced['bytes'], 'error' => $synced['errors'][0] ?? null], 0, $now);
        }
        if ($compressed['files'] > 0) {
            $this->remember('compress', ['mode' => 'compress', 'files' => $compressed['files'], 'bytes_before' => $compressed['bytes_before'], 'bytes_after' => $compressed['bytes_after']], 0, $now);
        }
        try {
            App::logger()->info('worker', 'conversation logs cleaned', $result);
        } catch (Throwable) {
        }

        return $result;
    }

    /**
     * Admin "立即压缩": compresses every file of an earlier day now.
     *
     * @return array<string, mixed>
     */
    public function compressNow(): array
    {
        $this->requireSuperAdmin();
        $settings = $this->settings();
        $today = (new DateTimeImmutable('now', new DateTimeZone($settings['timezone'])))->format('Y-m-d');
        $result = $this->store()->compressDue($today);
        $this->remember('compress', ['mode' => 'compress', 'files' => $result['files'], 'bytes_before' => $result['bytes_before'], 'bytes_after' => $result['bytes_after']], 0);
        if ($settings['storage_driver'] === 'cos' && $this->cosClient() !== null) {
            $result['cos'] = $this->syncCos($today);
        }
        (new AuditService())->write('conversation_log.compress', 'conversation_logs', null, $result);

        return $result;
    }

    /**
     * Admin "立即清理过期记录" (applies the retention rules now).
     *
     * @return array<string, mixed>
     */
    public function cleanupNow(): array
    {
        $this->requireSuperAdmin();
        $result = $this->cleanup(true);
        (new AuditService())->write('conversation_log.cleanup', 'conversation_logs', null, $result);

        return $result;
    }

    /**
     * Manual cleanup by condition, with a dry run.
     *
     * Criteria: mode "expired" (retention rules: days, record cap, deleted
     * keys), "before" (everything created before `before`, a local date or
     * datetime in the configured timezone) or "all"; optional api_key_id
     * narrows any mode to one key.
     *
     * @param array<string, mixed> $criteria
     * @return array<string, mixed> matched (preview) or deleted
     */
    public function cleanupBy(array $criteria, bool $preview): array
    {
        $this->requireSuperAdmin();
        $settings = $this->settings();
        $mode = (string) ($criteria['mode'] ?? 'expired');
        $apiKeyId = isset($criteria['api_key_id']) && (int) $criteria['api_key_id'] > 0 ? (int) $criteria['api_key_id'] : null;
        $normalized = ['mode' => $mode, 'api_key_id' => $apiKeyId];
        $now = time();
        switch ($mode) {
            case 'expired':
                [$where, $bindings] = $this->retentionWhere($settings, $now, $apiKeyId);
                break;
            case 'before':
                $before = trim((string) ($criteria['before'] ?? ''));
                if ($before === '' || ($local = $this->localTime($before, $settings['timezone'])) === null) {
                    abort_json('CONVERSATION_LOG_INVALID_BEFORE', '请选择要删除哪一天之前的记录', 422);
                }
                $normalized['before'] = $local->format('Y-m-d H:i');
                $where = 'created_at < ?';
                $bindings = [gmdate('Y-m-d H:i:s', $local->getTimestamp())];
                break;
            case 'all':
                if (!$preview && ($criteria['confirm'] ?? '') !== 'PURGE') {
                    abort_json('CONVERSATION_LOG_PURGE_UNCONFIRMED', '删除全部记录需要确认', 422);
                }
                $where = '1 = 1';
                $bindings = [];
                break;
            default:
                abort_json('CONVERSATION_LOG_INVALID_MODE', '清理方式无效', 422);
        }
        if ($where !== '' && $apiKeyId !== null && $mode !== 'expired') {
            $where = 'api_key_id = ? AND (' . $where . ')';
            array_unshift($bindings, $apiKeyId);
        }
        if ($where === '') {
            return [...$normalized, $preview ? 'matched' : 'deleted' => 0];
        }
        if ($preview) {
            $row = $this->logs()->first('SELECT COUNT(*) AS total, MIN(created_at) AS oldest_at, MAX(created_at) AS newest_at FROM conversation_logs WHERE ' . $where, $bindings);

            return [...$normalized, 'matched' => (int) ($row['total'] ?? 0), 'oldest_at' => $row['oldest_at'] ?? null, 'newest_at' => $row['newest_at'] ?? null];
        }
        $deleted = $this->deleteBatched($where, $bindings, $mode === 'all' ? self::MAX_DELETE_BATCHES * 10 : self::MAX_DELETE_BATCHES);
        $this->remember('manual', $normalized, $deleted);
        (new AuditService())->write('conversation_log.cleanup_by', 'conversation_logs', $apiKeyId, [...$normalized, 'deleted' => $deleted]);

        return [...$normalized, 'deleted' => $deleted];
    }

    /**
     * Deletes one conversation (all its calls).
     *
     * @return array{deleted: int}
     */
    public function deleteSession(string $sessionKey): array
    {
        $this->requireSuperAdmin();
        if (preg_match('/^[0-9a-f]{64}$/', $sessionKey) !== 1) {
            abort_json('CONVERSATION_NOT_FOUND', 'Conversation not found', 404);
        }
        $first = $this->logs()->first('SELECT api_key_id FROM conversation_logs WHERE session_key = ? ORDER BY id ASC LIMIT 1', [$sessionKey]);
        if ($first === null) {
            abort_json('CONVERSATION_NOT_FOUND', 'Conversation not found', 404);
        }
        $deleted = $this->deleteBatched('session_key = ?', [$sessionKey]);
        $apiKeyId = isset($first['api_key_id']) ? (int) $first['api_key_id'] : null;
        $this->remember('session', ['mode' => 'session', 'api_key_id' => $apiKeyId], $deleted);
        (new AuditService())->write('conversation_log.delete_session', 'conversation_logs', null, ['session_key' => $sessionKey, 'api_key_id' => $apiKeyId, 'deleted' => $deleted]);

        return ['deleted' => $deleted];
    }

    /**
     * Deletes one call (one question and its answer).
     *
     * @return array{deleted: int}
     */
    public function deleteCall(int $id): array
    {
        $this->requireSuperAdmin();
        $row = $this->logs()->first('SELECT id, api_key_id, session_key FROM conversation_logs WHERE id = ?', [$id]);
        if ($row === null) {
            abort_json('CONVERSATION_NOT_FOUND', 'Conversation record not found', 404);
        }
        $deleted = $this->deleteBatched('id = ?', [$id]);
        $apiKeyId = isset($row['api_key_id']) ? (int) $row['api_key_id'] : null;
        $this->remember('call', ['mode' => 'call', 'api_key_id' => $apiKeyId], $deleted);
        (new AuditService())->write('conversation_log.delete_call', 'conversation_logs', $id, ['api_key_id' => $apiKeyId, 'session_key' => $row['session_key']]);

        return ['deleted' => $deleted];
    }

    /**
     * Admin "清空全部会话记录", or turning the feature off with "清空".
     *
     * @return array{deleted: int}
     */
    public function purgeAll(string $trigger = 'purge'): array
    {
        $this->requireSuperAdmin();
        $deleted = $this->deleteBatched('1 = 1', [], self::MAX_DELETE_BATCHES * 10, false);
        $this->logs()->execute('DELETE FROM conversation_log_media');
        $this->mediaStore()->deleteAllLocal();
        $this->store()->deleteAll();
        $this->remember($trigger, ['mode' => 'all'], $deleted);
        (new AuditService())->write('conversation_log.purge_all', 'conversation_logs', null, ['deleted' => $deleted, 'trigger' => $trigger]);

        return ['deleted' => $deleted];
    }

    /**
     * Retention rules as one WHERE clause: rows older than their key's
     * retention (or the default), rows beyond the per-key cap, and rows of
     * deleted keys when purge_deleted_keys is on.
     *
     * @param array<string, mixed> $settings
     * @return array{0: string, 1: list<mixed>}
     */
    private function retentionWhere(array $settings, int $now, ?int $onlyApiKeyId): array
    {
        $default = (int) $settings['retention_days'];
        $clauses = [];
        $bindings = [];
        $customIds = [];
        $customSql = 'SELECT id, conversation_log_retention_days AS days FROM api_keys WHERE conversation_log_retention_days IS NOT NULL';
        $customParams = [];
        if ($onlyApiKeyId !== null) {
            $customSql .= ' AND id = ?';
            $customParams[] = $onlyApiKeyId;
        }
        foreach (App::db()->select($customSql, $customParams) as $row) {
            $customIds[] = (int) $row['id'];
            $clauses[] = '(api_key_id = ? AND created_at < ?)';
            array_push($bindings, (int) $row['id'], gmdate('Y-m-d H:i:s', $now - $this->retentionFor($row['days'], $default) * 86400));
        }
        $defaultCutoff = gmdate('Y-m-d H:i:s', $now - $default * 86400);
        if ($onlyApiKeyId !== null) {
            if ($customIds === []) {
                $clauses[] = '(api_key_id = ? AND created_at < ?)';
                array_push($bindings, $onlyApiKeyId, $defaultCutoff);
            }
        } elseif ($customIds === []) {
            $clauses[] = '(created_at < ?)';
            $bindings[] = $defaultCutoff;
        } else {
            $clauses[] = '(created_at < ? AND (api_key_id IS NULL OR api_key_id NOT IN (' . implode(', ', array_fill(0, count($customIds), '?')) . ')))';
            array_push($bindings, $defaultCutoff, ...$customIds);
        }

        $cap = (int) $settings['max_records_per_key'];
        if ($cap > 0) {
            $over = $this->logs()->select(
                'SELECT api_key_id, COUNT(*) AS total FROM conversation_logs WHERE api_key_id IS NOT NULL' . ($onlyApiKeyId !== null ? ' AND api_key_id = ?' : '') . ' GROUP BY api_key_id HAVING COUNT(*) > ' . $cap,
                $onlyApiKeyId !== null ? [$onlyApiKeyId] : []
            );
            foreach ($over as $row) {
                // Keep the newest $cap rows of the key.
                $edge = $this->logs()->first(
                    'SELECT id FROM conversation_logs WHERE api_key_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . $cap,
                    [(int) $row['api_key_id']]
                );
                if ($edge !== null) {
                    $clauses[] = '(api_key_id = ? AND id <= ?)';
                    array_push($bindings, (int) $row['api_key_id'], (int) $edge['id']);
                }
            }
        }

        if ($settings['purge_deleted_keys']) {
            // Keys with logs that are deleted (or gone) in the application database.
            $logged = $onlyApiKeyId !== null
                ? [$onlyApiKeyId]
                : array_map('intval', array_column($this->logs()->select('SELECT DISTINCT api_key_id FROM conversation_logs WHERE api_key_id IS NOT NULL'), 'api_key_id'));
            $gone = [];
            foreach (array_chunk($logged, 500) as $chunk) {
                $alive = array_map('intval', array_column(App::db()->select(
                    "SELECT id FROM api_keys WHERE status <> 'deleted' AND id IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                    $chunk
                ), 'id'));
                array_push($gone, ...array_diff($chunk, $alive));
            }
            if ($gone !== []) {
                $clauses[] = '(api_key_id IN (' . implode(', ', array_fill(0, count($gone), '?')) . '))';
                array_push($bindings, ...array_values($gone));
            }
        }

        return [$clauses === [] ? '' : implode(' OR ', $clauses), $bindings];
    }

    /**
     * Keeps the last cleanups for the settings page.
     *
     * @param array<string, mixed> $criteria
     */
    private function remember(string $trigger, array $criteria, int $deleted, ?int $now = null): void
    {
        $actor = App::context()->adminUser();
        $history = array_values($this->readSetting(self::HISTORY_KEY));
        array_unshift($history, array_filter([
            'at' => gmdate('Y-m-d H:i:s', $now ?? time()),
            'trigger' => $trigger,
            'actor' => $actor['username'] ?? null,
            'mode' => $criteria['mode'] ?? null,
            'api_key_id' => $criteria['api_key_id'] ?? null,
            'api_key_name' => isset($criteria['api_key_id']) ? (App::db()->first('SELECT name FROM api_keys WHERE id = ?', [(int) $criteria['api_key_id']])['name'] ?? null) : null,
            'before' => $criteria['before'] ?? null,
            'files' => $criteria['files'] ?? null,
            'bytes_before' => $criteria['bytes_before'] ?? null,
            'bytes_after' => $criteria['bytes_after'] ?? null,
            'error' => $criteria['error'] ?? null,
            'deleted' => $deleted,
        ], static fn (mixed $value): bool => $value !== null));
        $this->writeSetting(self::HISTORY_KEY, array_slice($history, 0, self::HISTORY_SIZE), 'Conversation log cleanup history');
    }

    private function localTime(string $value, string $timezone): ?DateTimeImmutable
    {
        try {
            $zone = new DateTimeZone($timezone);
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone)
                : new DateTimeImmutable(str_replace('T', ' ', $value), $zone);
        } catch (Throwable) {
            return null;
        }

        return $parsed instanceof DateTimeImmutable ? $parsed : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(array $input, bool $strict = false): array
    {
        $defaults = $this->defaults();
        $bool = static fn (string $key): bool => filter_var($input[$key] ?? $defaults[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $defaults[$key];

        $cap = $input['max_records_per_key'] ?? $defaults['max_records_per_key'];
        if ($cap === '' || $cap === null) {
            $cap = 0;
        }
        if (!is_numeric($cap) || (int) $cap < 0 || (int) $cap > self::MAX_RECORDS_CAP) {
            if ($strict) {
                abort_json('CONVERSATION_LOG_INVALID_CAP', '每个密钥最多保留条数需为 0～10000000 的整数（0 表示不限制）', 422);
            }
            $cap = 0;
        }

        $days = $input['retention_days'] ?? $defaults['retention_days'];
        if (!is_numeric($days) || (int) $days < 1 || (int) $days > self::MAX_RETENTION_DAYS) {
            if ($strict) {
                abort_json('CONVERSATION_LOG_INVALID_RETENTION', '保存天数需为 1～3650 的整数', 422);
            }
            $days = $defaults['retention_days'];
        }

        $time = trim((string) ($input['cleanup_time'] ?? $defaults['cleanup_time']));
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $match) !== 1 || (int) $match[1] > 23 || (int) $match[2] > 59) {
            if ($strict) {
                abort_json('CONVERSATION_LOG_INVALID_TIME', '清理时间格式应为 HH:MM，例如 03:00', 422);
            }
            $time = $defaults['cleanup_time'];
        } else {
            $time = sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
        }

        $mediaMax = $input['media_max_mb'] ?? $defaults['media_max_mb'];
        if (!is_numeric($mediaMax) || (int) $mediaMax < 1 || (int) $mediaMax > self::MEDIA_MAX_MB) {
            if ($strict) {
                abort_json('CONVERSATION_LOG_INVALID_MEDIA_SIZE', '单个媒体文件上限需为 1～2048 MB', 422);
            }
            $mediaMax = $defaults['media_max_mb'];
        }

        $timezone = trim((string) ($input['timezone'] ?? $defaults['timezone']));
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true) && $timezone !== 'UTC') {
            if ($strict) {
                abort_json('CONVERSATION_LOG_INVALID_TIMEZONE', '时区无效', 422);
            }
            $timezone = $defaults['timezone'];
        }

        return [
            'enabled' => $bool('enabled'),
            'compress_daily' => $bool('compress_daily'),
            'retention_days' => (int) $days,
            'max_records_per_key' => (int) $cap,
            'purge_deleted_keys' => $bool('purge_deleted_keys'),
            'auto_cleanup' => $bool('auto_cleanup'),
            'cleanup_time' => $time,
            'timezone' => $timezone,
            'storage_driver' => in_array($input['storage_driver'] ?? 'local', ['local', 'cos'], true) ? (string) ($input['storage_driver'] ?? 'local') : 'local',
            'record_media' => $bool('record_media'),
            'media_max_mb' => (int) $mediaMax,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function nextCleanupAt(array $settings, ?int $now = null): string
    {
        $now ??= time();
        $zone = new DateTimeZone($settings['timezone']);
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
        [$hour, $minute] = array_map('intval', explode(':', $settings['cleanup_time']));
        $next = $local->setTime($hour, $minute);
        $state = $this->readSetting(self::STATE_KEY);
        if ($next->getTimestamp() <= $now && ($state['last_date'] ?? null) === $local->format('Y-m-d')) {
            $next = $next->modify('+1 day');
        } elseif ($next->getTimestamp() <= $now) {
            // Due now: the scheduler picks it up within a few minutes.
            return gmdate('Y-m-d H:i:s', $now);
        }

        return gmdate('Y-m-d H:i:s', $next->getTimestamp());
    }

    /**
     * @param array<string, mixed> $conversation
     * @return array{0: string, 1: string}
     */
    private function sessionKey(array $conversation, ?int $userId, ?int $apiKeyId): array
    {
        $previous = $conversation['previous_response_id'] ?? null;
        if (is_string($previous) && $previous !== '') {
            $row = $this->logs()->first(
                'SELECT session_key FROM conversation_logs WHERE response_id = ? AND ' . ($userId === null ? 'user_id IS NULL' : 'user_id = ?') . ' ORDER BY id DESC LIMIT 1',
                $userId === null ? [$previous] : [$previous, $userId]
            );
            if ($row !== null) {
                return [(string) $row['session_key'], 'response_chain'];
            }
        }
        $scope = ($userId ?? 0) . '|' . ($apiKeyId ?? 0);
        $hint = $conversation['session_hint'] ?? null;
        if (is_string($hint) && $hint !== '') {
            return [hash('sha256', 'client|' . $scope . '|' . $hint), 'client'];
        }

        return [hash('sha256', 'derived|' . $scope . '|' . (string) ($conversation['session_seed'] ?? '')), 'derived'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function keySummary(int $apiKeyId): ?array
    {
        $row = App::db()->first(
            'SELECT k.id, k.name, k.key_prefix, k.key_suffix, k.status, k.conversation_log_enabled, k.conversation_log_retention_days, u.username, d.name AS department_name
             FROM api_keys k LEFT JOIN users u ON u.id = k.user_id LEFT JOIN departments d ON d.id = k.department_id WHERE k.id = ?',
            [$apiKeyId]
        );
        if ($row === null) {
            return null;
        }
        $custom = $row['conversation_log_retention_days'] !== null && $row['conversation_log_retention_days'] !== '';

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'display' => ($row['key_prefix'] ?? '') . '****' . ($row['key_suffix'] ?? ''),
            'status' => $row['status'],
            'username' => $row['username'],
            'department_name' => $row['department_name'],
            'enabled' => (int) $row['conversation_log_enabled'] === 1,
            'retention_days' => $this->retentionFor($row['conversation_log_retention_days'], $this->settings()['retention_days']),
            'custom_retention' => $custom,
            'default_retention_days' => $this->settings()['retention_days'],
            'feature_enabled' => (bool) $this->settings()['enabled'],
        ];
    }

    private function retentionFor(mixed $days, int $default): int
    {
        return is_numeric($days) && (int) $days >= 1 ? min(self::MAX_RETENTION_DAYS, (int) $days) : $default;
    }

    /**
     * @param list<mixed> $bindings
     */
    private function deleteBatched(string $where, array $bindings, int $maxBatches = self::MAX_DELETE_BATCHES, bool $pruneFiles = true): int
    {
        // Day files that hold rows about to go: rewritten without them afterwards.
        $files = $pruneFiles
            ? array_column($this->logs()->select('SELECT DISTINCT content_file FROM conversation_logs WHERE content_file IS NOT NULL AND (' . $where . ')', $bindings), 'content_file')
            : [];
        $deleted = 0;
        // Select then delete by id: works the same on SQLite and MySQL.
        for ($i = 0; $i < $maxBatches; $i++) {
            $ids = array_map('intval', array_column($this->logs()->select('SELECT id FROM conversation_logs WHERE ' . $where . ' LIMIT ' . self::DELETE_BATCH, $bindings), 'id'));
            if ($ids === []) {
                break;
            }
            $this->deleteMediaFor($ids, $pruneFiles);
            $deleted += $this->logs()->execute('DELETE FROM conversation_logs WHERE id IN (' . implode(', ', $ids) . ')');
            if (count($ids) < self::DELETE_BATCH) {
                break;
            }
        }
        foreach ($files as $file) {
            $this->pruneFile((string) $file);
        }

        return $deleted;
    }

    /**
     * Rewrites one day file with only the rows that still exist.
     */
    private function pruneFile(string $file): void
    {
        try {
            $keep = array_map('intval', array_column($this->logs()->select('SELECT id FROM conversation_logs WHERE content_file = ?', [$file]), 'id'));
            $this->store()->prune($file, $keep);
        } catch (Throwable $throwable) {
            try {
                App::logger()->warning('worker', 'conversation log file prune failed', ['file' => $file, 'error' => $throwable->getMessage()]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * Removes day files no row points at (rows deleted elsewhere, crashes).
     */
    private function sweepOrphanFiles(): int
    {
        $removed = 0;
        $files = array_keys($this->store()->files());
        try {
            $files = array_values(array_unique([...$files, ...array_keys($this->store()->remoteFiles())]));
        } catch (Throwable) {
            // COS unreachable: sweep what is on disk now, the cloud next time.
        }
        foreach ($files as $file) {
            if ($this->logs()->first('SELECT id FROM conversation_logs WHERE content_file = ? LIMIT 1', [$file]) === null) {
                $this->store()->prune($file, []);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userSummary(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $row = App::db()->first(
            'SELECT u.id, u.username, u.status, u.department_id, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?',
            [$userId]
        );

        return $row === null ? ['id' => $userId, 'username' => null] : ['id' => (int) $row['id'], 'username' => $row['username'], 'status' => $row['status'], 'department_id' => (int) $row['department_id'], 'department_name' => $row['department_name']];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function rangeWhere(array $query, string $alias): array
    {
        $where = [];
        $bindings = [];
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $value = trim((string) ($query[$key] ?? ''));
            if ($value !== '' && strtotime($value) !== false) {
                $where[] = "{$alias}.created_at {$operator} ?";
                $bindings[] = gmdate('Y-m-d H:i:s', (int) strtotime($value));
            }
        }

        return [$where, $bindings];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: int, 1: int}
     */
    private function page(array $query, int $default, int $max = 100): array
    {
        $limit = (int) ($query['limit'] ?? $default);
        $limit = $limit > 0 ? min($max, $limit) : $default;

        return [$limit, max(0, (int) ($query['offset'] ?? 0))];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function numericRow(array $row): array
    {
        foreach (['user_id', 'turns', 'sessions', 'input_tokens', 'output_tokens', 'total_tokens', 'first_id', 'last_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }

    private function requireSuperAdmin(): void
    {
        $actor = App::context()->adminUser();
        if ($actor === null) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }
        if (($actor['role'] ?? '') !== 'super_admin') {
            abort_json('ADMIN_PERMISSION_DENIED', '只有超级管理员可以查看和设置会话记录', 403);
        }
    }

    private function decrypt(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        try {
            return Crypto::decrypt($value);
        } catch (Throwable) {
            return '';
        }
    }

    private function decodeJson(mixed $value): mixed
    {
        $plain = $this->decrypt($value);
        if ($plain === '') {
            return null;
        }
        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function utc(mixed $value): string
    {
        if (is_string($value) && $value !== '' && ($time = strtotime($value . (str_contains($value, 'T') || str_contains($value, '+') ? '' : ' UTC'))) !== false) {
            return gmdate('Y-m-d H:i:s', $time);
        }

        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    private function readSetting(string $key): array
    {
        try {
            $row = App::db()->first('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
        } catch (Throwable) {
            return [];
        }
        $value = $row['setting_value'] ?? null;
        $decoded = is_string($value) ? json_decode($value, true) : (is_array($value) ? $value : null);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function writeSetting(string $key, array $value, string $description): void
    {
        $json = $this->encode($value);
        $updated = App::db()->execute('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?', [$json, $key]);
        if ($updated === 0 && App::db()->first('SELECT setting_key FROM system_settings WHERE setting_key = ?', [$key]) === null) {
            App::db()->execute('INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)', [$key, $json, $description]);
        }
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
