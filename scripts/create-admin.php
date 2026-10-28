<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$autoload = $basePath . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require $basePath . '/app/Support/helpers.php';
    spl_autoload_register(function (string $class) use ($basePath): void {
        $prefix = 'TokenHub\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $basePath . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

use TokenHub\Support\App;

App::bootstrap($basePath);
$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? null;
if ($password === null || strlen($password) < 12) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php <username> <password-at-least-12-chars>\n");
    exit(1);
}

App::db()->execute(
    'INSERT INTO users (department_id, username, password_hash, role, status, managed_department_ids) VALUES (1, ?, ?, "super_admin", "active", JSON_ARRAY(1)) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = "super_admin", status = "active"',
    [$username, password_hash($password, PASSWORD_DEFAULT)]
);

echo "Super admin ready: {$username}\n";
