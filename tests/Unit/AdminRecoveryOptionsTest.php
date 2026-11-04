<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

/*
 * UX-113: the login page hides "忘记密码" unless SMTP can send reset mail.
 * GET /admin-api/auth/options is public, so it must reveal only one boolean.
 */

use TokenHub\Http\Controllers\AdminRecoveryController;
use TokenHub\Services\SmtpSettingsService;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Database;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite unavailable\n";
    return;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$appDatabase = new ReflectionProperty(App::class, 'database');
$originalDatabase = $appDatabase->getValue();
$database = new Database(App::config());
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
$appDatabase->setValue(null, $database);

try {
    $service = new SmtpSettingsService();
    // Missing table (fresh install before migrations): no recovery, no error.
    assert_true($service->recoveryEnabled() === false, 'Without the SMTP settings table recovery is off.');

    $pdo->exec('CREATE TABLE admin_smtp_settings (id INTEGER PRIMARY KEY, settings_value TEXT)');
    assert_true($service->recoveryEnabled() === false, 'Unconfigured SMTP keeps recovery off.');

    $store = static function (array $values) use ($pdo): void {
        $pdo->exec('DELETE FROM admin_smtp_settings');
        $statement = $pdo->prepare('INSERT INTO admin_smtp_settings (id, settings_value) VALUES (1, ?)');
        $statement->execute([json_encode($values, JSON_UNESCAPED_SLASHES)]);
    };
    $complete = ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'auth_enabled' => true, 'username' => 'mailer', 'from_address' => 'noreply@example.com', 'encrypted_password' => Crypto::encrypt('secret-password')];

    $store(['enabled' => false] + $complete);
    assert_true($service->recoveryEnabled() === false, 'Disabled SMTP keeps recovery off.');

    $store(['encrypted_password' => ''] + $complete);
    assert_true($service->recoveryEnabled() === false, 'SMTP auth without a stored password cannot send mail.');

    $store(['host' => ''] + $complete);
    assert_true($service->recoveryEnabled() === false, 'SMTP without a host cannot send mail.');

    $store($complete);
    assert_true($service->recoveryEnabled() === true, 'Complete SMTP settings enable recovery.');

    $store(['auth_enabled' => false, 'username' => '', 'encrypted_password' => ''] + $complete);
    assert_true($service->recoveryEnabled() === true, 'An unauthenticated relay is enough.');

    // The public endpoint returns exactly one boolean and is not cached.
    $store($complete);
    $request = new Request('GET', '/admin-api/auth/options', ['REMOTE_ADDR' => '203.0.113.9'], [], [], '', new RequestContext('req_auth_options', 'trc_auth_options'));
    $response = (new AdminRecoveryController())->options($request);
    $payload = json_decode($response->body, true);
    assert_same(['recovery_enabled' => true], $payload['data'] ?? null, 'Options expose only recovery_enabled.');
    assert_true(!str_contains($response->body, 'smtp.example.com') && !str_contains($response->body, 'mailer'), 'Options never leak SMTP settings.');
    assert_same('no-store', $response->headers['Cache-Control'] ?? null, 'Options must not be cached.');
} finally {
    $appDatabase->setValue(null, $originalDatabase);
}

echo "AdminRecoveryOptionsTest OK\n";
