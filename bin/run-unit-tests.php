<?php

declare(strict_types=1);

/**
 * Behaviour tests that need no MySQL (tests/Unit). Redis-backed tests skip
 * themselves when Redis is unreachable; the HTTP behaviour test starts a fake
 * upstream with `php -S`.
 *
 *   php bin/run-unit-tests.php            run all
 *   php bin/run-unit-tests.php Sse        run files whose name contains "Sse"
 *
 * Each file runs in its own process so global state cannot leak between
 * tests. Exit code is non-zero when any file fails. The summary line lists
 * passed / failed / skipped so a skipped dependency is never reported as a pass.
 */

$root = dirname(__DIR__);
$filter = $argv[1] ?? '';
$files = glob($root . '/tests/Unit/*Test.php') ?: [];
sort($files);
$passed = $failed = $skipped = 0;
$started = microtime(true);

foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }
    $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $name = 'tests/Unit/' . basename($file);
    if ($code === 0 && str_starts_with(trim((string) $stdout), 'SKIP')) {
        $skipped++;
        echo "SKIP {$name} (" . trim((string) $stdout) . ")\n";
    } elseif ($code === 0) {
        $passed++;
        echo "PASS {$name}\n";
    } else {
        $failed++;
        echo "FAIL {$name}\n";
        $message = trim((string) $stderr) !== '' ? $stderr : $stdout;
        echo '     ' . str_replace("\n", "\n     ", trim(implode("\n", array_slice(explode("\n", (string) $message), 0, 6)))) . "\n";
    }
}

printf("\n%d passed, %d failed, %d skipped in %.1fs (commit %s, php %s)\n", $passed, $failed, $skipped, microtime(true) - $started, trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null')) ?: 'n/a', PHP_VERSION);
exit($failed > 0 ? 1 : 0);
