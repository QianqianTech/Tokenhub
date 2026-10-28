<?php

declare(strict_types=1);

/*
 * Qdrant snapshot helper used by the backup and restore scripts.
 *
 *   php bin/qdrant-snapshot.php create [--index=FILE]
 *       create one snapshot per collection and write a JSON index
 *       (collection => snapshot file name). Files stay in Qdrant's snapshots directory
 *       (/qdrant/snapshots in Docker, /var/lib/qdrant/snapshots for source installs).
 *   php bin/qdrant-snapshot.php delete --index=FILE
 *       delete the snapshots named in the index from Qdrant once the backup archived them.
 *   php bin/qdrant-snapshot.php restore --index=FILE [--snapshots-dir=/qdrant/snapshots]
 *       recover every collection from the snapshot files named in the index
 *       (the directory is the path as seen by the Qdrant server).
 */

use TokenHub\Support\Config;

$basePath = dirname(__DIR__);
require_once $basePath . '/app/Bootstrap/autoload.php';

$config = new Config($basePath . '/config', $basePath . '/.env');
$url = rtrim((string) $config->get('rag.qdrant.url', 'http://qdrant:6333'), '/');
$apiKey = (string) $config->get('rag.qdrant.api_key', '');

$command = $argv[1] ?? 'help';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    }
}

/**
 * @return array{0: int, 1: array<string, mixed>|null}
 */
$request = static function (string $method, string $path, ?array $body = null, int $timeout = 600) use ($url, $apiKey): array {
    $headers = "Content-Type: application/json\r\nAccept: application/json\r\n";
    if ($apiKey !== '') {
        $headers .= 'api-key: ' . $apiKey . "\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $body === null ? '' : json_encode($body),
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url . $path, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        }
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return [$status, is_array($decoded) ? $decoded : null];
};

if ($command === 'create') {
    [$status, $body] = $request('GET', '/collections', null, 30);
    if ($status !== 200) {
        fwrite(STDERR, "Qdrant not reachable at {$url} (HTTP {$status}).\n");
        exit(1);
    }
    $index = [];
    foreach ((array) ($body['result']['collections'] ?? []) as $collection) {
        $name = (string) ($collection['name'] ?? '');
        if ($name === '') {
            continue;
        }
        [$status, $snapshot] = $request('POST', '/collections/' . rawurlencode($name) . '/snapshots?wait=true');
        $file = (string) ($snapshot['result']['name'] ?? '');
        if ($status !== 200 || $file === '') {
            fwrite(STDERR, "Snapshot of {$name} failed (HTTP {$status}).\n");
            exit(1);
        }
        $index[] = ['collection' => $name, 'file' => $file];
        fwrite(STDOUT, "snapshot {$name}: {$file}\n");
    }
    $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (isset($options['index'])) {
        file_put_contents($options['index'], $json);
    } else {
        fwrite(STDOUT, $json);
    }
    fwrite(STDOUT, count($index) . " collection snapshot(s) created.\n");
    exit(0);
}

if ($command === 'delete') {
    $indexFile = $options['index'] ?? '';
    $index = is_file($indexFile) ? json_decode((string) file_get_contents($indexFile), true) : null;
    if (!is_array($index)) {
        fwrite(STDERR, "--index=FILE is required.\n");
        exit(64);
    }
    foreach ($index as $entry) {
        $name = (string) ($entry['collection'] ?? '');
        $file = (string) ($entry['file'] ?? '');
        if ($name !== '' && $file !== '') {
            $request('DELETE', '/collections/' . rawurlencode($name) . '/snapshots/' . rawurlencode($file) . '?wait=true', null, 60);
        }
    }
    fwrite(STDOUT, count($index) . " snapshot(s) deleted from Qdrant (already archived).\n");
    exit(0);
}

if ($command === 'restore') {
    $indexFile = $options['index'] ?? '';
    $index = is_file($indexFile) ? json_decode((string) file_get_contents($indexFile), true) : null;
    if (!is_array($index)) {
        fwrite(STDERR, "--index=FILE with the JSON written by `create` is required.\n");
        exit(64);
    }
    $directory = rtrim($options['snapshots-dir'] ?? '/qdrant/snapshots', '/');
    foreach ($index as $entry) {
        $name = (string) ($entry['collection'] ?? '');
        $file = (string) ($entry['file'] ?? '');
        if ($name === '' || $file === '') {
            continue;
        }
        [$status] = $request('PUT', '/collections/' . rawurlencode($name) . '/snapshots/recover?wait=true', [
            'location' => 'file://' . $directory . '/' . $name . '/' . $file,
            'priority' => 'snapshot',
        ]);
        if ($status !== 200) {
            fwrite(STDERR, "Recovering {$name} from {$file} failed (HTTP {$status}).\n");
            exit(1);
        }
        fwrite(STDOUT, "restored {$name}\n");
    }
    fwrite(STDOUT, count($index) . " collection(s) restored.\n");
    exit(0);
}

fwrite(STDOUT, "usage: php bin/qdrant-snapshot.php create [--index=FILE] | restore --index=FILE [--snapshots-dir=DIR]\n");
exit($command === 'help' ? 0 : 64);
