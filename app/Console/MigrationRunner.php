<?php

declare(strict_types=1);

namespace TokenHub\Console;

use RuntimeException;
use Throwable;
use TokenHub\Support\Database;
use TokenHub\Support\Logger;

/**
 * The single migration runner used by `bin/migrate.php`, `bin/install.php` and `bin/upgrade.php`.
 *
 * Every `*.sql` file in the migration directory is applied once, in file-name order. The run holds a
 * MySQL advisory lock so that several containers (php, queue-worker, init) starting at the same time
 * cannot apply the same migration twice.
 */
final class MigrationRunner
{
    public const LOCK_NAME = 'tokenhub:migrations';

    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
        private readonly string $migrationPath,
        private readonly int $lockTimeoutSeconds = 120,
    ) {
    }

    /**
     * All migration files, sorted the same way on every platform.
     *
     * @return list<string> absolute paths
     */
    public static function discover(string $migrationPath): array
    {
        $files = glob(rtrim($migrationPath, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

        return $files;
    }

    /**
     * @param list<string> $applied migration names already recorded
     * @return list<string> migration names still to apply
     */
    public static function pendingFrom(array $files, array $applied): array
    {
        $done = array_fill_keys($applied, true);
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!isset($done[$name])) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * Split a migration file into executable statements. Statements are separated by `;`;
     * `--` comment lines are dropped and semicolons inside quoted strings are respected.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote === null && self::startsLineComment($sql, $i)) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $buffer .= "\n";
                continue;
            }

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\') {
                    $buffer .= $sql[++$i] ?? '';
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private static function startsLineComment(string $sql, int $i): bool
    {
        if ($sql[$i] === '#') {
            return true;
        }

        return $sql[$i] === '-'
            && ($sql[$i + 1] ?? '') === '-'
            && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true);
    }

    /**
     * Apply all pending migrations.
     *
     * @param (callable(string): void)|null $progress called with each migration name before it runs
     */
    public function run(?callable $progress = null): int
    {
        return $this->withLock(function () use ($progress): int {
            $this->ensureMigrationTable();
            $batch = $this->nextBatch();
            $applied = 0;

            foreach ($this->pendingFiles() as $file) {
                $name = basename($file);
                if ($progress !== null) {
                    $progress($name);
                }

                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException('Unable to read migration ' . $name);
                }

                try {
                    foreach (self::statements($sql) as $statementSql) {
                        $this->executeStatement($statementSql);
                    }
                } catch (Throwable $exception) {
                    $this->logger->error('Migration failed', ['migration' => $name, 'error' => $exception->getMessage()]);
                    throw new RuntimeException('Migration ' . $name . ' failed: ' . $exception->getMessage(), 0, $exception);
                }

                $statement = $this->database->pdo()->prepare('INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)');
                $statement->execute(['migration' => $name, 'batch' => $batch]);

                $this->logger->audit('migration.applied', ['migration' => $name, 'batch' => $batch]);
                $applied++;
            }

            return $applied;
        });
    }

    /**
     * @return array{applied: list<string>, pending: list<string>}
     */
    public function status(): array
    {
        $this->ensureMigrationTable();
        $applied = $this->appliedMigrations();

        return [
            'applied' => $applied,
            'pending' => self::pendingFrom(self::discover($this->migrationPath), $applied),
        ];
    }

    /**
     * @return list<string>
     */
    public function pendingFiles(): array
    {
        $pending = array_fill_keys(self::pendingFrom(self::discover($this->migrationPath), $this->appliedMigrations()), true);

        return array_values(array_filter(
            self::discover($this->migrationPath),
            static fn (string $file): bool => isset($pending[basename($file)])
        ));
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $row = $this->database->first('SELECT GET_LOCK(:name, :timeout) AS acquired', [
            'name' => self::LOCK_NAME,
            'timeout' => $this->lockTimeoutSeconds,
        ]);
        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Another process is running migrations; lock not acquired within ' . $this->lockTimeoutSeconds . ' seconds.');
        }

        try {
            return $callback();
        } finally {
            try {
                $this->database->first('SELECT RELEASE_LOCK(:name) AS released', ['name' => self::LOCK_NAME]);
            } catch (Throwable) {
            }
        }
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

    /**
     * @return list<string>
     */
    private function appliedMigrations(): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['migration'],
            $this->database->select('SELECT migration FROM migrations ORDER BY migration')
        ));
    }

    private function nextBatch(): int
    {
        $rows = $this->database->select('SELECT MAX(batch) AS batch FROM migrations');

        return (int) ($rows[0]['batch'] ?? 0) + 1;
    }

    private function executeStatement(string $sql): void
    {
        $statement = $this->database->pdo()->query($sql);
        if ($statement === false) {
            return;
        }

        if ($statement->columnCount() > 0) {
            $statement->fetchAll();
        }
        $statement->closeCursor();
    }
}
