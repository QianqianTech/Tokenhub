<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\AppRealtimeService;

// Live-update stream loop without a database: short-lived streams, keep-alive
// pings, client disconnects and sessions revoked while streaming.
$root = dirname(__DIR__, 2);
$user = ['id' => 0, 'role' => 'user']; // no capabilities: only the stream mechanics run

$run = static function (callable $alive, callable $sessionValid) use ($user): array {
    $now = 1_000_000;
    $events = [];
    $service = new AppRealtimeService(static function () use (&$now): int {
        return $now;
    }, static function (int $seconds) use (&$now): void {
        $now += $seconds;
    });
    $result = $service->run($user, static function (string $event, array $data) use (&$events): void {
        $events[] = $event;
    }, $alive, $sessionValid);

    return [$result, $events];
};

[$result, $events] = $run(static fn (): bool => true, static fn (): bool => true);
assert_same('rotate', $result, 'A stream ends on its own after APP_STREAM_SECONDS so it never pins a PHP worker for long.');
assert_same('ready', $events[0], 'The stream starts with a ready event.');
assert_same('end', $events[count($events) - 1], 'The stream tells the APP to reconnect.');
assert_true(count(array_keys($events, 'ping', true)) >= 3, 'Keep-alive pings flow through proxies.');

$calls = 0;
[$result] = $run(static function () use (&$calls): bool {
    return ++$calls < 5;
}, static fn (): bool => true);
assert_same('disconnected', $result, 'The loop stops as soon as the client goes away.');

[$result, $events] = $run(static fn (): bool => true, static fn (): bool => false);
assert_same('signed_out', $result, 'A revoked device or expired token ends the stream.');
assert_same('end', $events[count($events) - 1], 'The APP is told it was signed out.');

// Wiring.
$controller = (string) file_get_contents($root . '/app/Http/Controllers/AppAuthController.php');
assert_true(str_contains($controller, "'APP_STREAM_BUSY'") && str_contains($controller, 'releaseSlot'), 'Streams are capped and always release their slot.');
$kernel = (string) file_get_contents($root . '/app/Bootstrap/Kernel.php');
assert_true(str_contains($kernel, "\$router->get('/admin-api/app-auth/stream', [\$appAuth, 'stream'], [new AdminAuthMiddleware()]);"), 'The stream needs an APP session.');
$notifier = (string) file_get_contents($root . '/app/Services/AlertNotifier.php');
assert_true(str_contains($notifier, "'alerts.notify'"), 'Queued APP pushes are delivered right away, not at the next alert evaluation.');
$client = (string) file_get_contents($root . '/public/app/assets/js/realtime.js');
assert_true(str_contains($client, 'visibilityState === "hidden"') && str_contains($client, 'status === 503'), 'The APP closes the stream in the background and falls back to polling when busy.');
$sw = (string) file_get_contents($root . '/public/app/sw.js');
assert_true(str_contains($sw, 'url.pathname.startsWith("/admin-api/")'), 'The live stream never goes through the service worker cache.');
