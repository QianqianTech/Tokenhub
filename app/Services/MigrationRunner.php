<?php

declare(strict_types=1);

namespace TokenHub\Services;

use PDO;
use TokenHub\Support\Database;

final class MigrationRunner
{
    public function __construct(
        private readonly Database $database,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function run(): array
    {
        $pdo = $this->database->pdo();
        $this->ensureMigrationsTable($pdo);

        $applied = $this->appliedMigrations($pdo);
        $newMigrations = [];

        foreach ($this->migrationFiles() as $file) {
            $version = basename($file, '.sql');

            if (in_array($version, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }

            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $statement = $pdo->prepare('INSERT INTO migrations (version, executed_at) VALUES (:version, UTC_TIMESTAMP())');
                $statement->execute(['version' => $version]);
                $pdo->commit();
            } catch (\Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            $newMigrations[] = $version;
        }

        return $newMigrations;
    }

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(190) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array<int, string>
     */
    private function appliedMigrations(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT version FROM migrations ORDER BY version ASC');

        return array_map(static fn (array $row): string => (string) $row['version'], $statement->fetchAll());
    }

    /**
     * @return array<int, string>
     */
    private function migrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);

        return $files;
    }
}

