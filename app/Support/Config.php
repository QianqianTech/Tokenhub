<?php

declare(strict_types=1);

namespace TokenHub\Support;

final class Config
{
    /**
     * @var array<string, mixed>
     */
    private array $items = [];

    private readonly string $configPath;

    /**
     * Values this process loaded from a .env file, by key.
     *
     * @var array<string, string>
     */
    private static array $fileValues = [];

    public function __construct(string $configPathOrBasePath, ?string $envFile = null)
    {
        $candidate = rtrim($configPathOrBasePath, DIRECTORY_SEPARATOR);
        $this->configPath = is_dir($candidate . DIRECTORY_SEPARATOR . 'config')
            ? $candidate . DIRECTORY_SEPARATOR . 'config'
            : $candidate;

        $this->loadEnv($envFile ?? dirname($this->configPath) . DIRECTORY_SEPARATOR . '.env');
        $this->loadConfigFiles();
    }

    public static function default(): self
    {
        return new self(base_path());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    private function loadEnv(string $envFile): void
    {
        if (!is_file($envFile)) {
            return;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if ($key === '' || !$this->fileMayDefine($key)) {
                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
            self::$fileValues[$key] = $value;
        }
    }

    /**
     * Real environment variables win over the .env file (Docker/systemd
     * `environment:` entries, CI, tests that set $_ENV before booting).
     * A value this loader itself placed earlier may be refreshed, so a
     * rewritten .env (installer, key rotation) still takes effect on reload.
     */
    private function fileMayDefine(string $key): bool
    {
        if (array_key_exists($key, $_ENV)) {
            $current = (string) $_ENV[$key];
        } else {
            $fromProcess = getenv($key);
            if ($fromProcess === false) {
                return true;
            }
            $current = $fromProcess;
        }

        return array_key_exists($key, self::$fileValues) && self::$fileValues[$key] === $current;
    }

    private function loadConfigFiles(): void
    {
        foreach (glob(rtrim($this->configPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $this->items[$name] = require $file;
        }
    }
}
