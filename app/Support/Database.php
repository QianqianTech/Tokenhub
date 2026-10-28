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

        try {
            $this->pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed', 0, $exception);
        }

        return $this->pdo;
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
