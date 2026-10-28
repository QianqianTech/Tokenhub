<?php

declare(strict_types=1);

// Kept for older runbooks: php scripts/create-admin.php <username> <password>
// Prefer: php bin/install.php admin --username=<username>
$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? null;
if ($password === null || strlen($password) < 12) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php <username> <password-at-least-12-chars>\n");
    exit(1);
}

$argv = [__DIR__ . '/../bin/install.php', 'admin', '--username=' . $username, '--password=' . $password];
require __DIR__ . '/../bin/install.php';
