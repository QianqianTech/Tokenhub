<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class MigrationRunner
{
    public function __construct(private Database $database, private string $path)
    {
    }

    public function run(): array
    {
        $this->database->execute('CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $executed = array_column($this->database->select('SELECT migration FROM migrations'), 'migration');
        $ran = [];

        $files = array_merge(
            glob($this->path . DIRECTORY_SEPARATOR . '*foundation*.sql') ?: [],
            glob($this->path . DIRECTORY_SEPARATOR . '*auth*.sql') ?: [],
        );
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $executed, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            $this->database->pdo()->exec($sql);
            $this->database->execute('INSERT INTO migrations (migration) VALUES (?)', [$name]);
            $ran[] = $name;
        }

        return $ran;
    }
}
