<?php

declare(strict_types=1);

namespace TokenHub\Console\Install;

use Throwable;
use TokenHub\Console\MigrationRunner;
use TokenHub\Support\Config;
use TokenHub\Support\Database;
use TokenHub\Support\RedisClient;

/**
 * Installation and runtime self-check used by `bin/doctor.php`, `bin/install.php` and the Docker entrypoint.
 *
 * Every check returns ['level' => ok|warn|fail, 'check' => name, 'message' => text].
 */
final class Doctor
{
    public const MIN_PHP = '8.2.0';
    /** phpoffice/phpspreadsheet 2.x supports PHP < 8.6. */
    public const MAX_PHP_EXCLUSIVE = '8.6.0';
    public const MIN_MYSQL = '8.0.19';
    public const RECOMMENDED_MYSQL = '8.4.0';

    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'redis', 'mbstring', 'openssl', 'json', 'zlib', 'zip', 'gd',
        'ctype', 'dom', 'fileinfo', 'iconv', 'libxml', 'simplexml', 'xml', 'xmlreader', 'xmlwriter',
    ];

    /** Not required, but production installs should have them. */
    public const RECOMMENDED_EXTENSIONS = ['Zend OPcache', 'pcntl'];

    /**
     * Settings the configuration checks need, read from the application configuration.
     *
     * @return array<string, mixed>
     */
    public static function settingsFrom(Config $config): array
    {
        return [
            'env' => (string) $config->get('app.env', 'production'),
            'debug' => (bool) $config->get('app.debug', false),
            'key' => (string) $config->get('app.key', ''),
            'url' => (string) $config->get('app.url', ''),
            'csrf_secret' => (string) $config->get('app.admin_csrf_secret', ''),
            'session_secure' => (bool) $config->get('app.session_secure', true),
            'db_password' => (string) $config->get('database.password', ''),
            'redis_password' => (string) $config->get('redis.password', ''),
            'vector_store' => (string) $config->get('rag.vector_store.primary', 'qdrant'),
            'qdrant_url' => (string) $config->get('rag.qdrant.url', ''),
            'qdrant_api_key' => (string) $config->get('rag.qdrant.api_key', ''),
            'rag_local_embedding_fallback' => (bool) $config->get('rag.embedding.allow_local_fallback', false),
            'paths' => array_filter([
                'logs' => (string) $config->get('paths.logs', ''),
                'exports' => (string) $config->get('paths.exports', ''),
                'media' => (string) $config->get('paths.media', ''),
            ]),
            'initial_admin_password' => (string) (env('TOKENHUB_ADMIN_PASSWORD', '') ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<array{level: string, check: string, message: string}>
     */
    public static function configChecks(array $settings): array
    {
        $results = [];
        $production = !in_array(strtolower((string) $settings['env']), ['local', 'development', 'dev', 'testing', 'test'], true);
        $failOrWarn = $production ? 'fail' : 'warn';

        $key = (string) $settings['key'];
        if (EnvFile::isPlaceholder($key) || strlen($key) < 32) {
            $results[] = self::result($failOrWarn, 'APP_KEY', 'APP_KEY must be a random value of at least 32 characters (run `php bin/install.php env` or the installer).');
        } else {
            $results[] = self::result('ok', 'APP_KEY', 'set');
        }

        $csrf = (string) $settings['csrf_secret'];
        if (EnvFile::isPlaceholder($csrf) || strlen($csrf) < 16) {
            $results[] = self::result($failOrWarn, 'ADMIN_CSRF_SECRET', 'ADMIN_CSRF_SECRET must be a random value of at least 16 characters.');
        } else {
            $results[] = self::result('ok', 'ADMIN_CSRF_SECRET', 'set');
        }

        foreach (['db_password' => 'DB_PASSWORD', 'redis_password' => 'REDIS_PASSWORD'] as $field => $name) {
            if (EnvFile::isPlaceholder((string) $settings[$field])) {
                $results[] = self::result($failOrWarn, $name, $name . ' is empty or still a template value.');
            } else {
                $results[] = self::result('ok', $name, 'set');
            }
        }

        if ($production && (bool) $settings['debug']) {
            $results[] = self::result('fail', 'APP_DEBUG', 'APP_DEBUG must be false in production.');
        }

        $url = (string) $settings['url'];
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || !in_array($scheme, ['http', 'https'], true)) {
            $results[] = self::result('fail', 'APP_URL', 'APP_URL must be the address users open, e.g. https://tokenhub.example.com.');
        } elseif ((bool) $settings['session_secure'] && $scheme === 'http') {
            $results[] = self::result('fail', 'SESSION_SECURE', 'SESSION_SECURE=true but APP_URL uses http://; browsers drop the admin session cookie. Serve HTTPS or set SESSION_SECURE=false for plain-HTTP intranet access.');
        } elseif (!(bool) $settings['session_secure'] && $scheme === 'https') {
            $results[] = self::result('warn', 'SESSION_SECURE', 'APP_URL is https:// but SESSION_SECURE=false; set it to true.');
        } elseif ($production && !(bool) $settings['session_secure']) {
            $results[] = self::result('warn', 'SESSION_SECURE', 'Admin cookies are sent over plain HTTP. Use HTTPS outside trusted networks.');
        } else {
            $results[] = self::result('ok', 'APP_URL', $url);
        }

        $store = (string) $settings['vector_store'];
        if (!in_array($store, ['qdrant', 'mysql_fallback'], true)) {
            $results[] = self::result('fail', 'RAG_VECTOR_STORE', 'RAG_VECTOR_STORE must be qdrant or mysql_fallback.');
        } elseif ($store === 'qdrant' && (string) $settings['qdrant_url'] === '') {
            $results[] = self::result('fail', 'QDRANT_URL', 'QDRANT_URL is required when RAG_VECTOR_STORE=qdrant.');
        }
        if (!empty($settings['rag_local_embedding_fallback'])) {
            // The local fallback is a hashing placeholder, not a model: retrieval quality is random.
            $results[] = self::result($failOrWarn, 'RAG_ALLOW_LOCAL_EMBEDDING_FALLBACK', 'placeholder hash embeddings are enabled; bind every RAG vector config to an embedding model and set it to false.');
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<array{level: string, check: string, message: string}>
     */
    public static function platformChecks(array $settings, string $phpVersion = PHP_VERSION, ?callable $extensionLoaded = null): array
    {
        $extensionLoaded ??= 'extension_loaded';
        $results = [];

        if (version_compare($phpVersion, self::MIN_PHP, '<') || version_compare($phpVersion, self::MAX_PHP_EXCLUSIVE, '>=')) {
            $results[] = self::result('fail', 'PHP', 'PHP ' . $phpVersion . ' is not supported; use PHP >= ' . self::MIN_PHP . ' and < ' . self::MAX_PHP_EXCLUSIVE . '.');
        } else {
            $results[] = self::result('ok', 'PHP', $phpVersion);
        }

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $ext): bool => !$extensionLoaded($ext)));
        $results[] = $missing === []
            ? self::result('ok', 'PHP extensions', implode(', ', self::REQUIRED_EXTENSIONS))
            : self::result('fail', 'PHP extensions', 'missing: ' . implode(', ', $missing));

        $recommended = array_values(array_filter(self::RECOMMENDED_EXTENSIONS, static fn (string $ext): bool => !$extensionLoaded($ext)));
        if ($recommended !== []) {
            $results[] = self::result('warn', 'PHP extensions (optional)', 'missing: ' . implode(', ', $recommended) . ' (opcache: performance; pcntl: queue lease renewal for long jobs)');
        }

        $results[] = is_file(base_path('vendor/autoload.php'))
            ? self::result('ok', 'Composer', 'vendor/autoload.php present')
            : self::result('fail', 'Composer', 'vendor/ is missing; run `composer install --no-dev --optimize-autoloader`.');

        foreach ((array) ($settings['paths'] ?? []) as $name => $path) {
            $path = (string) $path;
            if ($path === '') {
                continue;
            }
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                $results[] = self::result('fail', 'storage ' . $name, $path . ' does not exist and cannot be created.');
            } elseif (!is_writable($path)) {
                $results[] = self::result('fail', 'storage ' . $name, $path . ' is not writable by ' . self::currentUser() . '.');
            } else {
                $results[] = self::result('ok', 'storage ' . $name, $path);
            }
        }

        return $results;
    }

    /**
     * Checks against the running services.
     *
     * @param array<string, mixed> $settings
     * @return list<array{level: string, check: string, message: string}>
     */
    public static function runtimeChecks(array $settings, Database $database, RedisClient $redis, string $migrationPath, bool $installed = true): array
    {
        $results = [];

        try {
            $row = $database->first('SELECT VERSION() AS version');
            $version = (string) ($row['version'] ?? '');
            $results[] = self::mysqlVersionResult($version);
        } catch (Throwable $exception) {
            $results[] = self::result('fail', 'MySQL', 'cannot connect: ' . self::reason($exception));
            $database = null;
        }

        $results[] = $redis->isHealthy()
            ? self::result('ok', 'Redis', 'reachable')
            : self::result('fail', 'Redis', 'cannot connect or authenticate (check REDIS_HOST / REDIS_PASSWORD).');
        if ($redis->isHealthy()) {
            // Quota counters and leases live only in Redis: an eviction policy
            // other than noeviction can silently reset a quota.
            try {
                $policy = $redis->connection()->config('GET', 'maxmemory-policy');
                $value = is_array($policy) ? (string) ($policy['maxmemory-policy'] ?? (array_values($policy)[1] ?? '')) : '';
                if ($value !== '') {
                    $results[] = $value === 'noeviction'
                        ? self::result('ok', 'Redis eviction', 'maxmemory-policy noeviction')
                        : self::result('warn', 'Redis eviction', "maxmemory-policy is {$value}; set noeviction so quota counters are never evicted.");
                }
            } catch (\Throwable) {
                // CONFIG may be disabled on managed Redis; nothing to report.
            }
        }

        if ((string) $settings['vector_store'] === 'qdrant') {
            $results[] = self::qdrantResult((string) $settings['qdrant_url'], (string) $settings['qdrant_api_key']);
        } else {
            $results[] = self::result('warn', 'Qdrant', 'RAG_VECTOR_STORE=mysql_fallback; vector search runs in MySQL (suitable for small knowledge bases only).');
        }

        if ($database !== null && $installed) {
            try {
                $applied = array_column($database->select('SELECT migration FROM migrations'), 'migration');
                $pending = MigrationRunner::pendingFrom(MigrationRunner::discover($migrationPath), array_map('strval', $applied));
                $results[] = $pending === []
                    ? self::result('ok', 'Migrations', count($applied) . ' applied')
                    : self::result('fail', 'Migrations', count($pending) . ' pending; run `php bin/migrate.php` (first pending: ' . $pending[0] . ').');
            } catch (Throwable $exception) {
                $results[] = self::result('fail', 'Migrations', 'schema not installed; run `php bin/install.php run`.');
            }

            try {
                $admin = $database->first("SELECT COUNT(*) AS total FROM users WHERE role = 'super_admin' AND status = 'active'");
                $results[] = (int) ($admin['total'] ?? 0) > 0
                    ? self::result('ok', 'Administrator', 'active super administrator present')
                    : self::result('fail', 'Administrator', 'no active super administrator; run `php bin/install.php admin --username=admin`.');
            } catch (Throwable) {
                $results[] = self::result('fail', 'Administrator', 'users table missing.');
            }

            if ((string) ($settings['initial_admin_password'] ?? '') !== '' && !EnvFile::isPlaceholder((string) $settings['initial_admin_password'])) {
                $results[] = self::result('warn', 'TOKENHUB_ADMIN_PASSWORD', 'the initial administrator password is still stored in the environment file; remove it after the first login.');
            }

            try {
                $unbound = $database->first('SELECT COUNT(*) AS total FROM rag_vector_configs WHERE enabled = 1 AND (provider_id IS NULL OR model_id IS NULL)');
                $total = (int) ($unbound['total'] ?? 0);
                $results[] = $total === 0
                    ? self::result('ok', 'RAG embedding models', 'every enabled vector config is bound to a model')
                    : self::result('warn', 'RAG embedding models', $total . ' enabled vector config(s) have no embedding model; their datasets cannot be indexed (placeholder vectors are refused unless RAG_ALLOW_LOCAL_EMBEDDING_FALLBACK=true).');
            } catch (Throwable) {
            }

            try {
                $results[] = $redis->get('operations:worker:queue-worker') !== null
                    ? self::result('ok', 'Queue worker', 'heartbeat present')
                    : self::result('warn', 'Queue worker', 'no heartbeat in the last 30 seconds; start the queue worker (docker: queue-worker service, linux: tokenhub-worker.service).');
            } catch (Throwable) {
            }
        }

        return $results;
    }

    /**
     * @return array{level: string, check: string, message: string}
     */
    public static function mysqlVersionResult(string $version): array
    {
        if (stripos($version, 'mariadb') !== false) {
            return self::result('warn', 'MySQL', $version . ' (MariaDB is not tested; MySQL 8.4 LTS or 9.x is recommended).');
        }
        preg_match('/^\d+\.\d+\.\d+/', $version, $m);
        $numeric = $m[0] ?? '0.0.0';
        if (version_compare($numeric, self::MIN_MYSQL, '<')) {
            return self::result('fail', 'MySQL', $version . ' is too old; MySQL >= ' . self::MIN_MYSQL . ' is required.');
        }
        if (version_compare($numeric, self::RECOMMENDED_MYSQL, '<')) {
            return self::result('warn', 'MySQL', $version . ' works but MySQL 8.4 LTS or newer is recommended.');
        }

        return self::result('ok', 'MySQL', $version);
    }

    /**
     * @param list<array{level: string, check: string, message: string}> $results
     */
    public static function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if ($result['level'] === 'fail') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{level: string, check: string, message: string}> $results
     */
    public static function render(array $results): string
    {
        $labels = ['ok' => '[ OK ]', 'warn' => '[WARN]', 'fail' => '[FAIL]'];
        $lines = [];
        foreach ($results as $result) {
            $lines[] = sprintf('%s %-26s %s', $labels[$result['level']] ?? '[ ?? ]', $result['check'], $result['message']);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @return array{level: string, check: string, message: string}
     */
    private static function qdrantResult(string $url, string $apiKey): array
    {
        if ($url === '') {
            return self::result('fail', 'Qdrant', 'QDRANT_URL is empty.');
        }
        $headers = "Accept: application/json\r\n";
        if ($apiKey !== '') {
            $headers .= 'api-key: ' . $apiKey . "\r\n";
        }
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'header' => $headers, 'ignore_errors' => true]]);
        $body = @file_get_contents(rtrim($url, '/') . '/collections', false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($body === false || $status === 0) {
            return self::result('fail', 'Qdrant', 'cannot reach ' . $url . '.');
        }
        if ($status === 401 || $status === 403) {
            return self::result('fail', 'Qdrant', 'rejected the API key; QDRANT_API_KEY must match the Qdrant service key.');
        }
        if ($status >= 400) {
            return self::result('fail', 'Qdrant', 'returned HTTP ' . $status . '.');
        }

        return self::result('ok', 'Qdrant', $url);
    }

    private static function reason(Throwable $exception): string
    {
        $previous = $exception->getPrevious();

        return $previous !== null ? $previous->getMessage() : $exception->getMessage();
    }

    private static function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info)) {
                return (string) $info['name'];
            }
        }

        return get_current_user();
    }

    /**
     * @return array{level: string, check: string, message: string}
     */
    private static function result(string $level, string $check, string $message): array
    {
        return ['level' => $level, 'check' => $check, 'message' => $message];
    }
}
