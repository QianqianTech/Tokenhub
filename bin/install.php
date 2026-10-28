<?php

declare(strict_types=1);

/*
 * TokenHub installer.
 *
 *   php bin/install.php env   [options]   write a .env with random secrets from .env.example
 *   php bin/install.php run   [options]   check, wait for MySQL/Redis, migrate, create the first super admin
 *   php bin/install.php admin --username=NAME [--password=...]   create or reset a super administrator
 *   php bin/install.php check            same as php bin/doctor.php
 *
 * Run `php bin/install.php help` for all options. Every step can be repeated safely.
 */

use TokenHub\Console\Install\Doctor;
use TokenHub\Console\Install\EnvFile;
use TokenHub\Console\Install\Installer;
use TokenHub\Console\Install\SiteAddress;
use TokenHub\Support\Config;
use TokenHub\Support\Database;
use TokenHub\Support\Logger;
use TokenHub\Support\RedisClient;

$basePath = dirname(__DIR__);
require_once $basePath . '/app/Bootstrap/autoload.php';

/**
 * @return array{0: string, 1: array<string, list<string>>}
 */
function tokenhub_install_args(array $argv): array
{
    $command = 'help';
    $options = [];
    foreach (array_slice($argv, 1) as $index => $arg) {
        if ($index === 0 && !str_starts_with($arg, '-')) {
            $command = $arg;
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        [$name, $value] = str_contains($arg, '=') ? explode('=', $arg, 2) : [$arg, 'true'];
        $options[$name][] = $value;
    }

    return [$command, $options];
}

function tokenhub_opt(array $options, string $name, ?string $default = null): ?string
{
    $values = $options[$name] ?? [];

    return $values === [] ? $default : (string) end($values);
}

function tokenhub_flag(array $options, string $name): bool
{
    return in_array(strtolower((string) tokenhub_opt($options, $name, 'false')), ['1', 'true', 'yes'], true);
}

function tokenhub_say(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function tokenhub_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit($code);
}

function tokenhub_prompt(string $question, bool $secret = false): string
{
    if (!stream_isatty(STDIN)) {
        return '';
    }
    fwrite(STDOUT, $question);
    if ($secret && DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty -echo');
        $line = fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    } else {
        $line = fgets(STDIN);
    }

    return trim((string) $line);
}

function tokenhub_help(): void
{
    tokenhub_say(<<<'TXT'
TokenHub installer

  php bin/install.php env [options]
      Create .env from .env.example with random secrets. Existing files are kept unless --force.
      --output=PATH          file to write (default: .env in the application directory)
      --target=docker|native docker: service names (mysql, redis, qdrant); native: 127.0.0.1 and /var/lib/tokenhub paths
      --site=ADDRESS         domain, IP, https://IP or several comma separated (default: plain HTTP)
      --http-port=N --https-port=N   host ports (default 80/443)
      --vector-store=qdrant|mysql_fallback
      --admin-user=NAME --admin-password=PASS   initial super administrator (password generated when omitted)
      --db-host= --db-port= --db-name= --db-user= --db-password=   external MySQL
      --redis-host= --redis-port= --redis-password=                 external Redis
      --qdrant-url= --qdrant-api-key=                                external Qdrant
      --image-prefix=REGISTRY/PATH/ --image-tag=TAG                  Docker images
      --app-env=production|local   --set=KEY=VALUE (repeatable)    --force

  php bin/install.php run [options]
      Check the configuration, wait for MySQL/Redis, apply migrations, create the first super
      administrator (from --admin-user/--admin-password or TOKENHUB_ADMIN_USERNAME/PASSWORD) and record
      the installed version. Safe to run again; acts as an upgrade when the version changed.
      --wait=SECONDS   wait for MySQL/Redis (default 60)   --non-interactive   --force (ignore config failures)

  php bin/install.php admin --username=NAME [--password=PASS]
      Create or reset a super administrator (prompts for the password on a terminal).

  php bin/install.php check     run all self-checks (same as php bin/doctor.php)
TXT);
}

[$command, $options] = tokenhub_install_args($argv);

if ($command === 'help' || tokenhub_flag($options, 'help')) {
    tokenhub_help();
    exit(0);
}

if ($command === 'check') {
    $argv = [$argv[0]];
    require __DIR__ . '/doctor.php';
    exit(0);
}

if ($command === 'env') {
    $output = tokenhub_opt($options, 'output', $basePath . DIRECTORY_SEPARATOR . '.env');
    $template = tokenhub_opt($options, 'template', $basePath . DIRECTORY_SEPARATOR . '.env.example');
    if (is_file($output) && !tokenhub_flag($options, 'force')) {
        tokenhub_say('Keeping existing ' . $output . ' (use --force to regenerate; secrets would change).');
        exit(0);
    }
    if (!is_file($template)) {
        tokenhub_fail('Template not found: ' . $template);
    }

    $target = tokenhub_opt($options, 'target', is_file('/.dockerenv') ? 'docker' : 'native');
    if (!in_array($target, ['docker', 'native'], true)) {
        tokenhub_fail('--target must be docker or native');
    }
    $httpPort = (int) tokenhub_opt($options, 'http-port', '80');
    $httpsPort = (int) tokenhub_opt($options, 'https-port', '443');
    try {
        $site = SiteAddress::parse((string) tokenhub_opt($options, 'site', ''), $httpPort, $httpsPort, (string) tokenhub_opt($options, 'host', gethostname() ?: 'localhost'));
    } catch (InvalidArgumentException $exception) {
        tokenhub_fail($exception->getMessage());
    }

    $env = EnvFile::fromFile($template);
    $env->set('APP_ENV', (string) tokenhub_opt($options, 'app-env', 'production'));
    $env->set('APP_DEBUG', 'false');
    $env->set('APP_KEY', EnvFile::secret(36));
    $env->set('ADMIN_CSRF_SECRET', EnvFile::secret(32));
    $env->set('REALTIME_INTERNAL_TOKEN', EnvFile::secret(32));
    $env->set('APP_URL', $site->appUrl);
    $env->set('SESSION_SECURE', $site->https ? 'true' : 'false');
    $env->set('TOKENHUB_SITE_ADDRESS', $site->caddyAddress);
    $env->set('TOKENHUB_CADDY_TLS', $site->caddyTls);
    $env->set('TOKENHUB_CADDY_GLOBAL', $site->caddyGlobal);
    $env->set('TOKENHUB_HTTP_PORT', (string) $httpPort);
    $env->set('TOKENHUB_HTTPS_PORT', (string) $httpsPort);

    $adminUser = (string) tokenhub_opt($options, 'admin-user', 'admin');
    $adminPassword = (string) tokenhub_opt($options, 'admin-password', EnvFile::password(20));
    try {
        Installer::assertAdminCredentials($adminUser, $adminPassword);
    } catch (RuntimeException $exception) {
        tokenhub_fail($exception->getMessage());
    }
    $env->set('TOKENHUB_ADMIN_USERNAME', $adminUser);
    $env->set('TOKENHUB_ADMIN_PASSWORD', $adminPassword);

    $native = $target === 'native';
    $env->set('DB_HOST', (string) tokenhub_opt($options, 'db-host', $native ? '127.0.0.1' : 'mysql'));
    $env->set('DB_PORT', (string) tokenhub_opt($options, 'db-port', '3306'));
    $env->set('DB_DATABASE', (string) tokenhub_opt($options, 'db-name', 'tokenhub'));
    $env->set('DB_USERNAME', (string) tokenhub_opt($options, 'db-user', 'tokenhub'));
    $env->set('DB_PASSWORD', (string) tokenhub_opt($options, 'db-password', EnvFile::password(32)));
    $env->set('DB_ROOT_PASSWORD', EnvFile::password(32));
    $env->set('REDIS_HOST', (string) tokenhub_opt($options, 'redis-host', $native ? '127.0.0.1' : 'redis'));
    $env->set('REDIS_PORT', (string) tokenhub_opt($options, 'redis-port', '6379'));
    $env->set('REDIS_PASSWORD', (string) tokenhub_opt($options, 'redis-password', EnvFile::password(32)));
    $vectorStore = (string) tokenhub_opt($options, 'vector-store', 'qdrant');
    if (!in_array($vectorStore, ['qdrant', 'mysql_fallback'], true)) {
        tokenhub_fail('--vector-store must be qdrant or mysql_fallback');
    }
    $env->set('RAG_VECTOR_STORE', $vectorStore);
    $env->set('QDRANT_URL', (string) tokenhub_opt($options, 'qdrant-url', $native ? 'http://127.0.0.1:6333' : 'http://qdrant:6333'));
    $env->set('QDRANT_API_KEY', (string) tokenhub_opt($options, 'qdrant-api-key', EnvFile::password(32)));

    if ($native) {
        $env->set('CADDY_DATA_PATH', '/var/lib/caddy/.local/share/caddy');
        $env->set('LOG_PATH', '/var/log/tokenhub');
        $env->set('EXPORT_PATH', '/var/lib/tokenhub/exports');
        $env->set('MEDIA_PATH', '/var/lib/tokenhub/media');
        $env->set('CADDY_CONFIG_PATH', '/etc/caddy/Caddyfile');
        $env->set('CADDY_TLS_FRAGMENT_PATH', '/var/lib/tokenhub/caddy/tls-current.caddy');
        $env->set('CADDY_VALIDATE_COMMAND', 'caddy validate --config {config}');
        $env->set('CADDY_RELOAD_COMMAND', 'sudo -n /usr/bin/systemctl reload caddy');
    } else {
        $env->set('TOKENHUB_IMAGE_PREFIX', (string) tokenhub_opt($options, 'image-prefix', ''));
        $env->set('TOKENHUB_IMAGE_TAG', (string) tokenhub_opt($options, 'image-tag', Installer::version($basePath)));
    }

    foreach ($options['set'] ?? [] as $pair) {
        if (!str_contains($pair, '=')) {
            tokenhub_fail('--set expects KEY=VALUE');
        }
        [$key, $value] = explode('=', $pair, 2);
        $env->set(trim($key), $value);
    }

    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        tokenhub_fail('Cannot create ' . $directory);
    }
    $old = umask(0077);
    $written = file_put_contents($output, $env->toString());
    umask($old);
    if ($written === false) {
        tokenhub_fail('Cannot write ' . $output);
    }
    @chmod($output, 0640);

    tokenhub_say('Wrote ' . $output);
    tokenhub_say('  URL:            ' . $site->appUrl);
    tokenhub_say('  Administrator:  ' . $adminUser . ' / ' . $adminPassword);
    tokenhub_say('  Keep a copy of APP_KEY; provider secrets cannot be decrypted without it.');
    exit(0);
}

if (!in_array($command, ['run', 'admin', 'upgrade'], true)) {
    tokenhub_help();
    exit(64);
}

$config = new Config($basePath . DIRECTORY_SEPARATOR . 'config', $basePath . DIRECTORY_SEPARATOR . '.env');
$database = new Database($config);
$redis = new RedisClient($config);
$logger = new Logger((string) $config->get('logging.path', $basePath . '/storage/logs'));
$installer = new Installer($database, $redis, $logger, $basePath);

if ($command === 'admin') {
    $username = (string) tokenhub_opt($options, 'username', tokenhub_opt($options, 'admin-user', ''));
    if ($username === '') {
        $username = tokenhub_prompt('Administrator username: ');
    }
    $password = (string) tokenhub_opt($options, 'password', tokenhub_opt($options, 'admin-password', ''));
    if ($password === '') {
        $password = tokenhub_prompt('New password (min 12 characters): ', true);
    }
    try {
        $installer->waitForServices((int) tokenhub_opt($options, 'wait', '10'));
        $installer->upsertSuperAdmin($username, $password);
    } catch (Throwable $exception) {
        tokenhub_fail($exception->getMessage());
    }
    tokenhub_say('Super administrator ready: ' . $username);
    exit(0);
}

// run / upgrade
$mode = (string) tokenhub_opt($options, 'mode', is_file('/.dockerenv') ? 'docker' : 'native');
$settings = Doctor::settingsFrom($config);

tokenhub_say('TokenHub ' . Installer::version($basePath) . ' installer (' . $mode . ')');
$preflight = [...Doctor::platformChecks($settings), ...Doctor::configChecks($settings)];
fwrite(STDOUT, Doctor::render($preflight));
if (Doctor::hasFailures($preflight) && !tokenhub_flag($options, 'force')) {
    tokenhub_fail('Pre-flight checks failed; fix the [FAIL] items above (or pass --force).', 78);
}

try {
    $installer->waitForServices((int) tokenhub_opt($options, 'wait', '60'));
    $previous = $installer->state();
    tokenhub_say('Applying database migrations...');
    $applied = $installer->migrate();
    tokenhub_say('Applied ' . $applied . ' migration(s).');

    $createdAdmin = null;
    if (!$installer->hasSuperAdmin()) {
        $username = (string) tokenhub_opt($options, 'admin-user', (string) (env('TOKENHUB_ADMIN_USERNAME', '') ?: 'admin'));
        $password = (string) tokenhub_opt($options, 'admin-password', (string) (env('TOKENHUB_ADMIN_PASSWORD', '') ?: ''));
        if (EnvFile::isPlaceholder($password) && !tokenhub_flag($options, 'non-interactive')) {
            $password = tokenhub_prompt('Password for the first administrator "' . $username . '" (min 12 characters, empty = generate): ', true);
        }
        $generated = false;
        if (EnvFile::isPlaceholder($password)) {
            $password = EnvFile::password(20);
            $generated = true;
        }
        $installer->upsertSuperAdmin($username, $password);
        $createdAdmin = [$username, $generated ? $password : null];
    }

    $event = $previous === null ? 'install' : (($previous['version'] ?? '') === Installer::version($basePath) ? 'verify' : 'upgrade');
    if ($event !== 'verify') {
        $installer->recordState($event, $mode);
    }
} catch (Throwable $exception) {
    tokenhub_fail($exception->getMessage());
}

tokenhub_say('');
tokenhub_say($event === 'install' ? 'Installation complete.' : 'Database is up to date (' . $event . ').');
if ($createdAdmin !== null) {
    tokenhub_say('Super administrator created: ' . $createdAdmin[0]);
    if ($createdAdmin[1] !== null) {
        tokenhub_say('Generated password (shown once): ' . $createdAdmin[1]);
    }
    tokenhub_say('Change the password after the first login and remove TOKENHUB_ADMIN_PASSWORD from the environment file.');
}
tokenhub_say('Admin console: ' . rtrim((string) $config->get('app.url', ''), '/') . '/admin/');
exit(0);
