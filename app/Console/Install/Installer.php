<?php

declare(strict_types=1);

namespace TokenHub\Console\Install;

use RuntimeException;
use Throwable;
use TokenHub\Console\MigrationRunner;
use TokenHub\Support\Database;
use TokenHub\Support\Logger;
use TokenHub\Support\RedisClient;

/**
 * First installation and upgrade steps shared by bin/install.php and bin/upgrade.php.
 * Every step is idempotent: running the installer again only applies what is missing.
 */
final class Installer
{
    public const STATE_KEY = 'install.state';

    /** @var callable(string): void */
    private $output;

    public function __construct(
        private readonly Database $database,
        private readonly RedisClient $redis,
        private readonly Logger $logger,
        private readonly string $basePath,
        ?callable $output = null,
    ) {
        $this->output = $output ?? static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        };
    }

    public static function version(string $basePath): string
    {
        $file = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'VERSION';
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : 'dev';
    }

    /**
     * Wait until MySQL and Redis accept connections (containers start in parallel).
     */
    public function waitForServices(int $timeoutSeconds): void
    {
        $deadline = time() + max(0, $timeoutSeconds);
        $lastError = '';
        do {
            try {
                $this->database->first('SELECT 1 AS ok');
                if (!$this->redis->isHealthy()) {
                    throw new RuntimeException('Redis is not reachable or rejected the password');
                }

                return;
            } catch (Throwable $exception) {
                $previous = $exception->getPrevious();
                $lastError = $previous !== null ? $previous->getMessage() : $exception->getMessage();
            }
            if (time() >= $deadline) {
                break;
            }
            ($this->output)('waiting for MySQL/Redis: ' . $lastError);
            sleep(3);
        } while (true);

        throw new RuntimeException('Services not ready after ' . $timeoutSeconds . 's: ' . $lastError);
    }

    public function migrate(): int
    {
        $runner = new MigrationRunner($this->database, $this->logger, $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations');

        return $runner->run(function (string $name): void {
            ($this->output)('  migrating ' . $name);
        });
    }

    public function hasSuperAdmin(): bool
    {
        $row = $this->database->first("SELECT COUNT(*) AS total FROM users WHERE role = 'super_admin' AND status = 'active'");

        return (int) ($row['total'] ?? 0) > 0;
    }

    /**
     * Create or reset a super administrator.
     */
    public function upsertSuperAdmin(string $username, string $password): void
    {
        self::assertAdminCredentials($username, $password);
        $this->database->execute(
            "INSERT INTO users (department_id, username, password_hash, role, admin_type, admin_scope, admin_permission_mode, status, managed_department_ids)
             VALUES (1, ?, ?, 'super_admin', 'super_admin', 'global', 'preset', 'active', JSON_ARRAY(1))
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'super_admin', admin_type = 'super_admin',
                 admin_scope = 'global', status = 'active'",
            [$username, password_hash($password, PASSWORD_DEFAULT)]
        );
        $this->logger->audit('install.super_admin', ['username' => $username]);
    }

    public static function assertAdminCredentials(string $username, string $password): void
    {
        if (preg_match('/^[A-Za-z0-9_.@-]{3,120}$/', $username) !== 1) {
            throw new RuntimeException('Administrator username must be 3-120 characters: letters, digits, _ . @ -');
        }
        if (strlen($password) < 12 || EnvFile::isPlaceholder($password)) {
            throw new RuntimeException('Administrator password must be at least 12 characters and not a template value.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function state(): ?array
    {
        try {
            $row = $this->database->first('SELECT setting_value FROM system_settings WHERE setting_key = ?', [self::STATE_KEY]);
        } catch (Throwable) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $value = json_decode((string) $row['setting_value'], true);

        return is_array($value) ? $value : null;
    }

    public function recordState(string $event, string $mode): void
    {
        $state = $this->state() ?? [];
        $now = gmdate('c');
        $state['version'] = self::version($this->basePath);
        $state['mode'] = $mode;
        $state['installed_at'] ??= $now;
        $state['updated_at'] = $now;
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        $history[] = ['event' => $event, 'version' => $state['version'], 'at' => $now];
        $state['history'] = array_slice($history, -20);

        $this->database->execute(
            'INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP',
            [self::STATE_KEY, json_encode($state, JSON_UNESCAPED_SLASHES), 'Installed TokenHub version and install/upgrade history']
        );
    }
}
