<?php

declare(strict_types=1);

namespace TokenHub\Services;

use RuntimeException;
use TokenHub\Support\App;
use TokenHub\Support\SchemaInspector;

/**
 * How rag_vector_records keeps a copy of each vector (used to reuse unchanged embeddings and
 * by the MySQL fallback store). Since migration 2026_11_04_000001 vectors are stored as
 * base64-encoded little-endian float32 in vector_f32 (about 5.5 KB for 1024 dimensions instead
 * of about 20 KB of JSON). Rows written before keep vector_json until
 * `php bin/rag-index.php compact-vectors` converts them; both forms are always readable.
 */
final class RagVectorCodec
{
    private static ?bool $compact = null;

    /** Tests and tools: force (true/false) or re-detect (null) the compact column. */
    public static function useCompact(?bool $compact): void
    {
        self::$compact = $compact;
    }

    public static function compact(): bool
    {
        return self::$compact ??= SchemaInspector::hasColumn('rag_vector_records', 'vector_f32');
    }

    /**
     * Column => value pairs to store one vector.
     *
     * @param list<float> $vector
     * @return array<string, string|null>
     */
    public static function storage(array $vector): array
    {
        return self::compact()
            ? ['vector_json' => null, 'vector_f32' => self::encode($vector)]
            : ['vector_json' => json_encode(array_values($vector), JSON_UNESCAPED_SLASHES)];
    }

    /** Columns to select so decode() can read either form. */
    public static function selectColumns(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return self::compact() ? $prefix . 'vector_json, ' . $prefix . 'vector_f32' : $prefix . 'vector_json';
    }

    /** SQL condition "this row holds a stored vector". */
    public static function presentCondition(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return self::compact() ? '(' . $prefix . 'vector_f32 IS NOT NULL OR ' . $prefix . 'vector_json IS NOT NULL)' : $prefix . 'vector_json IS NOT NULL';
    }

    /** @param list<float> $vector */
    public static function encode(array $vector): string
    {
        return base64_encode(pack('g*', ...array_map('floatval', array_values($vector))));
    }

    /**
     * @param array<string, mixed> $row
     * @return list<float>|null
     */
    public static function decode(array $row): ?array
    {
        $compact = $row['vector_f32'] ?? null;
        if (is_string($compact) && $compact !== '') {
            $bytes = base64_decode($compact, true);
            if ($bytes === false || $bytes === '' || strlen($bytes) % 4 !== 0) {
                return null;
            }
            $values = unpack('g*', $bytes);

            return $values === false ? null : array_values(array_map('floatval', $values));
        }
        $json = $row['vector_json'] ?? null;
        if (!is_string($json) || $json === '') {
            return null;
        }
        $vector = json_decode($json, true);

        return is_array($vector) && $vector !== [] ? array_values(array_map('floatval', $vector)) : null;
    }

    /**
     * Converts JSON vectors written before the compact column existed (CLI compact-vectors).
     *
     * @return array{converted: int, invalid: int}
     */
    public static function compactStoredVectors(int $batchSize = 500): array
    {
        if (!self::compact()) {
            throw new RuntimeException('Run the database migrations first (rag_vector_records.vector_f32 is missing)');
        }
        $batchSize = max(10, min(5000, $batchSize));
        $converted = 0;
        $invalid = 0;
        $lastId = 0;
        do {
            $rows = App::db()->select('SELECT id, vector_json FROM rag_vector_records WHERE id > ? AND vector_json IS NOT NULL AND vector_f32 IS NULL ORDER BY id LIMIT ' . $batchSize, [$lastId]);
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $vector = self::decode(['vector_json' => $row['vector_json']]);
                if ($vector === null) {
                    $invalid++;
                    continue;
                }
                App::db()->execute('UPDATE rag_vector_records SET vector_f32 = ?, vector_json = NULL WHERE id = ?', [self::encode($vector), $lastId]);
                $converted++;
            }
        } while (count($rows) === $batchSize);

        return ['converted' => $converted, 'invalid' => $invalid];
    }
}
