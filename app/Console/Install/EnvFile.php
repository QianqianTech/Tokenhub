<?php

declare(strict_types=1);

namespace TokenHub\Console\Install;

/**
 * Reads and writes `.env` files while keeping the template's comments and key order.
 */
final class EnvFile
{
    /** Placeholder values that must never reach a production installation. */
    public const PLACEHOLDERS = ['', 'change-me', 'changeme', 'change-me-to-32-plus-random-characters', 'tokenhub_secret', 'root_secret', 'secret', 'password'];

    /**
     * @param list<string> $lines
     */
    private function __construct(private array $lines)
    {
    }

    public static function fromString(string $content): self
    {
        $content = str_replace("\r\n", "\n", $content);

        return new self(explode("\n", rtrim($content, "\n")));
    }

    public static function fromFile(string $path): self
    {
        $content = is_file($path) ? file_get_contents($path) : '';

        return self::fromString($content === false ? '' : $content);
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $content): array
    {
        return self::fromString($content)->values();
    }

    /**
     * @return array<string, string>
     */
    public function values(): array
    {
        $values = [];
        foreach ($this->lines as $line) {
            $pair = self::pair($line);
            if ($pair !== null) {
                $values[$pair[0]] = $pair[1];
            }
        }

        return $values;
    }

    public function get(string $key): ?string
    {
        return $this->values()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values());
    }

    /**
     * Replace the value of an existing key, or append it at the end.
     */
    public function set(string $key, string $value): self
    {
        $formatted = $key . '=' . self::quote($value);
        foreach ($this->lines as $index => $line) {
            $pair = self::pair($line);
            if ($pair !== null && $pair[0] === $key) {
                $this->lines[$index] = $formatted;

                return $this;
            }
        }
        $this->lines[] = $formatted;

        return $this;
    }

    /**
     * Keys whose values are still template placeholders.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public function placeholderKeys(array $keys): array
    {
        $values = $this->values();
        $found = [];
        foreach ($keys as $key) {
            if (self::isPlaceholder($values[$key] ?? '')) {
                $found[] = $key;
            }
        }

        return $found;
    }

    public static function isPlaceholder(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, self::PLACEHOLDERS, true) || str_starts_with($normalized, 'change-me');
    }

    public function toString(): string
    {
        return implode("\n", $this->lines) . "\n";
    }

    public static function secret(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'Az'), '=');
    }

    /**
     * Alphanumeric secret, safe inside shell commands, URLs and Compose interpolation.
     */
    public static function password(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function pair(string $line): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            return null;
        }
        [$key, $value] = explode('=', $trimmed, 2);
        $key = trim($key);
        if (str_starts_with($key, 'export ')) {
            $key = trim(substr($key, 7));
        }

        return [$key, trim($value, " \t\n\r\0\x0B\"'")];
    }

    private static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@,+\-]*$/', $value) === 1) {
            return $value;
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }
}
