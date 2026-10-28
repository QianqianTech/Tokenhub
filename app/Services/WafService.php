<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;
use TokenHub\Support\Request;

final class WafService
{
    private const ALLOWED_ACTIONS = ['block', 'redact', 'remove_header', 'allow'];
    private const ALLOWED_MATCHER_TYPES = ['ip', 'path', 'method', 'query', 'header', 'body_size', 'security_headers', 'attack'];

    /**
     * @return list<array<string, mixed>>
     */
    public function templates(): array
    {
        return [
            [
                'key' => 'attack_sql_injection',
                'label' => 'SQL injection detection',
                'description' => 'Observe normalized SQL injection signals.',
                'action' => 'block',
                'priority' => 15,
                'matcher' => ['type' => 'attack', 'categories' => ['sqli'], 'targets' => ['query', 'body'], 'weight' => 100, 'critical' => false],
            ],
            [
                'key' => 'attack_xss',
                'label' => 'XSS detection',
                'description' => 'Observe normalized cross-site scripting signals.',
                'action' => 'block',
                'priority' => 16,
                'matcher' => ['type' => 'attack', 'categories' => ['xss'], 'targets' => ['query', 'body'], 'weight' => 100, 'critical' => false],
            ],
            [
                'key' => 'attack_command_injection',
                'label' => 'Command injection detection',
                'description' => 'Observe shell command injection signals.',
                'action' => 'block',
                'priority' => 17,
                'matcher' => ['type' => 'attack', 'categories' => ['command_injection'], 'targets' => ['query', 'body'], 'weight' => 100, 'critical' => false],
            ],
            [
                'key' => 'attack_ssrf',
                'label' => 'SSRF detection',
                'description' => 'Observe server-side request forgery signals.',
                'action' => 'block',
                'priority' => 18,
                'matcher' => ['type' => 'attack', 'categories' => ['ssrf'], 'targets' => ['query', 'body'], 'weight' => 100, 'critical' => false],
            ],
            [
                'key' => 'attack_path_traversal',
                'label' => 'Path traversal detection',
                'description' => 'Observe encoded path traversal signals.',
                'action' => 'block',
                'priority' => 19,
                'matcher' => ['type' => 'attack', 'categories' => ['path_traversal'], 'targets' => ['path', 'query', 'body'], 'weight' => 100, 'critical' => false],
            ],
            [
                'key' => 'high_risk_paths',
                'label' => '高风险路径拦截',
                'description' => '拦截 .env、.git、备份文件和调试路径。',
                'action' => 'block',
                'priority' => 20,
                'matcher' => ['type' => 'path', 'values' => ['/.env', '/.git/*', '*.bak', '*~', '/debug*']],
            ],
            [
                'key' => 'sensitive_query_keys',
                'label' => '敏感查询参数拦截',
                'description' => '拦截 URL 中的 token、secret、api_key 等敏感参数。',
                'action' => 'block',
                'priority' => 30,
                'matcher' => ['type' => 'query', 'keys' => ['token', 'key', 'secret', 'api_key', 'access_token']],
            ],
            [
                'key' => 'scanner_user_agents',
                'label' => '扫描器 User-Agent 拦截',
                'description' => '拦截常见扫描器和攻击工具请求头。',
                'action' => 'block',
                'priority' => 40,
                'matcher' => ['type' => 'header', 'headers' => ['User-Agent' => '*sqlmap*']],
            ],
            [
                'key' => 'method_restriction',
                'label' => '危险 HTTP 方法拦截',
                'description' => '拦截 TRACE、TRACK、CONNECT 等方法。',
                'action' => 'block',
                'priority' => 50,
                'matcher' => ['type' => 'method', 'deny' => ['TRACE', 'TRACK', 'CONNECT']],
            ],
            [
                'key' => 'body_size_limit',
                'label' => '请求体大小限制',
                'description' => '限制入口请求体大小，避免异常大包进入 PHP。',
                'action' => 'block',
                'priority' => 60,
                'matcher' => ['type' => 'body_size', 'max_bytes' => 10485760],
            ],
            [
                'key' => 'admin_cidr_allow',
                'label' => '管理端网段白名单',
                'description' => '限制 /admin 和 /admin-api 只允许指定网段访问。',
                'action' => 'block',
                'priority' => 10,
                'matcher' => ['type' => 'ip', 'paths' => ['/admin*', '/admin-api/*'], 'allow' => ['10.0.0.0/8']],
            ],
            [
                'key' => 'api_cidr_allow',
                'label' => 'API 入口网段白名单',
                'description' => '限制 /v1 API 只允许指定网段访问。',
                'action' => 'block',
                'priority' => 11,
                'matcher' => ['type' => 'ip', 'paths' => ['/v1/*'], 'allow' => ['10.0.0.0/8']],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload = []): array
    {
        $sourceRules = array_key_exists('rules', $payload)
            ? (is_array($payload['rules']) ? array_values($payload['rules']) : [])
            : App::db()->select('SELECT * FROM waf_rules WHERE status = "active" ORDER BY priority ASC');
        $findings = ['errors' => [], 'warnings' => []];
        $highRisk = [];
        $rules = [];

        if (array_key_exists('rules', $payload) && !is_array($payload['rules'])) {
            $findings['errors'][] = [
                'rule_code' => null,
                'field' => 'rules',
                'message' => 'rules must be an array',
            ];
        }

        foreach ($sourceRules as $index => $rule) {
            if (!is_array($rule)) {
                $findings['errors'][] = [
                    'rule_code' => null,
                    'field' => 'rules.' . $index,
                    'message' => 'rule must be an object',
                ];
                continue;
            }
            $normalized = $this->normalizePreviewRule($rule, $index, $findings, $highRisk);
            if ($normalized !== null) {
                $rules[] = $normalized;
            }
        }

        $status = $findings['errors'] === [] ? 'valid' : 'invalid';
        $fragment = $status === 'valid' ? $this->generateFragment($rules) : '';

        return [
            'status' => $status,
            'fragment' => $fragment,
            'rules' => $rules,
            'findings' => $findings,
            'high_risk' => $highRisk,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function attackRules(): array
    {
        $rules = array_values(array_filter(App::db()->select('SELECT * FROM waf_rules WHERE status = "active" ORDER BY priority ASC'), fn (array $rule): bool => ($this->matcherFromRule($rule)['type'] ?? '') === 'attack'));
        if ($rules !== []) {
            return $rules;
        }
        return array_map(static fn (array $template): array => ['rule_code' => $template['key'], 'matcher' => $template['matcher'], 'status' => 'active'], array_values(array_filter($this->templates(), static fn (array $template): bool => str_starts_with((string) $template['key'], 'attack_'))));
    }

    /** @param array{risk_score:int,categories:list<string>,hits:list<array<string,mixed>>,blocked:bool,matched:bool} $result */
    public function recordInspection(Request $request, array $result, string $mode): void
    {
        $hits = array_map(static fn (array $hit): array => ['rule_code' => (string) ($hit['rule_code'] ?? ''), 'category' => (string) ($hit['category'] ?? ''), 'target' => (string) ($hit['target'] ?? ''), 'fingerprint' => (string) ($hit['fingerprint'] ?? ''), 'weight' => (int) ($hit['weight'] ?? 0)], $result['hits']);
        App::db()->execute(
            'INSERT INTO waf_events (rule_code, request_id, client_ip, method, path, action, detection_mode, risk_score, attack_categories, summary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) ($hits[0]['rule_code'] ?? 'attack'), $request->context->requestId(), $request->clientIp(), $request->method, $request->path, $mode === 'block' && $result['blocked'] ? 'block' : 'observe', $mode, $result['risk_score'], json_encode($result['categories'], JSON_UNESCAPED_SLASHES), json_encode(['hits' => $hits], JSON_UNESCAPED_SLASHES)]
        );
    }

    public function publish(array $options = []): array
    {
        $rules = App::db()->select('SELECT * FROM waf_rules WHERE status = "active" ORDER BY priority ASC');
        $preview = $this->preview(['rules' => $rules]);
        if (($preview['status'] ?? '') !== 'valid') {
            abort_json('WAF_CONFIG_INVALID', 'WAF rule configuration is invalid', 400);
        }
        if (($options['dry_run'] ?? false) === true) {
            return [
                'status' => 'dry_run',
                'validated' => true,
                'reloaded' => false,
                'fragment' => $preview['fragment'],
                'findings' => $preview['findings'],
                'high_risk' => $preview['high_risk'],
            ];
        }
        if (($preview['high_risk'] ?? []) !== [] && ($options['confirm_high_risk'] ?? false) !== true) {
            return [
                'status' => 'requires_confirmation',
                'validated' => true,
                'reloaded' => false,
                'fragment' => $preview['fragment'],
                'findings' => $preview['findings'],
                'high_risk' => $preview['high_risk'],
            ];
        }
        $fragment = (string) $preview['fragment'];
        $version = Crypto::token('waf_');
        $install = $this->installFragment($fragment, [
            'reload' => $options['reload'] ?? true,
            'fragment_path' => $options['fragment_path'] ?? null,
            'config_path' => $options['config_path'] ?? null,
        ]);
        $status = $install['status'] === 'published' ? 'published' : 'failed';

        App::db()->execute(
            'INSERT INTO waf_rule_versions (version_code, rules_snapshot, caddyfile_fragment, status, published_by) VALUES (?, ?, ?, ?, ?)',
            [$version, json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $fragment, $status, App::context()->adminUser()['id'] ?? null]
        );
        if ($status === 'published') {
            App::db()->execute('UPDATE waf_rule_versions SET status = "rolled_back" WHERE version_code != ? AND status = "published"', [$version]);
        }
        (new AuditService())->write('waf.publish', 'waf_rule_versions', $version, ['status' => $status]);
        App::logger()->info('security', 'waf fragment publish attempted', ['version_code' => $version, ...$install]);

        return ['version_code' => $version, 'fragment' => $fragment, ...$install];
    }

    public function rollback(string $versionCode, array $options = []): array
    {
        $version = App::db()->first('SELECT * FROM waf_rule_versions WHERE version_code = ?', [$versionCode]);
        if (!$version) {
            abort_json('ADMIN_PERMISSION_DENIED', 'WAF version not found', 404);
        }

        $install = $this->installFragment((string) $version['caddyfile_fragment'], [
            'reload' => $options['reload'] ?? true,
            'fragment_path' => $options['fragment_path'] ?? null,
            'config_path' => $options['config_path'] ?? null,
        ]);
        if ($install['status'] === 'published') {
            App::db()->execute('UPDATE waf_rule_versions SET status = "rolled_back" WHERE version_code != ? AND status = "published"', [$versionCode]);
            App::db()->execute('UPDATE waf_rule_versions SET status = "published" WHERE version_code = ?', [$versionCode]);
        }
        (new AuditService())->write('waf.rollback', 'waf_rule_versions', $versionCode, ['status' => $install['status']]);
        App::logger()->info('security', 'waf fragment rollback attempted', ['version_code' => $versionCode, ...$install]);

        return ['version_code' => $versionCode, ...$install];
    }

    public function versions(): array
    {
        return App::db()->select('SELECT id, version_code, status, published_by, created_at FROM waf_rule_versions ORDER BY id DESC LIMIT 100');
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $statistics = $this->statistics();
        $publication = App::db()->first(
            'SELECT version_code, status, published_by, created_at FROM waf_rule_versions ORDER BY id DESC LIMIT 1'
        ) ?: [];
        $recent = $this->events(['limit' => 10]);

        return [
            'publication' => [
                'version_code' => $publication['version_code'] ?? null,
                'status' => $publication['status'] ?? 'unpublished',
                'published_by' => $publication['published_by'] ?? null,
                'published_at' => $publication['created_at'] ?? null,
            ],
            'summary' => [
                ...($statistics['summary'] ?? []),
                'active_rule_count' => (int) (App::db()->first('SELECT COUNT(*) count_value FROM waf_rules WHERE status = "active"')['count_value'] ?? 0),
                'active_ban_count' => count((new LoginIpBanService())->activeBannedIps()),
            ],
            'top_rules' => $statistics['top_rules'] ?? [],
            'recent_events' => $recent['records'] ?? [],
        ];
    }

    public function events(array $query = []): array
    {
        [$where, $bindings] = $this->eventWhere($query);
        $limit = min(100, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $total = (int) (App::db()->first("SELECT COUNT(*) count_value FROM waf_events{$where}", $bindings)['count_value'] ?? 0);
        $records = App::db()->select(
            "SELECT id, rule_code, request_id, client_ip, method, path, action, summary,
                    disposition_status, false_positive, handled_by, handled_at, disposition_note, risk_score,
                    created_at
             FROM waf_events{$where}
             ORDER BY created_at DESC, id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );

        return [
            'records' => array_map(fn (array $row): array => $this->normalizeEventRow($row), $records),
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleEvent(int $id, array $payload): array
    {
        $event = App::db()->first('SELECT * FROM waf_events WHERE id = ?', [$id]);
        if ($event === null) {
            abort_json('WAF_EVENT_NOT_FOUND', 'WAF event not found', 404);
        }

        $action = (string) ($payload['action'] ?? 'review');
        $note = substr(trim((string) ($payload['note'] ?? '')), 0, 500);
        $handledBy = App::context()->adminUser()['id'] ?? null;
        $clientIp = (string) ($event['client_ip'] ?? '');
        $status = match ($action) {
            'review' => 'reviewed',
            'false_positive' => 'false_positive',
            'ban_ip' => 'manual_banned',
            'unban_ip' => 'unbanned',
            default => '',
        };
        if ($status === '') {
            abort_json('WAF_DISPOSITION_INVALID', 'Invalid WAF disposition action', 400);
        }

        if ($action === 'ban_ip' && $clientIp !== '') {
            $this->banIp($clientIp, 'waf_manual_ban', 1);
        }
        if ($action === 'unban_ip' && $clientIp !== '') {
            (new LoginIpBanService())->reset($clientIp);
        }

        App::db()->execute(
            'UPDATE waf_events
             SET disposition_status = ?,
                 false_positive = ?,
                 handled_by = ?,
                 handled_at = NOW(),
                 disposition_note = ?,
                 risk_score = ?
             WHERE id = ?',
            [
                $status,
                $action === 'false_positive' ? 1 : (int) ($event['false_positive'] ?? 0),
                $handledBy,
                $note === '' ? null : $note,
                $this->riskScore((string) ($event['action'] ?? ''), $status),
                $id,
            ]
        );

        (new AuditService())->write('waf.event.handle', 'waf_events', (string) $id, ['action' => $action, 'client_ip' => $clientIp]);

        return $this->normalizeEventRow(App::db()->first('SELECT * FROM waf_events WHERE id = ?', [$id]) ?: []);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{threshold:int,window_minutes:int,banned_count:int,banned:list<array<string,mixed>>}
     */
    public function autoDisposition(array $options = []): array
    {
        $threshold = min(100000, max(1, (int) ($options['threshold'] ?? 10)));
        $windowMinutes = min(1440, max(1, (int) ($options['window_minutes'] ?? 5)));
        $rows = App::db()->select(
            "SELECT client_ip, COUNT(*) hit_count, MAX(created_at) last_seen_at
             FROM waf_events
             WHERE action = 'block'
               AND false_positive = 0
               AND client_ip IS NOT NULL
               AND client_ip != ''
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$windowMinutes} MINUTE)
             GROUP BY client_ip
             HAVING hit_count >= ?
             ORDER BY hit_count DESC, last_seen_at DESC
             LIMIT 100",
            [$threshold]
        );

        $banned = [];
        foreach ($rows as $row) {
            $clientIp = (string) ($row['client_ip'] ?? '');
            if ($clientIp === '') {
                continue;
            }
            $hitCount = (int) ($row['hit_count'] ?? 0);
            $this->banIp($clientIp, 'waf_auto_disposition', $hitCount);
            App::db()->execute(
                "UPDATE waf_events
                 SET disposition_status = 'auto_banned',
                     handled_at = NOW(),
                     disposition_note = ?,
                     risk_score = GREATEST(COALESCE(risk_score, 0), ?)
                 WHERE client_ip = ?
                   AND action = 'block'
                   AND false_positive = 0
                   AND created_at >= DATE_SUB(NOW(), INTERVAL {$windowMinutes} MINUTE)",
                ['Auto-banned after repeated WAF hits', $this->riskScore('block', 'auto_banned'), $clientIp]
            );
            $banned[] = [
                'client_ip' => $clientIp,
                'hit_count' => $hitCount,
                'last_seen_at' => $row['last_seen_at'] ?? null,
            ];
        }

        if ($banned !== []) {
            (new AuditService())->write('waf.auto_disposition', 'waf_events', 'auto', [
                'threshold' => $threshold,
                'window_minutes' => $windowMinutes,
                'banned_count' => count($banned),
            ]);
        }

        return [
            'threshold' => $threshold,
            'window_minutes' => $windowMinutes,
            'banned_count' => count($banned),
            'banned' => $banned,
        ];
    }

    public function statistics(array $query = []): array
    {
        [$where, $bindings] = $this->eventWhere($query);
        $bucket = ($query['bucket'] ?? 'hour') === 'day' ? '%Y-%m-%d 00:00:00' : '%Y-%m-%d %H:00:00';
        $summary = App::db()->first(
            "SELECT COUNT(*) total_events,
                    COALESCE(SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END), 0) blocked_events,
                    COUNT(DISTINCT client_ip) unique_ips,
                    COUNT(DISTINCT rule_code) unique_rules
             FROM waf_events{$where}",
            $bindings
        ) ?: [];

        return [
            'summary' => [
                'total_events' => (int) ($summary['total_events'] ?? 0),
                'blocked_events' => (int) ($summary['blocked_events'] ?? 0),
                'unique_ips' => (int) ($summary['unique_ips'] ?? 0),
                'unique_rules' => (int) ($summary['unique_rules'] ?? 0),
            ],
            'trends' => $this->integerRows(App::db()->select(
                "SELECT DATE_FORMAT(created_at, '{$bucket}') bucket_at,
                        COUNT(*) event_count,
                        COALESCE(SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END), 0) blocked_count
                 FROM waf_events{$where}
                 GROUP BY bucket_at
                 ORDER BY bucket_at DESC
                 LIMIT 48",
                $bindings
            ), ['event_count', 'blocked_count']),
            'top_rules' => $this->integerRows(App::db()->select(
                "SELECT COALESCE(rule_code, 'unknown') rule_code,
                        COUNT(*) event_count,
                        COALESCE(SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END), 0) blocked_count,
                        MAX(created_at) last_seen_at
                 FROM waf_events{$where}
                 GROUP BY COALESCE(rule_code, 'unknown')
                 ORDER BY event_count DESC, last_seen_at DESC
                 LIMIT 20",
                $bindings
            ), ['event_count', 'blocked_count']),
            'top_ips' => $this->integerRows(App::db()->select(
                "SELECT client_ip,
                        COUNT(*) event_count,
                        COALESCE(SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END), 0) blocked_count,
                        MAX(created_at) last_seen_at
                 FROM waf_events{$this->appendEventWhere($where, 'client_ip IS NOT NULL')}
                 GROUP BY client_ip
                 ORDER BY event_count DESC, last_seen_at DESC
                 LIMIT 20",
                $bindings
            ), ['event_count', 'blocked_count']),
            'top_paths' => $this->integerRows(App::db()->select(
                "SELECT path,
                        COUNT(*) event_count,
                        COALESCE(SUM(CASE WHEN action = 'block' THEN 1 ELSE 0 END), 0) blocked_count,
                        MAX(created_at) last_seen_at
                 FROM waf_events{$this->appendEventWhere($where, 'path IS NOT NULL')}
                 GROUP BY path
                 ORDER BY event_count DESC, last_seen_at DESC
                 LIMIT 20",
                $bindings
            ), ['event_count', 'blocked_count']),
        ];
    }

    public function diff(string $versionCode, string $base = 'previous'): array
    {
        $current = App::db()->first('SELECT * FROM waf_rule_versions WHERE version_code = ?', [$versionCode]);
        if (!$current) {
            abort_json('ADMIN_PERMISSION_DENIED', 'WAF version not found', 404);
        }
        $baseRow = $base === 'previous'
            ? App::db()->first('SELECT * FROM waf_rule_versions WHERE id < ? ORDER BY id DESC LIMIT 1', [$current['id']])
            : App::db()->first('SELECT * FROM waf_rule_versions WHERE version_code = ?', [$base]);

        return [
            'version_code' => $versionCode,
            'base_version_code' => $baseRow['version_code'] ?? null,
            'rules' => $this->lineDiff((string) ($baseRow['rules_snapshot'] ?? '[]'), (string) $current['rules_snapshot']),
            'caddyfile_fragment' => $this->lineDiff((string) ($baseRow['caddyfile_fragment'] ?? ''), (string) $current['caddyfile_fragment']),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function ingestSecurityLog(?string $file = null, array $options = []): array
    {
        $file ??= storage_path('logs/caddy-security.jsonl');
        $offsetFile = (string) ($options['offset_file'] ?? storage_path('cache/caddy-security.offset'));
        $imported = 0;
        foreach ($this->securityEventsFromLog($file, $offsetFile) as $event) {
            App::db()->execute(
                'INSERT INTO waf_events (rule_code, request_id, client_ip, method, path, action, summary) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $event['rule_code'],
                    $event['request_id'] ?? null,
                    $event['client_ip'] ?? null,
                    $event['method'] ?? null,
                    $event['path'] ?? null,
                    $event['action'],
                    json_encode($event['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]
            );
            $imported++;
        }

        $result = ['imported' => $imported, 'file' => $file, 'offset_file' => $offsetFile];
        if (($options['auto_disposition'] ?? true) !== false) {
            $autoDisposition = $this->autoDisposition($this->autoDispositionOptions($options));
            $result['auto_disposition'] = $autoDisposition;
            $result['banned_count'] = $autoDisposition['banned_count'];
            if ($imported > 0 && $autoDisposition['banned_count'] > 0) {
                (new AlertService())->record('waf_auto_disposition', 'critical', 'WAF automatic disposition banned repeated source IPs', [
                    'imported' => $imported,
                    'threshold' => $autoDisposition['threshold'],
                    'window_minutes' => $autoDisposition['window_minutes'],
                    'banned_count' => $autoDisposition['banned_count'],
                    'banned_ips' => array_column($autoDisposition['banned'], 'client_ip'),
                ]);
            }
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function securityEventsFromLog(string $file, ?string $offsetFile = null): array
    {
        if (!is_file($file)) {
            return [];
        }
        $offset = $offsetFile && is_file($offsetFile) ? max(0, (int) file_get_contents($offsetFile)) : 0;
        $size = filesize($file) ?: 0;
        if ($offset > $size) {
            $offset = 0;
        }

        $handle = fopen($file, 'rb');
        if (!is_resource($handle)) {
            return [];
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $events = [];
        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode(trim($line), true);
            if (!is_array($decoded)) {
                continue;
            }
            $request = $decoded['request'] ?? [];
            $vars = $decoded['vars'] ?? [];
            $events[] = [
                'rule_code' => $vars['waf_rule'] ?? $decoded['rule_code'] ?? null,
                'request_id' => $decoded['request_id'] ?? null,
                'client_ip' => $request['remote_ip'] ?? $decoded['remote_ip'] ?? null,
                'method' => $request['method'] ?? null,
                'path' => $request['uri'] ?? null,
                'action' => $vars['waf_action'] ?? $decoded['action'] ?? 'block',
                'summary' => ['source' => 'caddy-security', 'status' => $decoded['status'] ?? null],
            ];
        }
        $position = ftell($handle);
        fclose($handle);

        if ($offsetFile !== null) {
            $dir = dirname($offsetFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($offsetFile, (string) ($position === false ? $size : $position), LOCK_EX);
        }

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    public function generateFragment(array $rules): string
    {
        return $this->fragment($rules, (new LoginIpBanService())->activeBannedIps());
    }

    /**
     * @param array<string, mixed> $options
     * @param null|callable(string): array<string, mixed> $runner
     * @return array<string, mixed>
     */
    public function installFragment(string $fragment, array $options = [], ?callable $runner = null): array
    {
        $fragmentPath = (string) ($options['fragment_path'] ?? App::config()->get('app.caddy_waf_fragment_path', storage_path('caddy' . DIRECTORY_SEPARATOR . 'waf-current.caddy')));
        $configPath = (string) ($options['config_path'] ?? App::config()->get('app.caddy_config_path', base_path('docker/caddy/Caddyfile')));
        $runner ??= fn (string $command): array => $this->runCommand($command);
        $previous = is_file($fragmentPath) ? (string) file_get_contents($fragmentPath) : '';
        if (!is_dir(dirname($fragmentPath))) {
            mkdir(dirname($fragmentPath), 0775, true);
        }

        $candidate = $fragmentPath . '.candidate';
        file_put_contents($candidate, $fragment, LOCK_EX);
        file_put_contents($fragmentPath, $fragment, LOCK_EX);
        $validateCommand = str_replace('{config}', $configPath, (string) ($options['validate_command'] ?? App::config()->get('app.caddy_validate_command', 'caddy validate --config {config}')));
        if ($validateCommand !== '') {
            $result = $runner($validateCommand);
            if (!($result['ok'] ?? false)) {
                file_put_contents($fragmentPath, $previous, LOCK_EX);
                @unlink($candidate);
                return [
                    'status' => 'failed',
                    'fragment_file' => $fragmentPath,
                    'validated' => false,
                    'reloaded' => false,
                    'error' => 'validate_failed',
                    'output' => $result['output'] ?? '',
                ];
            }
        }

        @unlink($candidate);

        if (($options['reload'] ?? false) === true) {
            $reloadCommand = str_replace('{config}', $configPath, (string) ($options['reload_command'] ?? App::config()->get('app.caddy_reload_command', 'caddy reload --config {config}')));
            if ($reloadCommand !== '') {
                $result = $runner($reloadCommand);
                if (!($result['ok'] ?? false)) {
                    file_put_contents($fragmentPath, $previous, LOCK_EX);
                    return [
                        'status' => 'failed',
                        'fragment_file' => $fragmentPath,
                        'validated' => true,
                        'reloaded' => false,
                        'error' => 'reload_failed',
                        'output' => $result['output'] ?? '',
                    ];
                }
            }
        }

        return [
            'status' => 'published',
            'fragment_file' => $fragmentPath,
            'validated' => true,
            'reloaded' => (bool) ($options['reload'] ?? false),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param list<string> $bannedIps
     */
    private function fragment(array $rules, array $bannedIps = []): string
    {
        $firewall = $this->firewallSettings();
        $lines = ["# Generated TokenHub WAF fragment"];
        if (($firewall['enabled'] ?? true) !== true) {
            $lines[] = "# WAF firewall disabled by settings";
            return implode("\n", $lines) . "\n";
        }

        $this->addFirewallRules($lines, $firewall);
        $bannedIps = array_values(array_filter(array_unique(array_map('strval', $bannedIps))));
        foreach ($bannedIps as $index => $bannedIp) {
            $matcherName = 'waf_system_login_password_fail_ban_' . $index;
            $lines[] = "# system_login_password_fail_ban: system blocked IPs after repeated admin password failures";
            $lines[] = "@{$matcherName} {";
            $lines[] = "  remote_ip {$bannedIp}";
            $lines[] = "}";
            $this->addBlock($lines, $matcherName, 'system_login_password_fail_ban', 'block', 403);
        }
        foreach ($rules as $rule) {
            $matcher = $this->matcherFromRule($rule);
            if (($matcher['type'] ?? '') === 'attack') {
                continue;
            }
            $lines[] = "# {$rule['rule_code']}: {$rule['description']}";
            $name = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $rule['rule_code']);
            $ruleCode = (string) $rule['rule_code'];
            $action = (string) ($rule['action'] ?? 'block');
            if (($matcher['type'] ?? '') === 'ip' && (!empty($matcher['allow']) || !empty($matcher['deny']))) {
                $lines[] = "@waf_{$name} {";
                if (!empty($matcher['paths'])) {
                    $lines[] = "  path " . implode(' ', array_map('strval', $matcher['paths']));
                }
                if (!empty($matcher['allow'])) {
                    $lines[] = "  not remote_ip " . implode(' ', array_map('strval', $matcher['allow']));
                }
                if (!empty($matcher['deny'])) {
                    $lines[] = "  remote_ip " . implode(' ', array_map('strval', $matcher['deny']));
                }
                $lines[] = "}";
                $this->addBlock($lines, "waf_{$name}", $ruleCode, $action, 403);
            }
            if (($matcher['type'] ?? '') === 'path' && !empty($matcher['values'])) {
                $lines[] = "@waf_{$name} path " . implode(' ', array_map('strval', $matcher['values']));
                if ($action === 'allow') {
                    $lines[] = "handle @waf_{$name} { }";
                } else {
                    $this->addBlock($lines, "waf_{$name}", $ruleCode, $action, 403);
                }
            }
            if (($matcher['type'] ?? '') === 'method' && !empty($matcher['deny'])) {
                $lines[] = "@waf_{$name} method " . implode(' ', array_map('strval', $matcher['deny']));
                $this->addBlock($lines, "waf_{$name}", $ruleCode, $action, 405);
            }
            if (($matcher['type'] ?? '') === 'query') {
                $keys = array_values(array_unique(array_map('strval', [...($matcher['keys'] ?? []), 'token', 'key', 'secret'])));
                foreach ($keys as $key) {
                    $lines[] = "@waf_{$name}_query_{$key} query {$key}=*";
                    $this->addBlock($lines, "waf_{$name}_query_{$key}", $ruleCode, $action, 403);
                }
                $patterns = array_values(array_unique(array_map('strval', [
                    ...($matcher['patterns'] ?? []),
                    '(?i)union\\s+select',
                    '../',
                    '..%2f',
                    '..%5c',
                ])));
                foreach ($patterns as $index => $pattern) {
                    $lines[] = "@waf_{$name}_pattern_{$index} expression `{query}.matches('{$pattern}')`";
                    $this->addBlock($lines, "waf_{$name}_pattern_{$index}", $ruleCode, $action, 403);
                }
            }
            if (($matcher['type'] ?? '') === 'header' && ($rule['action'] ?? '') === 'remove_header') {
                foreach ($matcher['headers'] ?? [] as $header) {
                    $lines[] = "request_header -{$header}";
                }
            }
            if (($matcher['type'] ?? '') === 'header' && ($rule['action'] ?? '') === 'redact') {
                foreach ($matcher['headers'] ?? [] as $header => $value) {
                    if (is_int($header)) {
                        $lines[] = "request_header {$value} \"****\"";
                    } else {
                        $lines[] = "request_header {$header} \"****\"";
                    }
                }
            }
            if (($matcher['type'] ?? '') === 'header' && ($rule['action'] ?? '') === 'block') {
                $lines[] = "@waf_{$name} {";
                foreach ($matcher['headers'] ?? [] as $header => $value) {
                    if (is_int($header)) {
                        $lines[] = "  header {$value} *";
                    } else {
                        $lines[] = "  header {$header} \"{$value}\"";
                    }
                }
                $lines[] = "}";
                $this->addBlock($lines, "waf_{$name}", $ruleCode, $action, 403);
            }
            if (($matcher['type'] ?? '') === 'body_size') {
                $lines[] = "request_body {";
                $lines[] = "  max_size " . (int) ($matcher['max_bytes'] ?? 1048576);
                $lines[] = "}";
            }
            if (($matcher['type'] ?? '') === 'security_headers') {
                foreach ($matcher['headers'] ?? [] as $header => $value) {
                    $lines[] = "header {$header} \"{$value}\"";
                }
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function firewallSettings(): array
    {
        try {
            return (new SystemSettingsService())->loadSection('firewall_settings');
        } catch (\Throwable) {
            return (new SystemSettingsService())->defaults()['firewall_settings'];
        }
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed> $settings
     */
    private function addFirewallRules(array &$lines, array $settings): void
    {
        $blockedPaths = array_values(array_filter(array_map('strval', $settings['blocked_paths'] ?? [])));
        if ($blockedPaths !== []) {
            $lines[] = "@blockedPath path " . implode(' ', $blockedPaths);
            $this->addBlock($lines, 'blockedPath', 'high_risk_path', 'block', 403);
        }

        $autoAllowLocal = ($settings['auto_allow_local_private_ips'] ?? true) === true;
        $adminAllowedCidrs = $this->effectiveAllowedCidrs($settings['admin_allowed_cidrs'] ?? [], $autoAllowLocal);
        $apiAllowedCidrs = $this->effectiveAllowedCidrs($settings['api_allowed_cidrs'] ?? [], $autoAllowLocal);
        $this->addIpAllowRule($lines, 'system_admin_allowed_cidrs', ['/admin*', '/admin-api/*'], $adminAllowedCidrs);
        $this->addIpAllowRule($lines, 'system_api_allowed_cidrs', ['/v1/*'], $apiAllowedCidrs);

        $blockedCidrs = array_values(array_filter(array_map('strval', $settings['manual_blocked_cidrs'] ?? [])));
        if ($blockedCidrs !== []) {
            $lines[] = "@waf_system_manual_blocked_cidrs {";
            $lines[] = "  remote_ip " . implode(' ', $blockedCidrs);
            $lines[] = "}";
            $this->addBlock($lines, 'waf_system_manual_blocked_cidrs', 'system_manual_blocked_cidrs', 'block', 403);
        }

        $knownMethods = ['GET', 'POST', 'OPTIONS', 'DELETE', 'PUT', 'PATCH', 'TRACE', 'TRACK', 'CONNECT'];
        $allowedMethods = array_values(array_unique(array_map('strtoupper', array_map('strval', $settings['allowed_methods'] ?? []))));
        $deniedMethods = array_values(array_diff($knownMethods, $allowedMethods));
        if ($deniedMethods !== []) {
            $lines[] = "@waf_system_denied_methods method " . implode(' ', $deniedMethods);
            $this->addBlock($lines, 'waf_system_denied_methods', 'system_denied_methods', 'block', 405);
        }

        foreach (array_values(array_filter(array_map('strval', $settings['sensitive_query_keys'] ?? []))) as $key) {
            $safeKey = preg_replace('/[^A-Za-z0-9_]/', '_', $key) ?: 'key';
            $lines[] = "@waf_system_sensitive_query_{$safeKey} query {$key}=*";
            $this->addBlock($lines, "waf_system_sensitive_query_{$safeKey}", 'system_sensitive_query', 'block', 403);
        }

        $maxBodyBytes = (int) ($settings['max_body_bytes'] ?? 0);
        if ($maxBodyBytes > 0) {
            $lines[] = "request_body {";
            $lines[] = "  max_size {$maxBodyBytes}";
            $lines[] = "}";
        }
    }

    /**
     * @param list<string> $lines
     * @param list<string> $paths
     * @param mixed $cidrs
     */
    private function addIpAllowRule(array &$lines, string $ruleCode, array $paths, mixed $cidrs): void
    {
        $cidrs = array_values(array_filter(array_map('strval', is_array($cidrs) ? $cidrs : [])));
        if ($cidrs === []) {
            return;
        }

        $matcherName = 'waf_' . $ruleCode;
        $lines[] = "@{$matcherName} {";
        $lines[] = "  path " . implode(' ', $paths);
        $lines[] = "  not remote_ip " . implode(' ', $cidrs);
        $lines[] = "}";
        $this->addBlock($lines, $matcherName, $ruleCode, 'block', 403);
    }

    /**
     * @return list<string>
     */
    private function effectiveAllowedCidrs(mixed $cidrs, bool $autoAllowLocal): array
    {
        $explicit = array_values(array_unique(array_filter(array_map('strval', is_array($cidrs) ? $cidrs : []))));
        if ($explicit === [] || !$autoAllowLocal) {
            return $explicit;
        }

        try {
            $ips = (new NetworkInterfaceIpService())->localAccessIps();
            foreach ($ips as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $explicit[] = $ip . '/32';
                }
            }
        } catch (\Throwable) {
            // Explicit allowlists remain valid when local interface discovery is unavailable.
        }

        return array_values(array_unique($explicit));
    }

    /**
     * @param list<string> $lines
     */
    private function addBlock(array &$lines, string $matcherName, string $ruleCode, string $action, int $status): void
    {
        $safeRule = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $ruleCode) ?: 'rule';
        $safeAction = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $action) ?: 'block';
        $lines[] = "handle @{$matcherName} {";
        $lines[] = "  vars waf_rule {$safeRule}";
        $lines[] = "  vars waf_action {$safeAction}";
        $lines[] = "  log_name caddy-security";
        $lines[] = "  respond \"Forbidden\" {$status}";
        $lines[] = "}";
    }

    /**
     * @param array<string, mixed> $rule
     * @param array{errors:list<array<string,mixed>>,warnings:list<array<string,mixed>>} $findings
     * @param list<array<string, mixed>> $highRisk
     * @return array<string, mixed>|null
     */
    private function normalizePreviewRule(array $rule, int $index, array &$findings, array &$highRisk): ?array
    {
        $ruleCode = trim((string) ($rule['rule_code'] ?? ''));
        $action = trim((string) ($rule['action'] ?? 'block'));
        $status = trim((string) ($rule['status'] ?? 'active'));
        $matcher = $this->matcherFromRule($rule);
        $hasError = false;

        if ($ruleCode === '' || !preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $ruleCode)) {
            $this->addFinding($findings, 'errors', $ruleCode !== '' ? $ruleCode : null, 'rule_code', 'rule_code must use 1-120 safe characters');
            $hasError = true;
        }
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            $this->addFinding($findings, 'errors', $ruleCode, 'action', 'action must be one of: ' . implode(', ', self::ALLOWED_ACTIONS));
            $hasError = true;
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            $this->addFinding($findings, 'errors', $ruleCode, 'status', 'status must be active or disabled');
            $hasError = true;
        }
        if (!$this->validateMatcher($ruleCode, $matcher, $action, $findings)) {
            $hasError = true;
        }

        if ($hasError) {
            return null;
        }

        $this->classifyHighRisk($ruleCode, $matcher, $action, $highRisk);

        return [
            'id' => $rule['id'] ?? null,
            'rule_code' => $ruleCode,
            'scope' => (string) ($rule['scope'] ?? 'global'),
            'matcher' => json_encode($matcher, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'action' => $action,
            'priority' => (int) ($rule['priority'] ?? (100 + $index)),
            'status' => $status,
            'description' => (string) ($rule['description'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $findings
     */
    private function validateMatcher(string $ruleCode, array $matcher, string $action, array &$findings): bool
    {
        $type = (string) ($matcher['type'] ?? '');
        if (!in_array($type, self::ALLOWED_MATCHER_TYPES, true)) {
            $this->addFinding($findings, 'errors', $ruleCode, 'matcher.type', 'matcher.type must be one of: ' . implode(', ', self::ALLOWED_MATCHER_TYPES));
            return false;
        }

        $valid = true;
        if ($type === 'ip' && $this->stringListFrom($matcher['allow'] ?? []) === [] && $this->stringListFrom($matcher['deny'] ?? []) === []) {
            $this->addFinding($findings, 'errors', $ruleCode, 'matcher', 'ip matcher requires allow or deny CIDRs');
            $valid = false;
        }
        if ($type === 'path' && $this->stringListFrom($matcher['values'] ?? []) === []) {
            $this->addFinding($findings, 'errors', $ruleCode, 'matcher.values', 'path matcher requires values');
            $valid = false;
        }
        if ($type === 'method' && $this->stringListFrom($matcher['deny'] ?? []) === []) {
            $this->addFinding($findings, 'errors', $ruleCode, 'matcher.deny', 'method matcher requires denied methods');
            $valid = false;
        }
        if ($type === 'query' && $this->stringListFrom($matcher['keys'] ?? []) === [] && $this->stringListFrom($matcher['patterns'] ?? []) === []) {
            $this->addFinding($findings, 'errors', $ruleCode, 'matcher', 'query matcher requires keys or patterns');
            $valid = false;
        }
        if ($type === 'header') {
            $headers = $matcher['headers'] ?? [];
            if (!is_array($headers) || $headers === []) {
                $this->addFinding($findings, 'errors', $ruleCode, 'matcher.headers', 'header matcher requires headers');
                $valid = false;
            }
            if (!in_array($action, ['block', 'redact', 'remove_header'], true)) {
                $this->addFinding($findings, 'errors', $ruleCode, 'action', 'header matcher action must be block, redact, or remove_header');
                $valid = false;
            }
        }
        if ($type === 'body_size' && (int) ($matcher['max_bytes'] ?? 0) <= 0) {
            $this->addFinding($findings, 'errors', $ruleCode, 'matcher.max_bytes', 'body_size matcher requires max_bytes greater than 0');
            $valid = false;
        }
        if ($type === 'security_headers') {
            $headers = $matcher['headers'] ?? [];
            if (!is_array($headers) || $headers === []) {
                $this->addFinding($findings, 'errors', $ruleCode, 'matcher.headers', 'security_headers matcher requires headers');
                $valid = false;
            }
        }
        if ($type === 'attack') {
            $categories = $this->stringListFrom($matcher['categories'] ?? []);
            $targets = $this->stringListFrom($matcher['targets'] ?? []);
            $allowedCategories = ['sqli', 'xss', 'command_injection', 'ssrf', 'path_traversal'];
            $allowedTargets = ['path', 'query', 'header', 'body'];
            if ($categories === [] || array_diff($categories, $allowedCategories) !== []) {
                $this->addFinding($findings, 'errors', $ruleCode, 'matcher.categories', 'attack matcher requires supported categories');
                $valid = false;
            }
            if ($targets === [] || array_diff($targets, $allowedTargets) !== []) {
                $this->addFinding($findings, 'errors', $ruleCode, 'matcher.targets', 'attack matcher requires supported targets');
                $valid = false;
            }
            if ((int) ($matcher['weight'] ?? 0) < 1 || (int) ($matcher['weight'] ?? 0) > 1000) {
                $this->addFinding($findings, 'errors', $ruleCode, 'matcher.weight', 'attack matcher weight must be between 1 and 1000');
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * @param list<array<string, mixed>> $highRisk
     */
    private function classifyHighRisk(string $ruleCode, array $matcher, string $action, array &$highRisk): void
    {
        $type = (string) ($matcher['type'] ?? '');
        $paths = $this->stringListFrom($matcher['paths'] ?? []);
        $values = $this->stringListFrom($matcher['values'] ?? []);
        if ($type === 'ip' && $this->stringListFrom($matcher['allow'] ?? []) !== [] && $this->touchesSensitivePaths($paths)) {
            $highRisk[] = ['rule_code' => $ruleCode, 'reason' => 'changes entry CIDR allow-listing for admin or API paths'];
        }
        if ($type === 'path' && $action === 'block' && $this->touchesSensitivePaths($values)) {
            $highRisk[] = ['rule_code' => $ruleCode, 'reason' => 'blocks sensitive admin, API, or runtime paths'];
        }
        if ($type === 'body_size' && (int) ($matcher['max_bytes'] ?? 0) < 1048576) {
            $highRisk[] = ['rule_code' => $ruleCode, 'reason' => 'sets request body limit below 1 MiB'];
        }
    }

    /**
     * @param list<string> $paths
     */
    private function touchesSensitivePaths(array $paths): bool
    {
        foreach ($paths as $path) {
            if (str_starts_with($path, '/admin') || str_starts_with($path, '/admin-api') || str_starts_with($path, '/v1') || str_contains($path, '.env') || str_contains($path, '.git')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function matcherFromRule(array $rule): array
    {
        $matcher = $rule['matcher'] ?? [];
        if (is_array($matcher)) {
            return $matcher;
        }
        if (is_string($matcher) && $matcher !== '') {
            $decoded = json_decode($matcher, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function stringListFrom(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param array<string, mixed> $findings
     */
    private function addFinding(array &$findings, string $type, ?string $ruleCode, string $field, string $message): void
    {
        $findings[$type][] = [
            'rule_code' => $ruleCode,
            'field' => $field,
            'message' => $message,
        ];
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function eventWhere(array $query): array
    {
        $conditions = [];
        $bindings = [];
        foreach (['rule_code', 'client_ip', 'action', 'method'] as $key) {
            if (!isset($query[$key]) || (string) $query[$key] === '') {
                continue;
            }
            $conditions[] = "{$key} = ?";
            $bindings[] = (string) $query[$key];
        }
        if (isset($query['path']) && (string) $query['path'] !== '') {
            $conditions[] = 'path LIKE ?';
            $bindings[] = '%' . (string) $query['path'] . '%';
        }
        if (isset($query['from']) && (string) $query['from'] !== '') {
            $conditions[] = 'created_at >= ?';
            $bindings[] = (string) $query['from'];
        }
        if (isset($query['to']) && (string) $query['to'] !== '') {
            $conditions[] = 'created_at <= ?';
            $bindings[] = (string) $query['to'];
        }

        return $conditions === [] ? ['', []] : [' WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    private function appendEventWhere(string $where, string $condition): string
    {
        return trim($where) === '' ? " WHERE {$condition}" : "{$where} AND {$condition}";
    }

    private function normalizeEventRow(array $row): array
    {
        if (isset($row['summary']) && is_string($row['summary']) && $row['summary'] !== '') {
            $decoded = json_decode($row['summary'], true);
            $row['summary'] = is_array($decoded) ? $decoded : $row['summary'];
        }
        if (array_key_exists('false_positive', $row)) {
            $row['false_positive'] = (int) $row['false_positive'];
        }
        if (array_key_exists('risk_score', $row)) {
            $row['risk_score'] = (int) $row['risk_score'];
        }
        return $row;
    }

    private function banIp(string $clientIp, string $reason, int $hitCount): void
    {
        App::db()->execute(
            'INSERT INTO system_ip_bans (client_ip, failure_count, reason, banned_at, last_failed_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                failure_count = GREATEST(failure_count, VALUES(failure_count)),
                reason = VALUES(reason),
                banned_at = COALESCE(banned_at, NOW()),
                last_failed_at = NOW()',
            [$clientIp, max(1, $hitCount), $reason]
        );
        App::redis()->delete((new LoginIpBanService())->cacheKey($clientIp));
    }

    private function riskScore(string $action, string $status): int
    {
        if ($status === 'false_positive' || $status === 'unbanned') {
            return 0;
        }
        if ($status === 'auto_banned' || $status === 'manual_banned') {
            return 100;
        }
        return $action === 'block' ? 70 : 30;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{threshold:int,window_minutes:int}
     */
    private function autoDispositionOptions(array $options): array
    {
        try {
            $settings = (new SystemSettingsService())->loadSection('alert_thresholds');
        } catch (\Throwable) {
            $settings = (new SystemSettingsService())->defaults()['alert_thresholds'];
        }

        return [
            'threshold' => (int) ($options['threshold'] ?? $settings['waf_spike_count'] ?? 10),
            'window_minutes' => (int) ($options['window_minutes'] ?? $settings['waf_spike_window_minutes'] ?? 5),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function integerRows(array $rows, array $columns): array
    {
        return array_map(static function (array $row) use ($columns): array {
            foreach ($columns as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = (int) $row[$column];
                }
            }
            return $row;
        }, $rows);
    }

    /**
     * @return array{ok: bool, output: string}
     */
    private function runCommand(string $command): array
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        return ['ok' => $code === 0, 'output' => implode("\n", $output)];
    }

    /**
     * @return list<string>
     */
    private function lineDiff(string $base, string $current): array
    {
        $baseLines = explode("\n", $base);
        $currentLines = explode("\n", $current);
        $diff = [];
        foreach (array_diff($baseLines, $currentLines) as $line) {
            if ($line !== '') {
                $diff[] = '- ' . $line;
            }
        }
        foreach (array_diff($currentLines, $baseLines) as $line) {
            if ($line !== '') {
                $diff[] = '+ ' . $line;
            }
        }
        return $diff;
    }
}
