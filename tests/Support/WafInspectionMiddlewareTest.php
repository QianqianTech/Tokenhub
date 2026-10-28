<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$inspectorClass = implode(chr(92), ['TokenHub', 'Services', 'WafRequestInspector']);
$middlewareClass = implode(chr(92), ['TokenHub', 'Middleware', 'WafInspectionMiddleware']);
$requestClass = implode(chr(92), ['TokenHub', 'Support', 'Request']);
$contextClass = implode(chr(92), ['TokenHub', 'Support', 'RequestContext']);
$responseClass = implode(chr(92), ['TokenHub', 'Support', 'Response']);

$records = [];
$waf = new class($records) {
    public function __construct(public array &$records) {}
    public function recordInspection(object $request, array $result, string $mode): void { $this->records[] = [$request, $result, $mode]; }
};
$settings = ['enabled' => true, 'mode' => 'block', 'block_threshold' => 100, 'max_inspect_bytes' => 65536, 'max_field_count' => 128, 'max_field_bytes' => 4096, 'excluded_paths' => ['/health']];
$rules = [['rule_code' => 'attack_sql_injection', 'matcher' => ['type' => 'attack', 'categories' => ['sqli'], 'targets' => ['query'], 'weight' => 100, 'critical' => false], 'status' => 'active']];
$request = new $requestClass('GET', '/v1/models', [], ['q' => '1 UNION SELECT 1'], [], '', new $contextClass('req_waf_middleware', 'trc_waf_middleware'));
$nextCalls = 0;
$middleware = new $middlewareClass(new $inspectorClass(), $waf, $settings, $rules);
$response = $middleware($request, function () use (&$nextCalls, $responseClass) { $nextCalls++; return new $responseClass('reachable'); });

assert_same(403, $response->status, 'Block mode should stop a request at the configured risk threshold.');
assert_true(str_contains($response->body, 'WAF_BLOCKED'), 'Block mode should return the stable WAF error code.');
assert_same(0, $nextCalls, 'Blocked WAF request should not reach downstream middleware.');
assert_same(1, count($records), 'Blocked WAF request should create one sanitized event.');

$settings['mode'] = 'observe';
$observeCalls = 0;
$observeResponse = (new $middlewareClass(new $inspectorClass(), $waf, $settings, $rules))($request, function () use (&$observeCalls, $responseClass) { $observeCalls++; return new $responseClass('reachable'); });
assert_same(200, $observeResponse->status, 'Observe mode should allow the request.');
assert_same(1, $observeCalls, 'Observe mode should continue to the downstream middleware.');
