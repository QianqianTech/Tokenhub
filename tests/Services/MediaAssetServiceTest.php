<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\MediaAssetService;

$service = new MediaAssetService();

$asset = ['owner_user_id' => 10, 'department_id' => 20, 'expires_at' => null];
$key = ['user_id' => 10, 'department_id' => 99];
assert_true($service->canAccess($asset, $key), 'Owner user should access own media asset.');

$departmentKey = ['user_id' => 99, 'department_id' => 20];
assert_true($service->canAccess($asset, $departmentKey), 'Same department key should access department media asset.');

$keyScoped = ['owner_user_id' => 11, 'department_id' => 21, 'api_key_id' => 77, 'expires_at' => null];
$matchingKey = ['id' => 77, 'user_id' => 99, 'department_id' => 99];
assert_true($service->canAccess($keyScoped, $matchingKey), 'Matching API key should access key-scoped media asset.');

$otherKey = ['user_id' => 99, 'department_id' => 21];
assert_true(!$service->canAccess($asset, $otherKey), 'Unrelated key should not access media asset.');

$expired = ['owner_user_id' => 10, 'department_id' => 20, 'expires_at' => '2000-01-01 00:00:00'];
assert_true(!$service->canAccess($expired, $key), 'Expired media asset should not be accessible.');

$screenGlobal = ['scope_type' => 'global', 'scope_ids' => null];
assert_true($service->canAccessScreen($asset, $screenGlobal), 'Global screen share should access non-expired media asset.');

$screenDepartment = ['scope_type' => 'department', 'scope_ids' => '[20]'];
assert_true($service->canAccessScreen($asset, $screenDepartment), 'Department screen share should access matching department media asset.');

$screenOtherUser = ['scope_type' => 'user', 'scope_ids' => '[99]'];
assert_true(!$service->canAccessScreen($asset, $screenOtherUser), 'Screen share should reject media outside its user scope.');

$screenToken = ['screen' => true, 'scope_type' => 'department', 'scope_ids' => '[20]'];
assert_true($service->canAccessDownloadPayload($asset, $screenToken), 'Screen download token should access matching media asset scope.');

$badScreenToken = ['screen' => true, 'scope_type' => 'department', 'scope_ids' => '[99]'];
assert_true(!$service->canAccessDownloadPayload($asset, $badScreenToken), 'Screen download token should reject media outside its scope.');

$apiKeyToken = ['id' => 77, 'user_id' => 99, 'department_id' => 99];
assert_true($service->canAccessDownloadPayload($keyScoped, $apiKeyToken), 'API key download token should still use API key scope.');

$public = $service->publicAsset([
    'asset_id' => 'asset_test',
    'media_type' => 'audio',
    'storage_path' => 'https://provider.example/private/audio.mp3',
    'mime_type' => 'audio/mpeg',
]);
assert_true(!array_key_exists('storage_path', $public), 'Public media asset payload should not expose storage_path.');
assert_same('asset_test', $public['asset_id'], 'Public media asset payload should keep asset_id.');
