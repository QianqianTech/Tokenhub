<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Console\Install\Doctor;
use TokenHub\Console\Install\EnvFile;
use TokenHub\Console\Install\Installer;
use TokenHub\Console\Install\SiteAddress;
use TokenHub\Console\MigrationRunner;

$root = dirname(__DIR__, 2);

// ---------------------------------------------------------------- EnvFile
$env = EnvFile::fromString("# comment\nAPP_KEY=change-me\nDB_HOST=mysql\n\nEMPTY=\n");
assert_same('change-me', $env->get('APP_KEY'), 'EnvFile should read values.');
$env->set('APP_KEY', 'abc')->set('NEW_KEY', 'tls internal');
assert_true(str_contains($env->toString(), "# comment\nAPP_KEY=abc\nDB_HOST=mysql"), 'EnvFile should replace in place and keep comments.');
assert_true(str_contains($env->toString(), 'NEW_KEY="tls internal"'), 'EnvFile should append and quote values with spaces.');
assert_same(['APP_KEY' => 'abc', 'DB_HOST' => 'mysql', 'EMPTY' => '', 'NEW_KEY' => 'tls internal'], $env->values(), 'EnvFile should round-trip values.');
assert_true(EnvFile::isPlaceholder('change-me') && EnvFile::isPlaceholder('') && EnvFile::isPlaceholder('tokenhub_secret'), 'Template values are placeholders.');
assert_true(!EnvFile::isPlaceholder(EnvFile::password(24)), 'Generated passwords are not placeholders.');
assert_same(1, preg_match('/^[A-Za-z0-9]{40}$/', EnvFile::password(40)), 'Passwords are alphanumeric (safe in shells and URLs).');
assert_true(strlen(EnvFile::secret(36)) >= 48, 'Secrets carry at least 36 random bytes.');

// ---------------------------------------------------------------- .env.example covers every configuration key
$template = EnvFile::fromFile($root . '/.env.example');
$documented = (string) file_get_contents($root . '/.env.example');
preg_match_all("/env\\(['\"]([A-Z0-9_]+)['\"]/", implode("\n", array_map('file_get_contents', glob($root . '/config/*.php') ?: [])), $matches);
foreach (array_unique($matches[1]) as $key) {
    assert_true(preg_match('/^(# )?' . preg_quote($key, '/') . '=|' . preg_quote($key, '/') . ' is/m', $documented) === 1, '.env.example should document ' . $key . '.');
}
foreach (['APP_KEY', 'ADMIN_CSRF_SECRET', 'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'REDIS_PASSWORD', 'TOKENHUB_ADMIN_PASSWORD'] as $secret) {
    assert_true(EnvFile::isPlaceholder((string) $template->get($secret)), '.env.example must not ship a real value for ' . $secret . '.');
}

// ---------------------------------------------------------------- SiteAddress (domain / IP / HTTP)
$plain = SiteAddress::parse('', 8080, 8443, '10.1.1.1');
assert_same([':80', '', 'http://10.1.1.1:8080', false], [$plain->caddyAddress, $plain->caddyTls, $plain->appUrl, $plain->https], 'Empty site means plain HTTP.');
$domain = SiteAddress::parse('tokenhub.example.com');
assert_same(['tokenhub.example.com', '', 'https://tokenhub.example.com', true], [$domain->caddyAddress, $domain->caddyTls, $domain->appUrl, $domain->https], 'A domain gets automatic HTTPS.');
$ipHttps = SiteAddress::parse('https://10.0.0.8');
assert_same(['https://10.0.0.8', 'tls internal', 'default_sni 10.0.0.8', 'https://10.0.0.8'], [$ipHttps->caddyAddress, $ipHttps->caddyTls, $ipHttps->caddyGlobal, $ipHttps->appUrl], 'https://IP uses the internal CA and an explicit SNI.');
$ipHttp = SiteAddress::parse('10.0.0.8', 8080);
assert_same([':80', 'http://10.0.0.8:8080', false], [$ipHttp->caddyAddress, $ipHttp->appUrl, $ipHttp->https], 'A bare IP is served over plain HTTP on every address.');
$both = SiteAddress::parse('tokenhub.example.com, https://10.0.0.8');
assert_same(['tokenhub.example.com, https://10.0.0.8', '', 'https://tokenhub.example.com'], [$both->caddyAddress, $both->caddyTls, $both->appUrl], 'Domain and IP can be combined.');
$rejected = false;
try {
    SiteAddress::parse('bad_host!');
} catch (InvalidArgumentException) {
    $rejected = true;
}
assert_true($rejected, 'Invalid site addresses are rejected.');

// install.sh mirrors SiteAddress
$installSh = (string) file_get_contents($root . '/deploy/docker/install.sh');
foreach (['tls internal', 'default_sni', 'SESSION_SECURE', 'TOKENHUB_SITE_ADDRESS'] as $needle) {
    assert_true(str_contains($installSh, $needle), 'deploy/docker/install.sh should implement ' . $needle . '.');
}

// ---------------------------------------------------------------- Doctor configuration rules
$good = [
    'env' => 'production', 'debug' => false, 'key' => str_repeat('k', 48), 'url' => 'https://tokenhub.example.com',
    'csrf_secret' => str_repeat('c', 32), 'session_secure' => true, 'db_password' => 'Xy7pQ2rT9wZk', 'redis_password' => 'Rr5tY8uI0oPl',
    'vector_store' => 'qdrant', 'qdrant_url' => 'http://qdrant:6333', 'qdrant_api_key' => '', 'paths' => [],
];
assert_true(!Doctor::hasFailures(Doctor::configChecks($good)), 'A complete production configuration passes.');
assert_true(Doctor::hasFailures(Doctor::configChecks([...$good, 'key' => 'change-me'])), 'Production refuses the template APP_KEY.');
assert_true(Doctor::hasFailures(Doctor::configChecks([...$good, 'db_password' => 'change-me'])), 'Production refuses template passwords.');
assert_true(Doctor::hasFailures(Doctor::configChecks([...$good, 'url' => 'http://10.0.0.8'])), 'Secure cookies over plain HTTP are rejected (admin login would break).');
assert_true(!Doctor::hasFailures(Doctor::configChecks([...$good, 'url' => 'http://10.0.0.8', 'session_secure' => false])), 'Plain HTTP intranet access is allowed with SESSION_SECURE=false.');
assert_true(!Doctor::hasFailures(Doctor::configChecks([...$good, 'env' => 'local', 'key' => ''])), 'Development configurations only warn.');
assert_true(Doctor::hasFailures(Doctor::configChecks([...$good, 'debug' => true])), 'APP_DEBUG=true is refused in production.');
assert_true(Doctor::hasFailures(Doctor::platformChecks([], '8.1.30', static fn (): bool => true)), 'PHP 8.1 is refused.');
assert_true(Doctor::hasFailures(Doctor::platformChecks([], '8.6.0', static fn (): bool => true)), 'PHP 8.6 is refused (PhpSpreadsheet supports < 8.6).');
assert_true(!Doctor::hasFailures(Doctor::platformChecks([], '8.2.0', static fn (): bool => true)), 'PHP 8.2 is accepted.');
assert_true(Doctor::hasFailures(Doctor::platformChecks([], '8.4.1', static fn (string $ext): bool => $ext !== 'gd')), 'Missing extensions are reported.');
assert_same('fail', Doctor::mysqlVersionResult('5.7.44')['level'], 'MySQL 5.7 is refused.');
assert_same('warn', Doctor::mysqlVersionResult('8.0.36')['level'], 'MySQL 8.0 works with a recommendation.');
assert_same('ok', Doctor::mysqlVersionResult('8.4.3')['level'], 'MySQL 8.4 LTS is recommended.');
assert_same('ok', Doctor::mysqlVersionResult('9.7.0')['level'], 'MySQL 9.x is accepted.');
assert_same('warn', Doctor::mysqlVersionResult('10.11.6-MariaDB')['level'], 'MariaDB is flagged as untested.');

// ---------------------------------------------------------------- admin credentials
foreach ([['admin', 'short'], ['admin', 'change-me'], ['a', 'long-enough-password'], ['bad name', 'long-enough-password']] as [$user, $password]) {
    $refused = false;
    try {
        Installer::assertAdminCredentials($user, $password);
    } catch (RuntimeException) {
        $refused = true;
    }
    assert_true($refused, 'Weak administrator credentials are refused: ' . $user);
}
Installer::assertAdminCredentials('admin', 'a-long-enough-password');
assert_same(trim((string) file_get_contents($root . '/VERSION')), Installer::version($root), 'The installed version comes from VERSION.');

// ---------------------------------------------------------------- migrations: one runner, all files, stable order
$files = MigrationRunner::discover($root . '/database/migrations');
$names = array_map('basename', $files);
assert_same(count(glob($root . '/database/migrations/*.sql') ?: []), count($files), 'The runner discovers every migration file.');
assert_same('0001_create_tokenhub_schema.sql', $names[0], 'The full schema runs first on a fresh database.');
$sorted = $names;
usort($sorted, 'strcmp');
assert_same($sorted, $names, 'Migrations run in byte-wise file-name order on every platform.');
assert_same(['2026_07_09_000002_rag_knowledge_base.sql'], MigrationRunner::pendingFrom(
    array_filter($files, static fn (string $f): bool => str_contains($f, '2026_07_09_00000')),
    array_values(array_filter($names, static fn (string $n): bool => $n !== '2026_07_09_000002_rag_knowledge_base.sql'))
), 'pendingFrom returns migrations that were not recorded.');
assert_same(['SELECT 1', "SELECT 'a;b'", 'SELECT 3'], MigrationRunner::statements("SELECT 1; -- c;x\nSELECT 'a;b';\n# z;\nSELECT 3"), 'Statement splitting respects comments and quoted semicolons.');
foreach ($files as $file) {
    assert_true(MigrationRunner::statements((string) file_get_contents($file)) !== [], basename($file) . ' should contain statements.');
}
$runnerSource = (string) file_get_contents($root . '/app/Console/MigrationRunner.php');
assert_true(str_contains($runnerSource, 'GET_LOCK'), 'Concurrent migration runs are serialised with a MySQL lock.');
assert_true(!is_file($root . '/app/Support/MigrationRunner.php') && !is_file($root . '/app/Services/MigrationRunner.php'), 'Only one migration runner remains.');
assert_true(str_contains((string) file_get_contents($root . '/scripts/migrate.php'), "bin/migrate.php"), 'scripts/migrate.php delegates to bin/migrate.php.');
assert_true(str_contains((string) file_get_contents($root . '/scripts/worker.php'), "bin/queue-worker.php"), 'scripts/worker.php delegates to bin/queue-worker.php.');
