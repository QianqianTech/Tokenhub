<?php

declare(strict_types=1);

namespace TokenHub\Support;

/**
 * Minimal Prometheus text format (0.0.4) writer.
 */
final class PrometheusWriter
{
    /** @var list<string> */
    private array $lines = [];

    /** @param list<array{0: array<string, string>, 1: int|float}> $samples */
    public function gauge(string $name, string $help, array $samples): void
    {
        $this->family($name, 'gauge', $help, $samples);
    }

    /** @param list<array{0: array<string, string>, 1: int|float}> $samples */
    public function counter(string $name, string $help, array $samples): void
    {
        $this->family($name, 'counter', $help, $samples);
    }

    /**
     * @param list<array{0: array<string, string>, 1: int|float}> $buckets
     * @param list<array{0: array<string, string>, 1: int|float}> $sums
     * @param list<array{0: array<string, string>, 1: int|float}> $counts
     */
    public function histogram(string $name, string $help, array $buckets, array $sums, array $counts): void
    {
        $this->lines[] = '# HELP ' . $name . ' ' . $help;
        $this->lines[] = '# TYPE ' . $name . ' histogram';
        foreach ($buckets as [$labels, $value]) {
            $this->lines[] = $name . '_bucket' . self::labels($labels) . ' ' . self::number($value);
        }
        foreach ($sums as [$labels, $value]) {
            $this->lines[] = $name . '_sum' . self::labels($labels) . ' ' . self::number($value);
        }
        foreach ($counts as [$labels, $value]) {
            $this->lines[] = $name . '_count' . self::labels($labels) . ' ' . self::number($value);
        }
    }

    public function render(): string
    {
        return implode("\n", $this->lines) . "\n";
    }

    /** @param list<array{0: array<string, string>, 1: int|float}> $samples */
    private function family(string $name, string $type, string $help, array $samples): void
    {
        $this->lines[] = '# HELP ' . $name . ' ' . $help;
        $this->lines[] = '# TYPE ' . $name . ' ' . $type;
        foreach ($samples as [$labels, $value]) {
            $this->lines[] = $name . self::labels($labels) . ' ' . self::number($value);
        }
    }

    /** @param array<string, string> $labels */
    public static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $key => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $key) ?: 'label';
            $parts[] = $key . '="' . str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], (string) $value) . '"';
        }

        return '{' . implode(',', $parts) . '}';
    }

    private static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }
}
