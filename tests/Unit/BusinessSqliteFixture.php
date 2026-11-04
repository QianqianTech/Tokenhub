<?php

declare(strict_types=1);

/**
 * SQLite stand-in for the MySQL dialect used by the business / ops services
 * (UTC_TIMESTAMP(), DATE_SUB/DATE_ADD with INTERVAL, DATE_FORMAT, LIMIT on
 * DELETE). Only what those services need; see RagSqliteFixture for RAG.
 */
final class BusinessSqlitePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(self::translate($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(self::translate($statement));
    }

    public static function translate(string $query): string
    {
        $query = preg_replace_callback(
            '/DATE_(SUB|ADD)\(\s*UTC_TIMESTAMP\(\)\s*,\s*INTERVAL\s+(\?|\d+)\s+(SECOND|MINUTE|HOUR|DAY)\s*\)/i',
            static function (array $m): string {
                $sign = strtoupper($m[1]) === 'SUB' ? '-' : '+';
                $unit = strtolower($m[3]) . 's';
                $amount = $m[2] === '?' ? "' || ? || '" : $m[2];
                return "strftime('%Y-%m-%d %H:%M:%S', 'now', '{$sign}{$amount} {$unit}')";
            },
            $query
        ) ?? $query;
        // DELETE ... LIMIT n (MySQL) -> DELETE ... WHERE rowid IN (SELECT rowid ... LIMIT n)
        if (preg_match('/^\s*DELETE FROM (\w+) WHERE (.+) LIMIT (\d+)\s*$/is', $query, $m) === 1) {
            $query = "DELETE FROM {$m[1]} WHERE rowid IN (SELECT rowid FROM {$m[1]} WHERE {$m[2]} LIMIT {$m[3]})";
        }

        return $query;
    }
}

function business_sqlite(): BusinessSqlitePdo
{
    $pdo = new BusinessSqlitePdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('DATE_FORMAT', static function (?string $value, string $format): ?string {
        return $value === null ? null : gmdate(strtr($format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's']), strtotime($value . ' UTC'));
    });
    $pdo->sqliteCreateFunction('CONCAT', static fn (...$parts): string => implode('', array_map('strval', $parts)));

    return $pdo;
}

/** Points App::db() at the SQLite PDO and declares which optional columns exist. */
function business_use_sqlite(PDO $pdo, array $columns = []): void
{
    $database = new \TokenHub\Support\Database(\TokenHub\Support\App::config());
    (new ReflectionProperty(\TokenHub\Support\Database::class, 'pdo'))->setValue($database, $pdo);
    (new ReflectionProperty(\TokenHub\Support\App::class, 'database'))->setValue(null, $database);
    (new ReflectionProperty(\TokenHub\Support\SchemaInspector::class, 'memo'))->setValue(null, $columns);
}
