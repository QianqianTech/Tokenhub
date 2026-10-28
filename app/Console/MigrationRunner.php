<?php

declare(strict_types=1);

namespace TokenHub\Console;

use TokenHub\Support\Database;
use TokenHub\Support\Logger;

final class MigrationRunner
{
    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
        private readonly string $migrationPath,
    ) {
    }

    public function run(): int
    {
        $files = array_merge(
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*foundation*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*auth*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*logs_observability*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*api_key_model_quotas*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*api_key_management_scopes*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*api_key_management_delegations*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*external_sync*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*external_api*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*cache_usage_metrics*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*waf*.sql') ?: [],
            glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*rag*.sql') ?: [],
        );
        sort($files);

        $this->ensureMigrationTable();
        $batch = $this->nextBatch();
        $applied = 0;

        foreach ($files as $file) {
            $name = basename($file);

            if ($this->hasRun($name)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            foreach ($this->statements($sql) as $statementSql) {
                $this->executeStatement($statementSql);
            }

            $statement = $this->database->pdo()->prepare('INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)');
            $statement->execute(['migration' => $name, 'batch' => $batch]);

            $this->logger->audit('migration.applied', ['migration' => $name, 'batch' => $batch]);
            $applied++;
        }

        return $applied;
    }

    private function ensureMigrationTable(): void
    {
        $this->database->execute(
            'CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY migrations_migration_unique (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $hasBatch = $this->database->first(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = "migrations" AND column_name = "batch"'
        );
        if ($hasBatch === null) {
            $this->database->execute('ALTER TABLE migrations ADD COLUMN batch INT UNSIGNED NOT NULL DEFAULT 1 AFTER migration');
        }
    }

    private function hasRun(string $migration): bool
    {
        $rows = $this->database->select('SELECT migration FROM migrations WHERE migration = :migration LIMIT 1', ['migration' => $migration]);

        return $rows !== [];
    }

    private function nextBatch(): int
    {
        $rows = $this->database->select('SELECT MAX(batch) AS batch FROM migrations');
        $batch = (int) ($rows[0]['batch'] ?? 0);

        return $batch + 1;
    }

    private function executeStatement(string $sql): void
    {
        $statement = $this->database->pdo()->query($sql);
        if ($statement === false) {
            return;
        }

        $statement->fetchAll();
        $statement->closeCursor();
    }

    /**
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $statements = [];
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
