<?php

declare(strict_types=1);

namespace TokenHub\Support;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = (string) $this->config->get('database.mysql.host', $this->config->get('database.host', '127.0.0.1'));
        $port = (string) $this->config->get('database.mysql.port', $this->config->get('database.port', '3306'));
        $database = (string) $this->config->get('database.mysql.database', $this->config->get('database.database', 'tokenhub'));
        $charset = (string) $this->config->get('database.charset', 'utf8mb4');
        $username = (string) $this->config->get('database.mysql.username', $this->config->get('database.username', 'tokenhub'));
        $password = (string) $this->config->get('database.mysql.password', $this->config->get('database.password', ''));

        $persistent = $this->persistentEnabled();
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($persistent) {
            // PERF-011b: reuse the TCP + auth handshake across FPM requests.
            $options[PDO::ATTR_PERSISTENT] = true;
        }
        $initCommand = self::sessionInitSql();
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = $initCommand;
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
                $username,
                $password,
                $options,
            );
            if ($persistent) {
                self::resetReusedConnection($pdo);
            }
            // BIZ-001: every session runs in UTC so NOW()/CURRENT_TIMESTAMP
            // match the gmdate() values PHP writes. The init command only runs
            // on a fresh connect; re-asserting it is cheap and also covers a
            // reused persistent connection.
            if ($persistent || !defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $pdo->exec($initCommand);
            }
            $this->pdo = $pdo;
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed', 0, $exception);
        }

        return $this->pdo;
    }

    /** SQL run at the start of every session (BIZ-001: UTC session time zone). */
    public static function sessionInitSql(): string
    {
        return "SET time_zone = '+00:00'";
    }

    private function persistentEnabled(): bool
    {
        $value = $this->config->get('database.persistent', true);
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no', ''], true);
    }

    /**
     * A persistent connection may come back from a request that died inside a
     * transaction or while holding a named lock. Roll back and release before
     * the new request uses it, so no state leaks between requests.
     */
    public static function resetReusedConnection(PDO $pdo): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
        }
        try {
            $pdo->exec('ROLLBACK');
            $pdo->exec('DO RELEASE_ALL_LOCKS()');
        } catch (Throwable) {
            // Older servers without RELEASE_ALL_LOCKS(): the rollback above is
            // the part that matters.
        }
    }

    /**
     * @param array<string, mixed>|list<mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        if ($params !== []) {
            $statement = $this->pdo()->prepare($sql);
            $statement->execute($params);

            return $statement->rowCount();
        }

        $result = $this->pdo()->exec($sql);

        return $result === false ? 0 : $result;
    }

    /**
     * @param array<string, mixed>|list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function first(string $sql, array $params = []): ?array
    {
        $rows = $this->select($sql, $params);
        return $rows[0] ?? null;
    }

    /**
     * @param callable(self): mixed $callback
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            // Nested call (e.g. a service that opens its own transaction is
            // used inside another one): join the outer transaction so the
            // whole unit commits or rolls back together.
            return $callback($this);
        }
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            $pdo->rollBack();
            throw $throwable;
        }
    }

    public function health(): array
    {
        try {
            $this->pdo()->query('SELECT 1');

            return ['ok' => true];
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'database_unavailable'];
        }
    }

    public function healthy(): array
    {
        return $this->health();
    }

    public function isHealthy(): bool
    {
        return (bool) ($this->health()['ok'] ?? false);
    }
}
