<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Console\MigrationRunner;
use TokenHub\Support\App;

$pdo = App::db()->pdo();
$execute = static function (string $sql) use ($pdo): void {
    $statement = $pdo->query($sql);
    if ($statement !== false) {
        if ($statement->columnCount() > 0) {
            $statement->fetchAll();
        }
        $statement->closeCursor();
    }
};

foreach (['2026_07_08_000002_content_retention_export.sql', '2026_07_08_000009_foundation_audit_logs_admin_columns.sql'] as $migration) {
    $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration);
    $statements = MigrationRunner::statements($sql);
    $backfills = [];
    foreach ($statements as $index => $statement) {
        if (str_starts_with($statement, 'UPDATE audit_logs SET')) {
            $backfills[] = $statement;
        } elseif (str_starts_with($statement, 'SET @sql') && str_contains($statement, 'UPDATE audit_logs SET')) {
            array_push($backfills, ...array_slice($statements, $index, 4));
        }
    }

    foreach ([false, true] as $legacy) {
        $table = 'audit_migration_test_' . bin2hex(random_bytes(6));
        try {
            $execute("CREATE TABLE `$table` (id INT PRIMARY KEY, actor_user_id BIGINT NULL, client_ip VARCHAR(64) NULL, details JSON NULL)");
            $execute("INSERT INTO `$table` VALUES (1, NULL, NULL, NULL), (2, 42, '192.0.2.42', JSON_OBJECT('keep', 'original'))");
            if ($legacy) {
                $execute("ALTER TABLE `$table` ADD actor_id BIGINT NULL, ADD ip_address VARCHAR(64) NULL, ADD metadata JSON NULL");
                $execute("UPDATE `$table` SET actor_id = 7, ip_address = '192.0.2.7', metadata = JSON_OBJECT('legacy', 'copied')");
            }
            $execute('SET @schema_name = DATABASE()');
            for ($run = 0; $run < 2; $run++) {
                foreach ($backfills as $statement) {
                    $execute(str_replace('audit_logs', $table, $statement));
                }
            }
            $rows = $pdo->query("SELECT actor_user_id, client_ip, details FROM `$table` ORDER BY id")->fetchAll();
            if ($legacy) {
                assert_same(7, (int) $rows[0]['actor_user_id'], $migration . ' must backfill the legacy actor.');
                assert_same('192.0.2.7', $rows[0]['client_ip'], $migration . ' must backfill the legacy IP.');
                assert_same(['legacy' => 'copied'], json_decode($rows[0]['details'], true), $migration . ' must preserve legacy audit details.');
            } else {
                assert_same(null, $rows[0]['actor_user_id'], $migration . ' must support a fresh schema without legacy columns.');
                assert_same(null, $rows[0]['client_ip'], $migration . ' must leave missing legacy data empty.');
                assert_same(null, $rows[0]['details'], $migration . ' must leave missing legacy details empty.');
            }
            assert_same(42, (int) $rows[1]['actor_user_id'], $migration . ' must not overwrite the canonical actor.');
            assert_same('192.0.2.42', $rows[1]['client_ip'], $migration . ' must not overwrite the canonical IP.');
            assert_same(['keep' => 'original'], json_decode($rows[1]['details'], true), $migration . ' must not overwrite canonical audit details.');
        } finally {
            $execute("DROP TABLE IF EXISTS `$table`");
        }
    }
}
