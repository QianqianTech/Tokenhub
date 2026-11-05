<?php

declare(strict_types=1);

namespace TokenHub\Services;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * The conversation log's own database (会话记录独立数据库). Never the
 * TokenHub application database: conversation volume cannot grow it.
 *
 * Default: a SQLite file next to the content files,
 *   {CONVERSATION_LOG_PATH or storage/conversation-logs}/index.sqlite
 * (WAL mode; on the storage volume in Docker, so it is backed up with it).
 * For heavy traffic point it at a separate MySQL database instead:
 *   CONVERSATION_LOG_DB_DSN=mysql:host=...;port=3306;dbname=tokenhub_conversation;charset=utf8mb4
 *   CONVERSATION_LOG_DB_USER=... CONVERSATION_LOG_DB_PASSWORD=...
 * The schema is created on first use. It holds the index (key, session,
 * time, tokens, cost, title); questions and answers live in the day files
 * (ConversationLogStore) and only fall back to the encrypted columns here
 * when the directory cannot be written.
 */
final class ConversationLogDb
{
    private static ?PDO $shared = null;
    private static ?string $sharedDsn = null;
    private static bool $injected = false;

    /** Tests: use this connection (SQLite in memory) instead of the configured one. */
    public static function useConnection(?PDO $pdo): void
    {
        self::$shared = $pdo;
        self::$sharedDsn = $pdo === null ? null : 'injected';
        self::$injected = $pdo !== null;
        if ($pdo !== null) {
            (new self())->ensureSchema($pdo);
        }
    }

    public static function reset(): void
    {
        self::$shared = null;
        self::$sharedDsn = null;
        self::$injected = false;
    }

    public function dsn(): string
    {
        $dsn = trim((string) env_value('CONVERSATION_LOG_DB_DSN', ''));

        return $dsn !== '' ? $dsn : 'sqlite:' . (new ConversationLogStore())->root() . DIRECTORY_SEPARATOR . 'index.sqlite';
    }

    public function pdo(): PDO
    {
        if (self::$injected && self::$shared !== null) {
            return self::$shared;
        }
        $dsn = $this->dsn();
        if (self::$shared !== null && self::$sharedDsn === $dsn) {
            return self::$shared;
        }
        if (str_starts_with($dsn, 'sqlite:')) {
            $file = substr($dsn, 7);
            $directory = dirname($file);
            if ($file !== ':memory:' && !is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('Conversation log database directory is not writable: ' . $directory);
            }
        }
        $pdo = new PDO($dsn, (string) env_value('CONVERSATION_LOG_DB_USER', '') ?: null, (string) env_value('CONVERSATION_LOG_DB_PASSWORD', '') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if ($this->driverOf($pdo) === 'sqlite') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 15000');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        } else {
            $pdo->exec("SET time_zone = '+00:00'");
        }
        $this->ensureSchema($pdo);
        self::$shared = $pdo;
        self::$sharedDsn = $dsn;

        return $pdo;
    }

    public function driver(): string
    {
        return $this->driverOf($this->pdo());
    }

    /**
     * Where the data is, for the settings page (never the password).
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $dsn = self::$injected ? 'sqlite::memory:' : $this->dsn();
        if (str_starts_with($dsn, 'sqlite:')) {
            $file = substr($dsn, 7);
            $bytes = 0;
            foreach (['', '-wal', '-shm'] as $suffix) {
                $bytes += is_file($file . $suffix) ? (int) filesize($file . $suffix) : 0;
            }

            return ['driver' => 'sqlite', 'location' => $file, 'bytes' => $bytes];
        }
        $location = preg_replace('/(password|pwd)=[^;]*/i', '$1=***', $dsn) ?? $dsn;

        return ['driver' => 'mysql', 'location' => $location, 'bytes' => null];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param list<mixed> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach (array_values($params) as $index => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    private function driverOf(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function ensureSchema(PDO $pdo): void
    {
        if ($this->driverOf($pdo) === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS conversation_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                usage_record_id INTEGER NULL UNIQUE,
                request_id TEXT NULL,
                user_id INTEGER NULL,
                department_id INTEGER NULL,
                api_key_id INTEGER NULL,
                model_id INTEGER NULL,
                model_name TEXT NULL,
                protocol TEXT NULL,
                format TEXT NULL,
                session_key TEXT NOT NULL,
                session_source TEXT NOT NULL DEFAULT 'derived',
                status TEXT NOT NULL DEFAULT 'success',
                input_tokens INTEGER NOT NULL DEFAULT 0,
                output_tokens INTEGER NOT NULL DEFAULT 0,
                total_tokens INTEGER NOT NULL DEFAULT 0,
                cache_read_tokens INTEGER NOT NULL DEFAULT 0,
                cost_amount NUMERIC NULL,
                cost_currency TEXT NULL,
                latency_ms INTEGER NULL,
                prior_count INTEGER NOT NULL DEFAULT 0,
                response_id TEXT NULL,
                system_hash TEXT NULL,
                storage TEXT NOT NULL DEFAULT 'file',
                content_file TEXT NULL,
                system_stored INTEGER NOT NULL DEFAULT 0,
                encrypted_title TEXT NULL,
                encrypted_system TEXT NULL,
                encrypted_messages TEXT NULL,
                encrypted_reply TEXT NULL,
                created_at TEXT NOT NULL
            )");
            foreach ([
                'idx_conversation_logs_session ON conversation_logs (session_key, id)',
                'idx_conversation_logs_api_key ON conversation_logs (api_key_id, id)',
                'idx_conversation_logs_api_key_created ON conversation_logs (api_key_id, created_at)',
                'idx_conversation_logs_created ON conversation_logs (created_at)',
                'idx_conversation_logs_response ON conversation_logs (response_id)',
                'idx_conversation_logs_content_file ON conversation_logs (content_file, id)',
            ] as $index) {
                $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $index);
            }

            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS conversation_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usage_record_id BIGINT UNSIGNED NULL,
            request_id VARCHAR(80) NULL,
            user_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            api_key_id BIGINT UNSIGNED NULL,
            model_id BIGINT UNSIGNED NULL,
            model_name VARCHAR(190) NULL,
            protocol VARCHAR(40) NULL,
            format VARCHAR(40) NULL,
            session_key CHAR(64) NOT NULL,
            session_source VARCHAR(20) NOT NULL DEFAULT 'derived',
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            cache_read_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            cost_amount DECIMAL(18,8) NULL,
            cost_currency CHAR(3) NULL,
            latency_ms INT UNSIGNED NULL,
            prior_count INT UNSIGNED NOT NULL DEFAULT 0,
            response_id VARCHAR(190) NULL,
            system_hash CHAR(64) NULL,
            storage VARCHAR(10) NOT NULL DEFAULT 'file',
            content_file VARCHAR(64) NULL,
            system_stored TINYINT(1) NOT NULL DEFAULT 0,
            encrypted_title TEXT NULL,
            encrypted_system MEDIUMTEXT NULL,
            encrypted_messages MEDIUMTEXT NULL,
            encrypted_reply MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_conversation_logs_usage (usage_record_id),
            INDEX idx_conversation_logs_session (session_key, id),
            INDEX idx_conversation_logs_api_key (api_key_id, id),
            INDEX idx_conversation_logs_api_key_created (api_key_id, created_at),
            INDEX idx_conversation_logs_created (created_at),
            INDEX idx_conversation_logs_response (response_id),
            INDEX idx_conversation_logs_content_file (content_file, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
