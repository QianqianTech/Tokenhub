<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\IdempotencyService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\RequestContext;
use TokenHub\Support\Response;

$service = new IdempotencyService();
$request = new Request('POST', '/v1/sync/devices', [], [], ['device_id' => 'device-a'], '{"device_id":"device-a"}', new RequestContext('req_idempotency', 'trc_idempotency'));

assert_same(true, $service->isEligible($request), 'External non-gateway mutation routes should support idempotency.');
assert_same(false, $service->isEligible(new Request('POST', '/v1/chat/completions', [], [], [], '{}', new RequestContext('req_gateway', 'trc_gateway'))), 'Model gateway requests must not cache stream-capable responses.');
assert_same(true, $service->isEligible(new Request('PATCH', '/v1/management/users/1', [], [], ['status' => 'disabled'], '{"status":"disabled"}', new RequestContext('req_management_patch', 'trc_management_patch'))), 'Management PATCH mutations must support idempotency.');
assert_same($service->requestHash($request), $service->requestHash($request), 'Idempotency request fingerprints must be deterministic.');
assert_true($service->requestHash($request) !== $service->requestHash(new Request('POST', '/v1/sync/devices', [], [], ['device_id' => 'device-b'], '{"device_id":"device-b"}', new RequestContext('req_other', 'trc_other'))), 'Distinct mutation bodies must not share an idempotency fingerprint.');

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$departmentId = $userId = $apiKeyId = 0;
try {
    $db->execute('INSERT INTO departments (name, path, level, status) VALUES (?, ?, 0, "active")', ['Idem Dept ' . $suffix, '/idem-' . $suffix . '/']);
    $departmentId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO users (department_id, username, password_hash, role, status) VALUES (?, ?, "hash", "user", "active")', [$departmentId, 'idem_' . $suffix]);
    $userId = (int) $db->pdo()->lastInsertId();
    $db->execute('INSERT INTO api_keys (user_id, department_id, name, key_prefix, key_suffix, key_hash, status) VALUES (?, ?, ?, "idem", "test", ?, "active")', [$userId, $departmentId, 'Idem ' . $suffix, hash('sha256', $suffix)]);
    $apiKeyId = (int) $db->pdo()->lastInsertId();
    $request = new Request('POST', '/v1/sync/devices', ['HTTP_IDEMPOTENCY_KEY' => 'idem_' . $suffix], [], ['device_id' => 'd'], '{"device_id":"d"}', new RequestContext('req_' . $suffix, 'trc_' . $suffix));
    $calls = 0;
    $first = $service->handle($request, ['id' => $apiKeyId], function () use (&$calls): Response { $calls++; return Response::ok(['call' => $calls], 201); });
    $second = $service->handle($request, ['id' => $apiKeyId], function () use (&$calls): Response { $calls++; return Response::ok(['call' => $calls], 201); });
    assert_same(1, $calls, 'A repeated idempotency key must not execute the mutation twice.');
    assert_same($first->body, $second->body, 'A repeated idempotency key must replay the original response.');
} finally {
    if ($apiKeyId > 0) $db->execute('DELETE FROM api_keys WHERE id = ?', [$apiKeyId]);
    if ($userId > 0) $db->execute('DELETE FROM users WHERE id = ?', [$userId]);
    if ($departmentId > 0) $db->execute('DELETE FROM departments WHERE id = ?', [$departmentId]);
}
