<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js');
$apiSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/api.js');
$tableSource = file_get_contents(__DIR__ . '/../../public/admin/assets/js/table.js');
$cssSource = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css');

assert_true(is_string($resources), 'Admin resources source should be readable.');
assert_true(is_string($apiSource), 'Admin API source should be readable.');
assert_true(is_string($tableSource), 'Admin table source should be readable.');
assert_true(is_string($cssSource), 'Admin CSS source should be readable.');

assert_true(str_contains($resources, '"admin-chat-sessions"'), 'Operations navigation should include the admin chat session page.');
assert_true(str_contains($resources, '"admin-chat-sessions": { label: "会话测试"'), 'Admin chat session resource should have a dedicated label.');
assert_true((bool) preg_match('/key: "operations"[^\\]]*items: \\[[^\\]]*"admin-chat-sessions"/', $resources), 'Admin chat session page should be under the operations menu.');
assert_true((bool) preg_match('/admin: \\[[^\\]]*"admin-chat-sessions"/', $resources), 'Admin role should be able to open the chat session page.');
assert_true((bool) preg_match('/super_admin: \\[[^\\]]*"admin-chat-sessions"/', $resources), 'Super admin role should be able to open the chat session page.');
assert_true(!((bool) preg_match('/user: \\[[^\\]]*"admin-chat-sessions"/', $resources)), 'Ordinary user role should not receive the admin chat session page.');

assert_true(str_contains($tableSource, 'renderAdminChatSessionPage'), 'Table renderer should expose a custom chat session page.');
assert_true(str_contains($tableSource, '/admin-api/chat-session/models'), 'Chat session page should load switchable models from the admin API.');
assert_true(str_contains($tableSource, '/admin-api/chat-session/stream'), 'Chat session page should stream messages through the admin API.');
assert_true(str_contains($apiSource, 'export async function streamApi'), 'Admin API helper should support readable stream responses.');
assert_true(str_contains($tableSource, 'name: "model"'), 'Chat session form should include a model selector.');
assert_true(!str_contains($tableSource, 'name: "system"'), 'Chat session form should not expose a system prompt field.');
assert_true(!str_contains($tableSource, 'name: "temperature"'), 'Chat session form should not expose temperature control.');
assert_true(!str_contains($tableSource, 'name: "max_tokens"'), 'Chat session form should not expose max token control.');
assert_true(str_contains($tableSource, 'state.stickToBottom'), 'Chat session page should auto-scroll unless the user scrolls away from the bottom.');
assert_true(str_contains($tableSource, 'admin-chat-message ${message.role}'), 'Chat session page should render role-specific message classes.');
assert_true(str_contains($tableSource, 'reasoningSeen'), 'Chat session page should detect reasoning-only model responses.');
assert_true(!str_contains($tableSource, 'state.messages = state.messages.filter((message) => message !== assistant);'), 'Chat session request failures should remain visible as assistant messages.');
assert_true(str_contains($tableSource, 'admin-chat-input-shell'), 'Chat session composer should render a single message input shell.');
assert_true(str_contains($tableSource, 'admin-chat-input-footer'), 'Chat session model selector should sit in the message input footer.');
assert_true(str_contains($cssSource, '.admin-chat-transcript'), 'Chat session CSS should define a scrollable transcript area.');
assert_true((bool) preg_match('/\.admin-chat-transcript\s*\{[^}]*background:\s*var\(--surface-soft\);/s', $cssSource), 'Chat transcript should use the shared soft surface token for light and dark themes.');
assert_true(str_contains($cssSource, '.admin-chat-message.user .admin-chat-bubble'), 'Chat session CSS should visually distinguish user messages.');
assert_true(str_contains($cssSource, '.admin-chat-message.assistant'), 'Chat session CSS should visually distinguish assistant messages.');
assert_true(str_contains($cssSource, '.admin-chat-input-shell'), 'Chat session CSS should style the message input shell.');
assert_true(str_contains($cssSource, '.admin-chat-model-field'), 'Chat session CSS should style the embedded model selector.');
