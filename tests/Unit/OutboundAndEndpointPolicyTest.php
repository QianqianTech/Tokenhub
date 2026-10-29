<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\OutboundUrlPolicy;
use TokenHub\Services\Providers\ProviderEndpointResolver;
use TokenHub\Services\SafeRemoteFetcher;

// --- outbound policy (TH-006) ---
$dns = [
    'api.example-public.test' => ['93.184.216.34'],
    'metadata.attacker.test' => ['169.254.169.254'],
    'internal.attacker.test' => ['10.0.0.5'],
    'vllm.corp.test' => ['10.20.0.8'],
    'rebind.attacker.test' => ['93.184.216.34', '127.0.0.1'],
];
$resolver = static fn (string $host): array => $dns[$host] ?? (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : []);
$policy = new OutboundUrlPolicy($resolver, ['vllm.corp.test', '10.30.0.0/16']);

assert_true($policy->check('https://api.example-public.test/v1', OutboundUrlPolicy::PURPOSE_PROVIDER)['ok'], 'Public providers are allowed.');
assert_same('forbidden_address', $policy->check('http://metadata.attacker.test/latest', OutboundUrlPolicy::PURPOSE_PROVIDER, ['allow_private' => true])['reason'], 'Metadata addresses are refused even with private access enabled.');
assert_same('private_address_not_allowed', $policy->check('http://internal.attacker.test/', OutboundUrlPolicy::PURPOSE_REMOTE_ASSET)['reason'], 'Upstream-returned URLs cannot reach private networks.');
assert_same('private_address_not_allowed', $policy->check('https://rebind.attacker.test/x', OutboundUrlPolicy::PURPOSE_REMOTE_ASSET)['reason'], 'Any private answer in the DNS set is refused.');
assert_true($policy->check('http://vllm.corp.test:8000/v1', OutboundUrlPolicy::PURPOSE_PROVIDER)['ok'], 'Allowlisted private inference hosts are allowed.');
assert_true($policy->check('http://10.30.4.4/v1', OutboundUrlPolicy::PURPOSE_PROVIDER)['ok'], 'Allowlisted private CIDRs are allowed.');
assert_true($policy->check('http://internal.attacker.test/v1', OutboundUrlPolicy::PURPOSE_PROVIDER, ['allow_private' => true])['ok'], 'Provider-level private permission works.');
assert_same('scheme_not_allowed', $policy->check('http://api.example-public.test/hook', OutboundUrlPolicy::PURPOSE_WEBHOOK)['reason'], 'Webhooks require https.');
assert_same('credentials_in_url', $policy->check('https://user:pass@api.example-public.test/', OutboundUrlPolicy::PURPOSE_PROVIDER)['reason'], 'Credentials in URLs are refused.');
assert_same(false, $policy->check('file:///etc/passwd', OutboundUrlPolicy::PURPOSE_REMOTE_ASSET)['ok'], 'Non-http schemes are refused.');
assert_same('scheme_not_allowed', $policy->check('gopher://api.example-public.test/', OutboundUrlPolicy::PURPOSE_PROVIDER)['reason'], 'Only http(s) is allowed.');
assert_same('loopback', $policy->category('127.0.0.1'), 'Loopback is categorised.');
assert_same('private', $policy->category('100.64.1.1'), 'CGNAT is private.');
assert_same('private', $policy->category('fd12::1'), 'IPv6 ULA is private.');
assert_same('public', $policy->category('2001:4860:4860::8888'), 'Public IPv6 is public.');

// --- bounded remote fetch: redirect re-validation, size and status ---
$hops = [
    'https://api.example-public.test/a' => ['status' => 302, 'headers' => ['HTTP/1.1 302 Found', 'Location: http://metadata.attacker.test/latest'], 'body' => '', 'truncated' => false],
    'https://api.example-public.test/big' => ['status' => 200, 'headers' => ['HTTP/1.1 200 OK'], 'body' => false, 'truncated' => true],
    'https://api.example-public.test/ok' => ['status' => 301, 'headers' => ['HTTP/1.1 301', 'Location: /final'], 'body' => '', 'truncated' => false],
    'https://api.example-public.test/final' => ['status' => 200, 'headers' => ['HTTP/1.1 200 OK', 'Content-Type: image/png'], 'body' => 'PNG', 'truncated' => false],
];
$fetcher = new SafeRemoteFetcher($policy, static fn (string $url, array $options): array => $hops[$url] ?? ['status' => 404, 'headers' => [], 'body' => '', 'truncated' => false], 1024, 5);
assert_same('OUTBOUND_BLOCKED:forbidden_address', $fetcher->get('https://api.example-public.test/a')['error'] ?? null, 'Redirects into forbidden targets are blocked at the next hop.');
assert_same('OUTBOUND_TOO_LARGE', $fetcher->get('https://api.example-public.test/big')['error'] ?? null, 'Oversized bodies are refused.');
$ok = $fetcher->get('https://api.example-public.test/ok');
assert_same('PNG', $ok['body'] ?? null, 'Relative redirects to allowed targets are followed.');
assert_same('image/png', $ok['content_type'] ?? null, 'Content type is reported.');

// --- endpoint resolution (TH-013) ---
$resolver = new ProviderEndpointResolver();
$cases = [
    ['https://api.openai.com', '/v1/chat/completions', 'https://api.openai.com/v1/chat/completions'],
    ['https://generativelanguage.googleapis.com/v1beta/openai', '/v1/chat/completions', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'],
    ['https://generativelanguage.googleapis.com/v1beta/openai/', '/v1/embeddings', 'https://generativelanguage.googleapis.com/v1beta/openai/embeddings'],
    ['https://open.bigmodel.cn/api/paas/v4', '/v1/chat/completions', 'https://open.bigmodel.cn/api/paas/v4/chat/completions'],
    ['https://dashscope.aliyuncs.com/compatible-mode/v1', '/v1/chat/completions', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions'],
    ['https://ark.cn-beijing.volces.com/api/v3', '/v1/chat/completions', 'https://ark.cn-beijing.volces.com/api/v3/chat/completions'],
    ['https://openrouter.ai/api/v1', '/v1/chat/completions', 'https://openrouter.ai/api/v1/chat/completions'],
    ['https://openrouter.ai/api/v1', '/v1/generation?id=gen-1', 'https://openrouter.ai/api/v1/generation?id=gen-1'],
    ['https://api.groq.com/openai/v1', '/v1/chat/completions', 'https://api.groq.com/openai/v1/chat/completions'],
    ['https://api.deepseek.com', '/v1/chat/completions', 'https://api.deepseek.com/v1/chat/completions'],
    ['https://api.deepseek.com/anthropic', '/v1/messages', 'https://api.deepseek.com/anthropic/v1/messages'],
    ['https://api.anthropic.com', '/v1/messages', 'https://api.anthropic.com/v1/messages'],
    ['https://api.kimi.com/coding/v1', '/v1/chat/completions', 'https://api.kimi.com/coding/v1/chat/completions'],
    ['https://ark.cn-beijing.volces.com/api/v3', '/contents/generations/tasks', 'https://ark.cn-beijing.volces.com/api/v3/contents/generations/tasks'],
];
foreach ($cases as [$base, $endpoint, $expected]) {
    $resolved = $resolver->resolve($base, $endpoint);
    assert_same($expected, $resolved['url'] ?? null, "Endpoint mapping for {$base}{$endpoint}");
    assert_true(!str_contains((string) $resolved['url'], '/v1/v1/') && !str_contains((string) $resolved['url'], 'v1beta/openai/v1'), 'No duplicated version segment.');
}
$mapped = $resolver->resolve('https://example.test/root', '/v1/chat/completions', ['tokenhub_endpoint_map' => ['/v1/chat/completions' => '/llm/chat']]);
assert_same('https://example.test/root/llm/chat', $mapped['url'], 'Explicit endpoint map wins.');
assert_same(false, $resolver->resolve('https://example.test', '/v1/responses', ['tokenhub_unsupported_endpoints' => ['/v1/responses']])['ok'], 'Unsupported endpoints are rejected explicitly.');
assert_same(false, $resolver->resolve('https://example.test', '/v1/embeddings', ['tokenhub_endpoint_map' => '{"/v1/embeddings": null}'])['ok'], 'A null mapping marks an endpoint unsupported.');

// IPv6 addresses that embed an IPv4 address are judged by that address (SSRF).
$embeddedPolicy = new \TokenHub\Services\OutboundUrlPolicy(static fn (string $host): array => []);
foreach (['::ffff:169.254.169.254' => 'metadata', '64:ff9b::a9fe:a9fe' => 'metadata', '2002:a9fe:a9fe::1' => 'metadata', '::ffff:10.0.0.1' => 'private', '::ffff:127.0.0.1' => 'loopback', '2606:4700::1111' => 'public'] as $embeddedAddress => $expectedCategory) {
    assert_same($expectedCategory, $embeddedPolicy->category($embeddedAddress), 'IPv4-embedded IPv6 ' . $embeddedAddress . ' is ' . $expectedCategory . '.');
}
$mapped = new \TokenHub\Services\OutboundUrlPolicy(static fn (string $host): array => ['::ffff:169.254.169.254']);
assert_same('forbidden_address', $mapped->check('https://sneaky.example.com/', \TokenHub\Services\OutboundUrlPolicy::PURPOSE_PROVIDER, ['allow_private' => true])['reason'] ?? null, 'Mapped metadata addresses stay forbidden even where private networks are allowed.');

