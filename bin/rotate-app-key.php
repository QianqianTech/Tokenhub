<?php

declare(strict_types=1);

/**
 * Re-encrypts stored secrets with the current APP_KEY / APP_KEY_ID.
 *
 * Rotation procedure (resumable; safe to re-run after an interruption):
 *  1. Set APP_HASH_KEY to the CURRENT APP_KEY value (keeps API key hashes and
 *     admin sessions valid), then deploy.
 *  2. Move the current key to APP_PREVIOUS_KEYS="k1:<old key>", set APP_KEY to
 *     the new key and APP_KEY_ID to a new id (e.g. k2), deploy. Old ciphertext
 *     still decrypts through APP_PREVIOUS_KEYS.
 *  3. Run:  php bin/rotate-app-key.php --dry-run   (counts only)
 *           php bin/rotate-app-key.php            (re-encrypts in batches)
 *  4. When the report shows 0 remaining, remove the old key from
 *     APP_PREVIOUS_KEYS. Rollback before step 4 = restore the previous env.
 *
 * Rows that cannot be decrypted with any configured key are reported, never
 * overwritten.
 */

require __DIR__ . '/_bootstrap.php';

use TokenHub\Services\ProviderHeaderVault;
use TokenHub\Support\App;
use TokenHub\Support\Crypto;

$dryRun = cli_option($argv, 'dry-run') !== null;
$batch = max(1, (int) cli_option($argv, 'batch', '200'));
$report = [];

$targets = [
    ['table' => 'providers', 'column' => 'encrypted_secret'],
    ['table' => 'external_webhooks', 'column' => 'secret'],
    ['table' => 'external_webhooks', 'column' => 'previous_secret'],
    ['table' => 'usage_contents', 'column' => 'encrypted_input'],
    ['table' => 'usage_contents', 'column' => 'encrypted_output'],
];

foreach ($targets as $target) {
    $table = $target['table'];
    $column = $target['column'];
    $stats = ['scanned' => 0, 'reencrypted' => 0, 'undecryptable' => 0, 'current' => 0];
    $lastId = 0;
    try {
        do {
            $rows = App::db()->select("SELECT id, {$column} AS value FROM {$table} WHERE id > ? AND {$column} IS NOT NULL AND {$column} <> '' ORDER BY id ASC LIMIT {$batch}", [$lastId]);
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $stats['scanned']++;
                $value = (string) $row['value'];
                if (!Crypto::needsReencryption($value)) {
                    $stats['current']++;
                    continue;
                }
                $plain = Crypto::decrypt($value);
                if ($plain === '') {
                    $stats['undecryptable']++;
                    continue;
                }
                if (!$dryRun) {
                    App::db()->execute("UPDATE {$table} SET {$column} = ? WHERE id = ? AND {$column} = ?", [Crypto::encrypt($plain), $lastId, $value]);
                }
                $stats['reencrypted']++;
            }
        } while (count($rows) === $batch);
    } catch (Throwable $throwable) {
        $stats['error'] = $throwable->getMessage();
    }
    $report["{$table}.{$column}"] = $stats;
}

// Provider headers: encrypt legacy plaintext credentials and rotate "enc:" values.
$vault = new ProviderHeaderVault();
$headerStats = ['scanned' => 0, 'updated' => 0];
foreach (App::db()->select('SELECT id, default_headers FROM providers WHERE default_headers IS NOT NULL') as $row) {
    $headerStats['scanned']++;
    $revealed = $vault->revealForUse((string) $row['default_headers']);
    $protected = $vault->protectForStorage($revealed, null);
    $encoded = json_encode($protected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded !== $row['default_headers']) {
        if (!$dryRun) {
            App::db()->execute('UPDATE providers SET default_headers = ? WHERE id = ?', [$encoded, (int) $row['id']]);
        }
        $headerStats['updated']++;
    }
}
$report['providers.default_headers'] = $headerStats;

echo json_encode(['dry_run' => $dryRun, 'key_id' => Crypto::keyIdOf(Crypto::encrypt('probe')), 'report' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
