<?php
declare(strict_types=1);

/**
 * TokenHub 后台演示数据（按实际表结构自适应写入）
 *
 * 脚本运行时读取 information_schema，只写存在的表和列；必填列自动补值，外键自动关联，
 * 枚举值自动匹配。所有写入的行记在 demo_seed_ledger 表里，可以一键清理。
 *
 * 用法（在 php 容器里执行）：
 *   php seed-demo-data.php             演练：真实写入后整体回滚，只打印结果，不留任何数据
 *   php seed-demo-data.php --apply     正式写入（会先清掉上一次写入的演示数据）
 *   php seed-demo-data.php --purge     只清理演示数据
 *   php seed-demo-data.php --inspect   打印相关表结构（出错时把输出发给 Claude）
 *
 * 选项：
 *   --days=30          调用记录覆盖的天数
 *   --per-day=120      工作日平均每天请求数（周末约 1/3）
 *   --only=org,catalog,usage,rag,alerts   只写其中几块
 *   --currency=CNY     费用币种（默认读 BASE_CURRENCY / BILLING_CURRENCY，否则 CNY）
 *
 * 数据库连接读取环境变量 DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD
 * （也认 DB_NAME、DB_USER、DB_PASS、MYSQL_*），找不到时再读项目 .env。
 */

if (PHP_SAPI !== 'cli') {
    exit("只能在命令行运行\n");
}

final class DemoSeedAlt
{
    /** @param array{str:string,int:int|float} $v */
    public function __construct(public array $v) {}
}

function ds_alt(string $s, int|float $i): DemoSeedAlt
{
    return new DemoSeedAlt(['str' => $s, 'int' => $i]);
}

/** 状态值：字符串列写 $s，整数列写 1/0 */
function ds_st(string $s): DemoSeedAlt
{
    $on = ['active', 'enabled', 'enable', 'normal', 'success', 'succeeded', 'open', 'online', 'ok', 'confirmed', 'completed', 'ready', 'indexed', 'published'];
    return ds_alt($s, in_array($s, $on, true) ? 1 : 0);
}

final class DemoSeedError extends RuntimeException {}

final class DemoSeeder
{
    public const SKIP = "\0__skip__";

    public PDO $db;
    public bool $apply = false;
    public array $opts = [];
    public DateTimeImmutable $now;
    public string $currency = 'CNY';
    public string $runId;

    private array $tables = [];
    private array $colCache = [];
    private array $fkCache = [];
    private array $uqCache = [];
    private array $stmtCache = [];
    private bool $aliasUpsert = true;
    private array $placeholderBlacklist = ['admin_users', 'admin_sessions', 'users'];

    /** @var array<string, list<int|string>> */
    public array $created = [];
    /** @var list<array{0:string,1:string,2:?string,3:mixed,4:?array}> */
    public array $ledger = [];
    public array $stats = [];
    public array $warnings = [];
    public array $notes = [];
    public array $credentials = [];

    public function __construct(PDO $db, array $opts)
    {
        $this->db = $db;
        $this->opts = $opts;
        $this->apply = isset($opts['apply']);
        $tz = getenv('APP_TIMEZONE') ?: (getenv('TZ') ?: date_default_timezone_get());
        try {
            date_default_timezone_set($tz);
        } catch (Throwable) {
        }
        $this->now = new DateTimeImmutable('now');
        $this->currency = (string)($opts['currency'] ?? (getenv('BASE_CURRENCY') ?: (getenv('BILLING_CURRENCY') ?: 'CNY')));
        $this->runId = 'demo-' . $this->now->format('YmdHis');
        foreach ($db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $this->tables[strtolower((string)$t)] = (string)$t;
        }
        $ver = (string)$db->query('SELECT VERSION()')->fetchColumn();
        $this->aliasUpsert = !str_contains(strtolower($ver), 'mariadb') && version_compare(preg_replace('/[^0-9.].*$/', '', $ver), '8.0.19', '>=');
    }

    // ---------------------------------------------------------------- 表结构

    public function has(string $t): bool
    {
        return isset($this->tables[strtolower($t)]);
    }

    /** 候选表名中第一个存在的 */
    public function pick(array $cands): ?string
    {
        foreach ($cands as $c) {
            if ($this->has($c)) {
                return $this->tables[strtolower($c)];
            }
        }
        return null;
    }

    /** @return list<string> */
    public function like(string $regex): array
    {
        $out = [];
        foreach ($this->tables as $t) {
            if (preg_match($regex, $t)) {
                $out[] = $t;
            }
        }
        sort($out);
        return $out;
    }

    public function cols(string $t): array
    {
        if (!isset($this->colCache[$t])) {
            $st = $this->db->prepare('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, CHARACTER_MAXIMUM_LENGTH
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            $st->execute([$t]);
            $cols = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r = array_change_key_case($r, CASE_UPPER);
                $r['DATA_TYPE'] = strtolower((string)$r['DATA_TYPE']);
                $r['EXTRA'] = strtolower((string)$r['EXTRA']);
                $cols[(string)$r['COLUMN_NAME']] = $r;
            }
            $this->colCache[$t] = $cols;
        }
        return $this->colCache[$t];
    }

    public function hasCol(string $t, string $c): bool
    {
        return isset($this->cols($t)[$c]);
    }

    /** @return array<string, array{0:string,1:string}> 列 => [被引用表, 被引用列] */
    public function fks(string $t): array
    {
        if (!isset($this->fkCache[$t])) {
            $st = $this->db->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL');
            $st->execute([$t]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$c, $rt, $rc]) {
                $out[(string)$c] = [(string)$rt, (string)$rc];
            }
            $this->fkCache[$t] = $out;
        }
        return $this->fkCache[$t];
    }

    /** @return array<string, list<string>> 索引名 => 列（含 PRIMARY） */
    public function uniques(string $t): array
    {
        if (!isset($this->uqCache[$t])) {
            $st = $this->db->prepare('SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX');
            $st->execute([$t]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$i, $c]) {
                $out[(string)$i][] = (string)$c;
            }
            $this->uqCache[$t] = $out;
        }
        return $this->uqCache[$t];
    }

    public function pk(string $t): ?string
    {
        $u = $this->uniques($t);
        return isset($u['PRIMARY']) && count($u['PRIMARY']) === 1 ? $u['PRIMARY'][0] : null;
    }

    private static function isInt(string $type): bool
    {
        return in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'bit'], true);
    }

    private static function isNum(string $type): bool
    {
        return self::isInt($type) || in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true);
    }

    private static function isStr(string $type): bool
    {
        return in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true);
    }

    private static function isGenerated(array $c): bool
    {
        return str_contains($c['EXTRA'], 'virtual generated') || str_contains($c['EXTRA'], 'stored generated');
    }

    private static function required(array $c): bool
    {
        return $c['IS_NULLABLE'] === 'NO' && $c['COLUMN_DEFAULT'] === null && !str_contains($c['EXTRA'], 'auto_increment');
    }

    public static function enumOpts(string $columnType): array
    {
        if (!preg_match('/^(?:enum|set)\((.*)\)$/i', $columnType, $m)) {
            return [];
        }
        preg_match_all("/'((?:[^']|'')*)'/", $m[1], $mm);
        return array_map(static fn($s) => str_replace("''", "'", $s), $mm[1]);
    }

    private const SYNONYMS = [
        ['active', 'enabled', 'enable', 'normal', 'on', 'online', 'ok', 'available', 'healthy', 'running', 'valid'],
        ['disabled', 'inactive', 'off', 'suspended', 'paused', 'stopped', 'revoked', 'blocked'],
        ['expired', 'disabled', 'inactive'],
        ['success', 'succeeded', 'ok', 'completed', 'complete', 'done', 'finished', 'confirmed', 'indexed', 'ready', 'published', 'active'],
        ['failed', 'failure', 'error', 'errored', 'rejected'],
        ['open', 'firing', 'active', 'triggered', 'new', 'pending', 'unresolved', 'alerting'],
        ['acknowledged', 'ack', 'acked', 'processing', 'investigating', 'handling', 'in_progress'],
        ['resolved', 'closed', 'recovered', 'done', 'fixed'],
        ['silenced', 'muted', 'suppressed', 'ignored', 'snoozed'],
        ['critical', 'p0', 'fatal', 'emergency', 'high', 'error', 'severe'],
        ['warning', 'warn', 'medium', 'p1', 'major'],
        ['info', 'low', 'notice', 'p2', 'minor', 'information'],
        ['department', 'dept', 'org', 'organization', 'group'],
        ['shared', 'public', 'global', 'all', 'organization'],
        ['private', 'owner', 'personal', 'self'],
        ['read', 'reader', 'viewer', 'view', 'readonly'],
        ['member', 'user', 'normal', 'staff', 'employee'],
        ['openai', 'openai_compatible', 'openai-compatible', 'oai'],
        ['text_generation', 'chat', 'text', 'llm', 'completion'],
    ];

    public function enumPick(array $opts, string $v, string $where): string
    {
        if ($opts === [] || in_array($v, $opts, true)) {
            return $v;
        }
        $lower = array_map('strtolower', $opts);
        $i = array_search(strtolower($v), $lower, true);
        if ($i !== false) {
            return $opts[$i];
        }
        foreach (self::SYNONYMS as $group) {
            if (in_array(strtolower($v), $group, true)) {
                foreach ($group as $g) {
                    $i = array_search($g, $lower, true);
                    if ($i !== false) {
                        return $opts[$i];
                    }
                }
            }
        }
        $pick = $this->enumDefault($opts);
        $this->warn("{$where}：枚举里没有「{$v}」，改用「{$pick}」");
        return $pick;
    }

    public function enumDefault(array $opts): string
    {
        $lower = array_map('strtolower', $opts);
        foreach (['active', 'enabled', 'normal', 'success', 'succeeded', 'open', 'info', 'text', 'text_generation', 'openai', 'read', 'member', 'department'] as $p) {
            $i = array_search($p, $lower, true);
            if ($i !== false) {
                return $opts[$i];
            }
        }
        return $opts[0] ?? '';
    }

    public function warn(string $msg): void
    {
        $this->warnings[$msg] = ($this->warnings[$msg] ?? 0) + 1;
    }

    // ---------------------------------------------------------------- 取值

    private function coerce(string $t, array $c, mixed $v): mixed
    {
        $name = $c['COLUMN_NAME'];
        $type = $c['DATA_TYPE'];
        if ($v instanceof DemoSeedAlt) {
            $v = self::isNum($type) ? $v->v['int'] : $v->v['str'];
        }
        if ($v instanceof DateTimeInterface) {
            if (self::isInt($type)) {
                return $v->getTimestamp();
            }
            $v = $v->format('Y-m-d H:i:s');
        }
        if (is_bool($v)) {
            $v = $v ? 1 : 0;
        }
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (in_array($type, ['enum', 'set'], true)) {
            return $this->enumPick(self::enumOpts($c['COLUMN_TYPE']), (string)$v, "{$t}.{$name}");
        }
        if (self::isNum($type)) {
            if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                return $v;
            }
            if (is_string($v) && preg_match('/^(status|state|enabled|active|is_|has_|stream)/', $name)) {
                $on = ['active', 'enabled', 'success', 'succeeded', 'open', 'true', 'yes', 'on', 'normal', 'ok', 'confirmed', 'completed'];
                return in_array(strtolower($v), $on, true) ? 1 : 0;
            }
            return self::SKIP;
        }
        if ($type === 'date') {
            return substr((string)$v, 0, 10);
        }
        if ($type === 'json') {
            $s = (string)$v;
            json_decode($s);
            return json_last_error() === JSON_ERROR_NONE ? $s : json_encode($s, JSON_UNESCAPED_UNICODE);
        }
        if (self::isStr($type) && $c['CHARACTER_MAXIMUM_LENGTH'] !== null) {
            $max = (int)$c['CHARACTER_MAXIMUM_LENGTH'];
            $s = (string)$v;
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
        }
        return $v;
    }

    private function fallback(string $t, array $c, int $depth): mixed
    {
        $n = $c['COLUMN_NAME'];
        $type = $c['DATA_TYPE'];
        $fks = $this->fks($t);
        if (isset($fks[$n])) {
            $id = $this->pickRef($fks[$n][0], $fks[$n][1], $depth);
            return $id ?? self::SKIP;
        }
        if (self::isInt($type) && preg_match('/(_id|_by)$/', $n)) {
            $ref = $this->guessRefTable($n);
            if ($ref !== null && $ref !== $t) {
                $id = $this->pickRef($ref, null, $depth);
                if ($id !== null) {
                    return $id;
                }
            }
            return 0;
        }
        if (in_array($type, ['enum', 'set'], true)) {
            return $this->enumDefault(self::enumOpts($c['COLUMN_TYPE']));
        }
        if (self::isInt($type)) {
            return preg_match('/^(is_|enabled|active|status)/', $n) ? 1 : 0;
        }
        if (self::isNum($type)) {
            return 0;
        }
        if ($type === 'date') {
            return $this->now->format('Y-m-d');
        }
        if (in_array($type, ['datetime', 'timestamp'], true)) {
            return $this->now->format('Y-m-d H:i:s');
        }
        if ($type === 'time') {
            return '00:00:00';
        }
        if ($type === 'year') {
            return (int)$this->now->format('Y');
        }
        if ($type === 'json') {
            return preg_match('/(s|list|ids|tags|scopes|models|items)$/', $n) ? '[]' : '{}';
        }
        if (in_array($type, ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'], true)) {
            return random_bytes(max(1, min(16, (int)($c['CHARACTER_MAXIMUM_LENGTH'] ?? 16))));
        }
        $max = (int)($c['CHARACTER_MAXIMUM_LENGTH'] ?? 255);
        $rand = bin2hex(random_bytes(8));
        if ($n === $this->pk($t) || str_contains($n, 'uuid')) {
            $s = self::uuid();
        } elseif (preg_match('/hash|sha|digest|fingerprint|checksum/', $n)) {
            $s = hash('sha256', $rand);
        } elseif (str_contains($n, 'email')) {
            $s = "demo.{$rand}@example.com";
        } elseif (preg_match('/url|endpoint/', $n)) {
            $s = 'https://demo.invalid/';
        } elseif (preg_match('/(^|_)ip$/', $n)) {
            $s = '10.0.0.1';
        } elseif (str_contains($n, 'currency')) {
            $s = $this->currency;
        } elseif (preg_match('/code|slug|key|token|identifier/', $n)) {
            $s = 'demo-' . $rand;
        } elseif (preg_match('/name|title|label/', $n)) {
            $s = '演示-' . substr($rand, 0, 6);
        } else {
            $s = 'demo';
        }
        return mb_substr($s, 0, max(1, $max));
    }

    private function guessRefTable(string $n): ?string
    {
        if (in_array($n, ['created_by', 'updated_by', 'deleted_by', 'operator_id', 'admin_id', 'admin_user_id', 'reviewed_by', 'confirmed_by'], true)) {
            return $this->pick(['admin_users']);
        }
        if (!str_ends_with($n, '_id')) {
            return null;
        }
        $base = substr($n, 0, -3);
        $base = preg_replace('/^(owner|parent|target|source|subject|uploader|owner_user)_?/', '', $base) ?: $base;
        if ($base === '' || $base === 'owner_user') {
            $base = 'user';
        }
        $c = [$base . 's', $base . 'es', preg_replace('/y$/', 'ies', $base), $base, 'rag_' . $base . 's', 'rag_' . $base . 'es'];
        if ($base === 'kb' || $base === 'knowledge_base') {
            array_unshift($c, 'rag_knowledge_bases', 'knowledge_bases');
        }
        if ($base === 'dataset') {
            array_unshift($c, 'rag_vector_datasets', 'rag_datasets');
        }
        return $this->pick($c);
    }

    private function pickRef(string $table, ?string $col, int $depth): mixed
    {
        if (!empty($this->created[$table]) && ($col === null || $col === $this->pk($table))) {
            $ids = $this->created[$table];
            return $ids[array_rand($ids)];
        }
        $col ??= $this->pk($table) ?? 'id';
        if (!$this->hasCol($table, $col)) {
            return null;
        }
        $v = $this->db->query("SELECT `{$col}` FROM `{$table}` ORDER BY `{$col}` LIMIT 1")->fetchColumn();
        if ($v !== false && $v !== null) {
            return $v;
        }
        if ($depth < 2 && !in_array(strtolower($table), $this->placeholderBlacklist, true)) {
            $this->warn("{$table}：为满足外键自动建了一条占位记录");
            return $this->insert($table, ['name' => '演示占位', 'title' => '演示占位'], $depth + 1);
        }
        return null;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ---------------------------------------------------------------- 写入

    /** 按语义字典组装一行：只取存在的列，必填列补值 */
    private function buildRow(string $t, array $sem, int $depth): array
    {
        $row = [];
        foreach ($this->cols($t) as $name => $c) {
            if (self::isGenerated($c)) {
                continue;
            }
            $auto = str_contains($c['EXTRA'], 'auto_increment');
            if (array_key_exists($name, $sem)) {
                $v = $sem[$name];
                if ($v !== null) {
                    $v = $this->coerce($t, $c, $v);
                }
                if ($v === null) {
                    if ($c['IS_NULLABLE'] === 'YES') {
                        $row[$name] = null;
                        continue;
                    }
                    if (!self::required($c)) {
                        continue;
                    }
                    $v = $this->fallback($t, $c, $depth);
                } elseif ($v === self::SKIP) {
                    if (!self::required($c)) {
                        continue;
                    }
                    $v = $this->fallback($t, $c, $depth);
                }
                if ($v !== self::SKIP) {
                    $row[$name] = $v;
                }
                continue;
            }
            if ($auto) {
                continue;
            }
            if (in_array($name, ['created_at', 'updated_at'], true) && in_array($c['DATA_TYPE'], ['datetime', 'timestamp', 'int', 'bigint'], true)) {
                $row[$name] = $this->coerce($t, $c, $sem['@at'] ?? $this->now);
                continue;
            }
            if (self::required($c)) {
                $v = $this->fallback($t, $c, $depth);
                if ($v !== self::SKIP) {
                    $row[$name] = $v;
                }
            }
        }
        return $row;
    }

    /** 唯一键冲突时给字符串列加后缀 */
    private function dedupe(string $t, array $row): array
    {
        foreach ($this->uniques($t) as $idx => $cols) {
            if ($idx === 'PRIMARY') {
                continue;
            }
            foreach ($cols as $c) {
                if (!array_key_exists($c, $row) || $row[$c] === null) {
                    continue 2;
                }
            }
            for ($try = 0; $try < 3; $try++) {
                $where = implode(' AND ', array_map(static fn($c) => "`{$c}` = ?", $cols));
                $st = $this->db->prepare("SELECT 1 FROM `{$t}` WHERE {$where} LIMIT 1");
                $st->execute(array_map(static fn($c) => $row[$c], $cols));
                if ($st->fetchColumn() === false) {
                    break;
                }
                $fixed = false;
                foreach ($cols as $c) {
                    $info = $this->cols($t)[$c];
                    if (self::isStr($info['DATA_TYPE'])) {
                        $suffix = '-' . substr(bin2hex(random_bytes(3)), 0, 4);
                        $max = (int)($info['CHARACTER_MAXIMUM_LENGTH'] ?? 255);
                        $row[$c] = mb_substr((string)$row[$c], 0, max(1, $max - mb_strlen($suffix))) . $suffix;
                        $fixed = true;
                        break;
                    }
                }
                if (!$fixed) {
                    break;
                }
            }
        }
        return $row;
    }

    private function exec(string $sql, array $vals, string $t, array $row): PDOStatement
    {
        $st = $this->stmtCache[$sql] ??= $this->db->prepare($sql);
        try {
            $st->execute($vals);
        } catch (PDOException $e) {
            $shown = [];
            foreach ($row as $k => $v) {
                $s = is_string($v) ? $v : var_export($v, true);
                $shown[] = $k . '=' . (mb_strlen($s) > 40 ? mb_substr($s, 0, 40) . '…' : $s);
            }
            throw new DemoSeedError("写入 {$t} 失败：" . $e->getMessage() . "\n    行：" . implode(', ', $shown), 0, $e);
        }
        return $st;
    }

    public function insert(string $t, array $sem, int $depth = 0): mixed
    {
        $row = $this->dedupe($t, $this->buildRow($t, $sem, $depth));
        $keys = array_keys($row);
        $sql = $keys === []
            ? "INSERT INTO `{$t}` () VALUES ()"
            : "INSERT INTO `{$t}` (`" . implode('`, `', $keys) . '`) VALUES (' . implode(', ', array_fill(0, count($keys), '?')) . ')';
        $this->exec($sql, array_values($row), $t, $row);
        $pk = $this->pk($t);
        $id = ($pk !== null && array_key_exists($pk, $row)) ? $row[$pk] : $this->db->lastInsertId();
        if ($id === '0' || $id === false || $id === '') {
            $id = null;
        }
        if (is_string($id) && ctype_digit($id)) {
            $id = (int)$id;
        }
        if ($pk !== null && $id !== null) {
            $this->created[$t][] = $id;
            $this->ledger[] = ['ins', $t, $pk, $id, null];
        } else {
            $this->ledger[] = ['row', $t, null, null, $row];
        }
        $this->stats[$t] = ($this->stats[$t] ?? 0) + 1;
        return $id;
    }

    public function update(string $t, mixed $id, array $sem): void
    {
        $pk = $this->pk($t);
        if ($pk === null || $id === null) {
            return;
        }
        $set = [];
        $vals = [];
        foreach ($sem as $k => $v) {
            if (!$this->hasCol($t, $k)) {
                continue;
            }
            $v = $v === null ? null : $this->coerce($t, $this->cols($t)[$k], $v);
            if ($v === self::SKIP) {
                continue;
            }
            $set[] = "`{$k}` = ?";
            $vals[] = $v;
        }
        if ($set === []) {
            return;
        }
        $vals[] = $id;
        $this->exec("UPDATE `{$t}` SET " . implode(', ', $set) . " WHERE `{$pk}` = ?", $vals, $t, $sem);
    }

    /** 汇总表：主键/唯一键冲突时把指标列累加 */
    public function upsertAdd(string $t, array $sem, array $metricCols): void
    {
        $row = $this->buildRow($t, $sem, 0);
        $metrics = array_values(array_filter($metricCols, static fn($m) => array_key_exists($m, $row) && is_numeric($row[$m])));
        $keys = array_keys($row);
        $ph = implode(', ', array_fill(0, count($keys), '?'));
        $colsSql = '`' . implode('`, `', $keys) . '`';
        if ($metrics === []) {
            $sql = "INSERT IGNORE INTO `{$t}` ({$colsSql}) VALUES ({$ph})";
        } elseif ($this->aliasUpsert) {
            $upd = implode(', ', array_map(static fn($m) => "`{$m}` = `{$t}`.`{$m}` + new_row.`{$m}`", $metrics));
            $sql = "INSERT INTO `{$t}` ({$colsSql}) VALUES ({$ph}) AS new_row ON DUPLICATE KEY UPDATE {$upd}";
        } else {
            $upd = implode(', ', array_map(static fn($m) => "`{$m}` = `{$m}` + VALUES(`{$m}`)", $metrics));
            $sql = "INSERT INTO `{$t}` ({$colsSql}) VALUES ({$ph}) ON DUPLICATE KEY UPDATE {$upd}";
        }
        $st = $this->exec($sql, array_values($row), $t, $row);
        $n = $st->rowCount();
        if ($n === 1) {
            $pk = $this->pk($t);
            $id = ($pk !== null && array_key_exists($pk, $row)) ? $row[$pk] : $this->db->lastInsertId();
            if ($pk !== null && $id) {
                $this->ledger[] = ['ins', $t, $pk, $id, null];
            } else {
                $this->ledger[] = ['row', $t, null, null, $row];
            }
            $this->stats[$t] = ($this->stats[$t] ?? 0) + 1;
        } elseif ($n === 2) {
            // 累加到了已有行上：记下唯一键和增量，清理时减回去
            foreach ($this->uniques($t) as $cols) {
                if (array_diff($cols, array_keys($row)) === []) {
                    $where = [];
                    foreach ($cols as $c) {
                        $where[$c] = $row[$c];
                    }
                    $delta = [];
                    foreach ($metrics as $m) {
                        $delta[$m] = $row[$m];
                    }
                    $this->ledger[] = ['acc', $t, null, null, ['where' => $where, 'delta' => $delta]];
                    break;
                }
            }
            $this->stats[$t . '（累加）'] = ($this->stats[$t . '（累加）'] ?? 0) + 1;
        }
    }

    // ---------------------------------------------------------------- 台账

    public function ensureLedgerTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS `demo_seed_ledger` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `run_id` VARCHAR(40) NOT NULL,
            `action` VARCHAR(8) NOT NULL,
            `tbl` VARCHAR(128) NOT NULL,
            `pk_col` VARCHAR(64) NULL,
            `row_id` VARCHAR(64) NULL,
            `payload` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->tables['demo_seed_ledger'] = 'demo_seed_ledger';
    }

    public function writeLedger(): void
    {
        $st = $this->db->prepare('INSERT INTO `demo_seed_ledger` (`run_id`, `action`, `tbl`, `pk_col`, `row_id`, `payload`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($this->ledger as [$action, $t, $pk, $id, $payload]) {
            $st->execute([$this->runId, $action, $t, $pk, $id === null ? null : (string)$id,
                $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $this->now->format('Y-m-d H:i:s')]);
        }
    }

    public function purge(): array
    {
        if (!$this->has('demo_seed_ledger')) {
            return [0, 0];
        }
        $rows = $this->db->query('SELECT * FROM `demo_seed_ledger` ORDER BY `id` DESC')->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [0, 0];
        }
        $done = 0;
        $failed = [];
        $run = function (array $r) use (&$done): void {
            $t = (string)$r['tbl'];
            if (!$this->has($t)) {
                return;
            }
            $payload = $r['payload'] !== null ? json_decode((string)$r['payload'], true) : null;
            if ($r['action'] === 'ins') {
                $st = $this->db->prepare("DELETE FROM `{$t}` WHERE `{$r['pk_col']}` = ?");
                $st->execute([$r['row_id']]);
            } elseif ($r['action'] === 'row' && is_array($payload) && $payload !== []) {
                $where = [];
                $vals = [];
                foreach ($payload as $k => $v) {
                    if ($v === null) {
                        $where[] = "`{$k}` IS NULL";
                    } else {
                        $where[] = "`{$k}` = ?";
                        $vals[] = $v;
                    }
                }
                $st = $this->db->prepare("DELETE FROM `{$t}` WHERE " . implode(' AND ', $where) . ' LIMIT 1');
                $st->execute($vals);
            } elseif ($r['action'] === 'acc' && is_array($payload)) {
                $set = [];
                $vals = [];
                foreach ($payload['delta'] as $m => $d) {
                    $set[] = "`{$m}` = GREATEST(`{$m}` - ?, 0)";
                    $vals[] = $d;
                }
                $where = [];
                foreach ($payload['where'] as $c => $v) {
                    $where[] = "`{$c}` = ?";
                    $vals[] = $v;
                }
                if ($set !== []) {
                    $st = $this->db->prepare("UPDATE `{$t}` SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $where));
                    $st->execute($vals);
                }
            }
            $done++;
        };
        foreach ($rows as $r) {
            try {
                $run($r);
            } catch (PDOException) {
                $failed[] = $r;
            }
        }
        if ($failed !== []) {
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($failed as $r) {
                    $run($r);
                }
            } finally {
                $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        $this->db->exec('DELETE FROM `demo_seed_ledger`');
        return [$done, count($failed)];
    }

    // ---------------------------------------------------------------- 应用自身的加密 / 哈希（可选）

    private ?bool $appLoaded = null;

    private function loadApp(): bool
    {
        if ($this->appLoaded !== null) {
            return $this->appLoaded;
        }
        $this->appLoaded = false;
        foreach (array_unique([dirname(__DIR__), getcwd() ?: '.', '/var/www/html', '/app', '/srv/tokenhub']) as $base) {
            $auto = $base . '/vendor/autoload.php';
            if (is_file($auto)) {
                try {
                    require_once $auto;
                    foreach (['App\\Support\\Config', 'App\\Config', 'App\\Core\\Config'] as $cfg) {
                        if (class_exists($cfg) && method_exists($cfg, 'loadEnv')) {
                            try {
                                $cfg::loadEnv($base);
                            } catch (Throwable) {
                            }
                        }
                    }
                    $this->appLoaded = true;
                } catch (Throwable) {
                }
                break;
            }
        }
        return $this->appLoaded;
    }

    /** 尝试用应用的 Crypto 加密渠道密钥，失败返回 null */
    public function appEncrypt(string $plain): ?string
    {
        if (!$this->loadApp()) {
            return null;
        }
        foreach (['TokenHub\\Support\\Crypto', 'App\\Support\\Crypto', 'App\\Services\\Crypto', 'App\\Security\\Crypto', 'App\\Services\\CryptoService', 'App\\Support\\Encryption'] as $cls) {
            if (!class_exists($cls)) {
                continue;
            }
            foreach (['encrypt', 'encryptString', 'seal'] as $m) {
                if (!method_exists($cls, $m)) {
                    continue;
                }
                try {
                    $rm = new ReflectionMethod($cls, $m);
                    $out = $rm->isStatic() ? $cls::$m($plain) : (new $cls())->$m($plain);
                    if (is_string($out) && $out !== '' && $out !== $plain) {
                        return $out;
                    }
                } catch (Throwable) {
                }
            }
        }
        return null;
    }

    public function apiKeyHash(string $plain): string
    {
        if ($this->loadApp()) {
            foreach (['TokenHub\\Support\\Crypto', 'App\\Services\\ApiKeyService', 'App\\Support\\Crypto', 'App\\Support\\ApiKeyHasher', 'App\\Services\\ApiKeyHasher'] as $cls) {
                if (!class_exists($cls)) {
                    continue;
                }
                foreach (['hashKey', 'hash', 'hashApiKey', 'keyHash'] as $m) {
                    if (!method_exists($cls, $m)) {
                        continue;
                    }
                    try {
                        $rm = new ReflectionMethod($cls, $m);
                        if ($rm->isStatic() && $rm->getNumberOfRequiredParameters() === 1) {
                            $out = $cls::$m($plain);
                            if (is_string($out) && $out !== '') {
                                return $out;
                            }
                        }
                    } catch (Throwable) {
                    }
                }
            }
        }
        $key = (string)(getenv('API_KEY_HASH_KEY') ?: (getenv('APP_HASH_KEY') ?: (getenv('APP_KEY') ?: 'tokenhub-demo')));
        if (str_starts_with($key, 'base64:')) {
            $key = (string)base64_decode(substr($key, 7), true);
        }
        return hash_hmac('sha256', $plain, $key);
    }
}

// ==================================================================== 工具

function ds_env_load(): array
{
    $file = [];
    foreach (array_unique([dirname(__DIR__) . '/.env', (getcwd() ?: '.') . '/.env', '/var/www/html/.env', '/app/.env', '/srv/tokenhub/.env']) as $f) {
        if (!is_readable($f)) {
            continue;
        }
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim(preg_replace('/^export\s+/', '', $k));
            $v = trim($v);
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            $file[$k] ??= $v;
        }
        break;
    }
    return $file;
}

function ds_env_first(array $file, array $names, ?string $default = null): ?string
{
    foreach ($names as $n) {
        $v = getenv($n);
        if ($v !== false && $v !== '') {
            return $v;
        }
    }
    foreach ($names as $n) {
        if (isset($file[$n]) && $file[$n] !== '') {
            return $file[$n];
        }
    }
    return $default;
}

function ds_gauss(): float
{
    $u = max(1e-9, mt_rand() / mt_getrandmax());
    $v = mt_rand() / mt_getrandmax();
    return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
}

function ds_lognorm(float $median, float $sigma, int $min, int $max): int
{
    return (int)max($min, min($max, round($median * exp($sigma * ds_gauss()))));
}

/** @param array<mixed,float|int> $weights */
function ds_wpick(array $weights): int|string
{
    $sum = array_sum($weights);
    $r = mt_rand() / mt_getrandmax() * $sum;
    foreach ($weights as $k => $w) {
        $r -= $w;
        if ($r <= 0) {
            return $k;
        }
    }
    return array_key_last($weights);
}

function ds_line(string $s = ''): void
{
    fwrite(STDOUT, $s . "\n");
}

// ==================================================================== 各模块

function ds_seed_org(DemoSeeder $s, array &$ctx): void
{
    $deptT = $s->pick(['departments', 'depts', 'organizations', 'org_departments']);
    if ($deptT === null) {
        $s->notes[] = '没有找到部门表，跳过部门';
    } else {
        // 推断 path 列的格式
        $pathFmt = '/%s/';
        $sep = '/';
        if ($s->hasCol($deptT, 'path')) {
            $sample = $s->db->query("SELECT `path` FROM `{$deptT}` WHERE `path` IS NOT NULL AND `path` <> '' LIMIT 1")->fetchColumn();
            if (is_string($sample)) {
                if (preg_match('/^\d+(,\d+)*,?$/', $sample)) {
                    $sep = ',';
                    $pathFmt = '%s';
                } elseif (preg_match('/^\d+(\.\d+)*$/', $sample)) {
                    $sep = '.';
                    $pathFmt = '%s';
                } elseif (preg_match('/^\d+(\/\d+)*$/', $sample)) {
                    $pathFmt = '%s';
                }
            }
        }
        $tree = [
            ['DEMO', '星河科技（演示）', null, '演示集团总部'],
            ['DEMO-RD', '技术研发中心', 'DEMO', '负责平台与模型应用研发'],
            ['DEMO-RD-PLAT', '平台工程部', 'DEMO-RD', '网关、基础设施与 SRE'],
            ['DEMO-RD-AI', '算法研究院', 'DEMO-RD', '大模型评测与 RAG'],
            ['DEMO-OPS', '产品运营部', 'DEMO', '产品与内容运营'],
            ['DEMO-MKT', '市场销售部', 'DEMO', '品牌、市场与销售'],
            ['DEMO-MKT-EAST', '华东大区', 'DEMO-MKT', '华东区域销售'],
            ['DEMO-CS', '客户服务中心', 'DEMO', '智能客服与工单'],
        ];
        $ids = [];
        $paths = [];
        $levels = [];
        $order = 0;
        foreach ($tree as [$code, $name, $parent, $desc]) {
            $order++;
            $pid = $parent ? $ids[$parent] : null;
            $level = $parent ? $levels[$parent] + 1 : 1;
            $id = $s->insert($deptT, [
                'name' => $name, 'title' => $name, 'code' => $code, 'slug' => strtolower($code),
                'parent_id' => $pid, 'status' => ds_st('active'), 'enabled' => 1, 'is_active' => 1, 'is_enabled' => 1,
                'sort' => $order, 'sort_order' => $order, 'position' => $order,
                'level' => $level, 'depth' => $level, 'description' => $desc, 'remark' => '演示数据',
            ]);
            $ids[$code] = $id;
            $levels[$code] = $level;
            $paths[$code] = ($parent ? $paths[$parent] . $sep : '') . $id;
            if ($s->hasCol($deptT, 'path')) {
                $s->update($deptT, $id, ['path' => sprintf($pathFmt, $paths[$code])]);
            }
        }
        $ctx['dept'] = $ids;
        $ctx['deptTable'] = $deptT;
    }

    $userT = $s->pick(['users', 'members', 'app_users', 'employees']);
    if ($userT === null) {
        $s->notes[] = '没有找到用户表，跳过用户';
    } else {
        $pwd = password_hash('Demo@123456', PASSWORD_DEFAULT);
        $people = [
            ['zhangwei', '张伟', 'DEMO-RD-PLAT', 'manager'], ['lina', '李娜', 'DEMO-RD-AI', 'member'],
            ['wangfang', '王芳', 'DEMO-OPS', 'manager'], ['liuyang', '刘洋', 'DEMO-RD-PLAT', 'member'],
            ['chenjing', '陈静', 'DEMO-CS', 'manager'], ['yangfan', '杨帆', 'DEMO-MKT-EAST', 'member'],
            ['zhaolei', '赵磊', 'DEMO-RD-AI', 'member'], ['huangli', '黄丽', 'DEMO-MKT', 'manager'],
            ['sunhao', '孙浩', 'DEMO-CS', 'member'], ['wumin', '吴敏', 'DEMO-OPS', 'member'],
        ];
        $ctx['users'] = [];
        foreach ($people as $i => [$login, $name, $dept, $role]) {
            $deptId = $ctx['dept'][$dept] ?? null;
            $id = $s->insert($userT, [
                'name' => $name, 'username' => 'demo.' . $login, 'login' => 'demo.' . $login, 'account' => 'demo.' . $login,
                'display_name' => $name, 'real_name' => $name, 'nickname' => $name, 'full_name' => $name,
                'email' => "demo.{$login}@example.com", 'phone' => sprintf('1380000%04d', $i + 1), 'mobile' => sprintf('1380000%04d', $i + 1),
                'department_id' => $deptId, 'dept_id' => $deptId, 'role' => 'user', 'title' => $role === 'manager' ? '部门负责人' : '工程师',
                'password_hash' => $pwd, 'password' => $pwd, 'status' => ds_st('active'), 'enabled' => 1, 'is_active' => 1,
                'remark' => '演示数据',
                '@at' => $s->now->modify('-' . (40 + $i) . ' days'),
            ]);
            $ctx['users'][] = ['id' => $id, 'name' => $name, 'dept' => $deptId, 'deptCode' => $dept];
        }
        $s->credentials[] = '演示用户：demo.zhangwei 等 10 个账号，密码 Demo@123456（是否能登录取决于该表是否用于登录）';
    }

    $keyT = $s->pick(['api_keys', 'apikeys', 'access_keys']);
    if ($keyT === null) {
        $s->notes[] = '没有找到 API Key 表，跳过 Key';
        return;
    }
    $specs = [
        ['网关压测脚本', 0, 'active', null, 9], ['RAG 评测流水线', 1, 'active', null, 8], ['运营日报生成', 2, 'active', null, 5],
        ['内部开发调试', 3, 'active', null, 6], ['智能客服机器人', 4, 'active', null, 14], ['销售助手插件', 5, 'active', null, 6],
        ['模型对比实验', 6, 'active', null, 4], ['市场文案助手', 7, 'active', null, 5], ['工单摘要服务', 8, 'active', null, 7],
        ['内容审核任务', 9, 'active', null, 3], ['旧版客服接口', 4, 'disabled', null, 2], ['外包项目临时 Key', 3, 'active', '-6 days', 2],
    ];
    $ctx['keys'] = [];
    foreach ($specs as $i => [$name, $ui, $status, $expires, $weight]) {
        $u = $ctx['users'][$ui] ?? ['id' => null, 'dept' => null, 'name' => ''];
        $plain = 'sk-demo-' . bin2hex(random_bytes(20));
        $expiresAt = $expires ? $s->now->modify($expires) : $s->now->modify('+180 days');
        $disabled = $status === 'disabled';
        $id = $s->insert($keyT, [
            'name' => $name, 'title' => $name, 'description' => '演示数据', 'remark' => '演示数据',
            'user_id' => $u['id'], 'owner_id' => $u['id'], 'department_id' => $u['dept'], 'dept_id' => $u['dept'],
            'key_prefix' => substr($plain, 0, 12), 'prefix' => substr($plain, 0, 12),
            'key_hash' => $s->apiKeyHash($plain), 'hash' => $s->apiKeyHash($plain), 'api_key_hash' => $s->apiKeyHash($plain), 'secret_hash' => $s->apiKeyHash($plain),
            'key_last4' => substr($plain, -4), 'last4' => substr($plain, -4), 'last_four' => substr($plain, -4),
            'masked_key' => substr($plain, 0, 12) . '…' . substr($plain, -4), 'key_mask' => substr($plain, 0, 12) . '…' . substr($plain, -4),
            'status' => $disabled ? ds_st('disabled') : ds_st('active'), 'enabled' => $disabled ? 0 : 1, 'is_active' => $disabled ? 0 : 1,
            'disabled_by' => $disabled ? 'admin' : null, 'disabled_at' => $disabled ? $s->now->modify('-3 days') : null,
            'expires_at' => $expiresAt, 'last_used_at' => $s->now->modify('-' . mt_rand(1, 300) . ' minutes'),
            'rate_limit' => 600, 'rpm_limit' => 600,
            '@at' => $s->now->modify('-' . (35 - $i) . ' days'),
        ]);
        $ctx['keys'][] = ['id' => $id, 'user' => $u['id'], 'dept' => $u['dept'], 'weight' => $weight, 'status' => $status,
            'expired' => $expires !== null, 'name' => $name];
        if ($i < 3) {
            $s->credentials[] = "API Key「{$name}」：{$plain}（演示用，渠道是假的，调用不会成功）";
        }
    }
}

function ds_seed_catalog(DemoSeeder $s, array &$ctx): void
{
    $provT = $s->pick(['providers', 'model_providers', 'vendors', 'suppliers']);
    $modelT = $s->pick(['models', 'ai_models', 'provider_models', 'model_catalog']);
    $chanT = $s->pick(['channels', 'provider_channels', 'model_channels', 'upstreams', 'provider_endpoints']);
    if ($modelT === null) {
        $s->notes[] = '没有找到模型表，跳过厂商/模型/渠道';
        return;
    }
    $cur = $s->currency;
    // 价格：每百万 token（人民币口径的演示价）
    $catalog = [
        'openai' => ['OpenAI', 'openai', [
            ['gpt-4o', 'text_generation', 18.0, 72.0, 128000, ['vision'], 16],
            ['gpt-4o-mini', 'text_generation', 1.1, 4.4, 128000, ['vision'], 22],
            ['text-embedding-3-small', 'embedding', 0.15, 0.0, 8191, [], 6],
            ['gpt-image-1', 'image_generation', 36.0, 288.0, 0, [], 1],
        ]],
        'anthropic' => ['Anthropic', 'anthropic', [
            ['claude-sonnet-4-5', 'text_generation', 21.6, 108.0, 200000, ['vision', 'tools'], 12],
            ['claude-haiku-4-5', 'text_generation', 7.2, 36.0, 200000, ['vision'], 6],
        ]],
        'deepseek' => ['DeepSeek', 'openai', [
            ['deepseek-chat', 'text_generation', 2.0, 8.0, 128000, ['tools'], 20],
            ['deepseek-reasoner', 'text_generation', 4.0, 16.0, 128000, ['reasoning'], 7],
        ]],
        'bailian' => ['阿里云百炼', 'openai', [
            ['qwen-plus', 'text_generation', 0.8, 2.0, 131072, ['tools'], 14],
            ['qwen-max', 'text_generation', 2.4, 9.6, 32768, [], 4],
            ['text-embedding-v3', 'embedding', 0.5, 0.0, 8192, [], 5],
        ]],
        'ark' => ['火山方舟', 'openai', [
            ['doubao-seed-1-6', 'text_generation', 0.8, 8.0, 256000, ['vision'], 10],
            ['doubao-seedance-1-0-pro', 'video_generation', 0.0, 15.0, 0, [], 1],
        ]],
        'zhipu' => ['智谱 AI', 'openai', [
            ['glm-4.6', 'text_generation', 2.0, 8.0, 200000, ['tools'], 6],
        ]],
    ];

    // 判断已有同名模型：撞名就加 demo- 前缀，避免演示渠道接走真实请求
    $nameCols = array_values(array_filter(['name', 'model_name', 'model', 'upstream_model'], static fn($c) => $s->hasCol($modelT, $c)));
    $exists = static function (string $name) use ($s, $modelT, $nameCols): bool {
        foreach ($nameCols as $c) {
            $st = $s->db->prepare("SELECT 1 FROM `{$modelT}` WHERE `{$c}` = ? LIMIT 1");
            $st->execute([$name]);
            if ($st->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    };

    $chanHasModel = $chanT !== null && ($s->hasCol($chanT, 'model_id'));
    $modelHasChan = $s->hasCol($modelT, 'channel_id');
    $joinT = $s->pick(['channel_models', 'model_channels', 'provider_channel_models', 'channel_model_bindings', 'route_models', 'model_routes']);
    if ($joinT === $chanT) {
        $joinT = null;
    }

    $secret = 'demo-key-not-real';
    $enc = $s->appEncrypt($secret);
    if ($enc === null) {
        $s->warn('没能调用应用的 Crypto 加密渠道密钥，渠道密钥按明文占位写入；在后台编辑这些演示渠道时如果报解密错误，重新填一个密钥保存即可');
    }
    $chanSem = static function (string $name, mixed $provId, string $slug, string $protocol, int $priority, mixed $modelId = null, ?string $upstream = null) use ($secret, $enc): array {
        $url = "https://{$slug}.demo.invalid/v1";
        return [
            'name' => $name, 'title' => $name, 'provider_id' => $provId, 'model_id' => $modelId,
            'base_url' => $url, 'endpoint' => $url, 'api_base' => $url, 'url' => $url,
            'protocol' => $protocol, 'type' => $protocol, 'api_type' => $protocol,
            'api_key' => $enc ?? $secret, 'api_key_encrypted' => $enc ?? $secret, 'api_key_ciphertext' => $enc ?? $secret, 'secret' => $enc ?? $secret,
            'secret_encrypted' => $enc ?? $secret, 'encrypted_api_key' => $enc ?? $secret, 'credential' => $enc ?? $secret,
            'upstream_model' => $upstream, 'upstream_model_name' => $upstream,
            'priority' => $priority, 'weight' => 100, 'sort' => $priority, 'timeout' => 120, 'timeout_seconds' => 120,
            'status' => ds_st('active'), 'enabled' => 1, 'is_active' => 1, 'remark' => '演示数据：地址为 .invalid，不会真实调用',
            'description' => '演示数据：地址为 .invalid，不会真实调用',
        ];
    };

    $ctx['models'] = [];
    foreach ($catalog as $slug => [$provName, $protocol, $models]) {
        $provId = null;
        if ($provT !== null) {
            $provId = $s->insert($provT, [
                'name' => $provName . '（演示）', 'display_name' => $provName . '（演示）', 'title' => $provName . '（演示）',
                'code' => 'demo-' . $slug, 'slug' => 'demo-' . $slug, 'vendor' => $slug, 'key' => 'demo-' . $slug,
                'type' => $protocol, 'provider_type' => $protocol, 'protocol' => $protocol,
                'base_url' => "https://{$slug}.demo.invalid/v1", 'website' => 'https://example.com',
                'api_key' => $enc ?? $secret,
                'api_key_ciphertext' => $enc ?? $secret, 'api_key_encrypted' => $enc ?? $secret,
                'status' => ds_st('active'), 'enabled' => 1, 'is_active' => 1, 'description' => '演示数据', 'remark' => '演示数据',
                '@at' => $s->now->modify('-45 days'),
            ]);
        }
        $provChans = [];
        if ($chanT !== null && !$chanHasModel) {
            $provChans[] = $s->insert($chanT, $chanSem("{$provName} 主线路（演示）", $provId, $slug, $protocol, 1));
            if (in_array($slug, ['openai', 'deepseek'], true)) {
                $provChans[] = $s->insert($chanT, $chanSem("{$provName} 备用线路（演示）", $provId, $slug . '-backup', $protocol, 2));
            }
        }
        foreach ($models as [$mname, $cap, $pin, $pout, $ctxWin, $tags, $weight]) {
            $name = $exists($mname) ? 'demo-' . $mname : $mname;
            $per1k = static fn(string $col, float $v): float => preg_match('/1k|thousand|per_k/', $col) ? $v / 1000 : $v;
            $sem = [
                'provider_id' => $provId, 'name' => $name, 'model_name' => $name, 'model' => $name, 'display_name' => $name,
                'model_key' => $name, 'code' => $name, 'upstream_model' => $mname, 'upstream_model_name' => $mname, 'upstream_model_id' => $mname,
                'capability' => $cap, 'type' => $cap, 'model_type' => $cap, 'capability_tags' => $tags, 'tags' => $tags,
                'context_window' => $ctxWin, 'context_length' => $ctxWin, 'max_context_tokens' => $ctxWin, 'max_input_tokens' => $ctxWin,
                'max_output_tokens' => $cap === 'text_generation' ? 16384 : 0, 'max_tokens' => $cap === 'text_generation' ? 16384 : 0,
                'currency' => $cur, 'protocol' => $protocol,
                'status' => ds_st('active'), 'enabled' => 1, 'is_active' => 1, 'description' => '演示数据', 'remark' => '演示数据',
                'channel_id' => $modelHasChan ? ($provChans[0] ?? null) : null,
                '@at' => $s->now->modify('-44 days'),
            ];
            foreach (['input_price', 'prompt_price', 'input_price_per_million', 'input_price_per_1k', 'price_input'] as $c) {
                $sem[$c] = $per1k($c, $pin);
            }
            foreach (['output_price', 'completion_price', 'output_price_per_million', 'output_price_per_1k', 'price_output'] as $c) {
                $sem[$c] = $per1k($c, $pout);
            }
            $mid = $s->insert($modelT, $sem);
            $chans = $provChans;
            if ($chanT !== null && $chanHasModel) {
                $chans = [$s->insert($chanT, $chanSem("{$provName} · {$mname}（演示）", $provId, $slug, $protocol, 1, $mid, $mname))];
            }
            if ($joinT !== null && $mid !== null) {
                foreach ($chans as $p => $cid) {
                    $s->insert($joinT, ['channel_id' => $cid, 'model_id' => $mid, 'provider_id' => $provId, 'upstream_model' => $mname,
                        'upstream_model_name' => $mname, 'priority' => $p + 1, 'weight' => 100, 'status' => ds_st('active'), 'enabled' => 1]);
                }
            }
            $endpoint = match ($cap) {
                'embedding' => '/v1/embeddings',
                'image_generation' => '/v1/images/generations',
                'video_generation' => '/v1/videos/generations',
                default => $protocol === 'anthropic' ? '/v1/messages' : '/v1/chat/completions',
            };
            $ctx['models'][] = ['id' => $mid, 'name' => $name, 'provider' => $provId, 'provName' => $provName, 'channels' => $chans,
                'cap' => $cap, 'pin' => $pin, 'pout' => $pout, 'weight' => $weight, 'endpoint' => $endpoint, 'protocol' => $protocol, 'slug' => $slug];
        }
    }

    // 价格表
    $priceT = $s->pick(['model_prices', 'prices', 'model_pricing', 'pricing', 'pricing_rules', 'price_rules']);
    if ($priceT !== null) {
        foreach ($ctx['models'] as $m) {
            $sem = [
                'model_id' => $m['id'], 'provider_id' => $m['provider'], 'name' => $m['name'] . ' 标准价', 'title' => $m['name'] . ' 标准价',
                'currency' => $cur, 'unit' => 'per_1m_tokens', 'billing_unit' => 'per_1m_tokens', 'pricing_mode' => 'token', 'rule_type' => 'token',
                'effective_at' => $s->now->modify('-60 days'), 'effective_from' => $s->now->modify('-60 days'), 'starts_at' => $s->now->modify('-60 days'),
                'status' => ds_st('active'), 'enabled' => 1, 'is_active' => 1, 'remark' => '演示数据',
            ];
            foreach (['input_price', 'prompt_price', 'input_price_per_million', 'input_price_per_1k', 'price_input', 'input_unit_price'] as $c) {
                $sem[$c] = preg_match('/1k/', $c) ? $m['pin'] / 1000 : $m['pin'];
            }
            foreach (['output_price', 'completion_price', 'output_price_per_million', 'output_price_per_1k', 'price_output', 'output_unit_price'] as $c) {
                $sem[$c] = preg_match('/1k/', $c) ? $m['pout'] / 1000 : $m['pout'];
            }
            $s->insert($priceT, $sem);
        }
    }

    // Key 的模型授权
    $grantT = $s->pick(['api_key_models', 'api_key_model_grants', 'api_key_model_permissions', 'api_key_allowed_models']);
    if ($grantT !== null && !empty($ctx['keys'])) {
        foreach ($ctx['keys'] as $k) {
            foreach ($ctx['models'] as $m) {
                $s->insert($grantT, ['api_key_id' => $k['id'], 'model_id' => $m['id'], 'status' => ds_st('active'), 'enabled' => 1]);
            }
        }
    }
}

function ds_seed_usage(DemoSeeder $s, array &$ctx): void
{
    if (empty($ctx['keys']) || empty($ctx['models'])) {
        $s->notes[] = '调用记录依赖演示 Key 和模型，本次没有，跳过';
        return;
    }
    $detailTables = array_values(array_filter([
        $s->pick(['usage_records', 'usage_logs']),
        $s->pick(['request_logs', 'gateway_request_logs', 'api_request_logs']),
    ]));
    if ($detailTables === []) {
        $s->notes[] = '没有找到 usage_records / request_logs，跳过调用记录';
        return;
    }
    $days = max(1, (int)($s->opts['days'] ?? 30));
    $perDay = max(10, (int)($s->opts['per-day'] ?? 120));
    $now = $s->now;
    $hourW = [0 => 1, 1 => 0.5, 2 => 0.3, 3 => 0.3, 4 => 0.3, 5 => 0.5, 6 => 1, 7 => 2, 8 => 4, 9 => 8, 10 => 10, 11 => 9,
        12 => 5, 13 => 6, 14 => 9, 15 => 10, 16 => 9, 17 => 7, 18 => 4, 19 => 3, 20 => 3, 21 => 2.5, 22 => 2, 23 => 1.5];
    $modelW = [];
    foreach ($ctx['models'] as $i => $m) {
        $modelW[$i] = $m['weight'];
    }
    $records = [];
    $ua = ['openai-python/1.109.1', 'OpenAI/NodeJS 5.23.0', 'anthropic-sdk-python/0.69.0', 'langchain/0.3.27', 'curl/8.7.1', 'TokenHub-Admin/ChatTest'];
    $errors = [
        429 => ['PROVIDER_RATE_LIMITED', 'Upstream provider rate limited the request'],
        502 => ['PROVIDER_ERROR', 'Upstream provider returned an error'],
        504 => ['PROVIDER_TIMEOUT', 'Upstream provider timed out'],
        400 => ['INVALID_REQUEST', 'Invalid request: max_tokens is too large'],
        422 => ['QUOTA_EXCEEDED', 'Department monthly quota exceeded'],
    ];

    for ($d = $days - 1; $d >= 0; $d--) {
        $date = $now->modify("-{$d} days")->setTime(0, 0);
        $dow = (int)$date->format('N');
        $factor = $dow >= 6 ? 0.35 : 1.0;
        $growth = 0.7 + 0.3 * (1 - $d / max(1, $days));
        $n = (int)round($perDay * $factor * $growth * (0.85 + mt_rand() / mt_getrandmax() * 0.3));
        $hours = $hourW;
        if ($d === 0) {
            $cur = (int)$now->format('G');
            $hours = array_filter($hourW, static fn($h) => $h <= $cur, ARRAY_FILTER_USE_KEY);
            $n = (int)round($n * array_sum($hours) / array_sum($hourW)) + 5;
        }
        for ($i = 0; $i < $n; $i++) {
            $h = (int)ds_wpick($hours);
            $ts = $date->setTime($h, mt_rand(0, 59), mt_rand(0, 59));
            if ($ts > $now) {
                $ts = $now->modify('-' . mt_rand(10, 3000) . ' seconds');
            }
            $keyW = [];
            foreach ($ctx['keys'] as $ki => $k) {
                if (($k['status'] === 'disabled' && $d < 3) || ($k['expired'] && $d < 6)) {
                    continue;
                }
                $keyW[$ki] = $k['weight'];
            }
            $k = $ctx['keys'][ds_wpick($keyW)];
            $m = $ctx['models'][ds_wpick($modelW)];
            $chan = $m['channels'] ? $m['channels'][array_rand($m['channels'])] : null;

            $errRate = 0.03;
            if ($m['slug'] === 'deepseek' && $d === 1) {
                $errRate = 0.18;
            }
            $code = 200;
            if (mt_rand() / mt_getrandmax() < $errRate) {
                $code = (int)ds_wpick([429 => 45, 502 => 25, 504 => 15, 400 => 10, 422 => 5]);
            }
            $ok = $code === 200;
            $in = $out = $cached = $reason = 0;
            $images = 0;
            $videoSec = 0;
            switch ($m['cap']) {
                case 'embedding':
                    $in = ds_lognorm(600, 0.7, 20, 8000);
                    break;
                case 'image_generation':
                    $in = ds_lognorm(80, 0.4, 10, 400);
                    $out = $ok ? 4160 : 0;
                    $images = $ok ? 1 : 0;
                    break;
                case 'video_generation':
                    $in = ds_lognorm(120, 0.4, 10, 600);
                    $out = $ok ? 108900 : 0;
                    $videoSec = 5;
                    break;
                default:
                    $in = ds_lognorm(1500, 0.9, 30, 60000);
                    $out = $ok ? ds_lognorm(420, 0.8, 5, 8000) : 0;
                    $cached = mt_rand(0, 3) === 0 ? (int)($in * 0.6) : 0;
                    $reason = str_contains($m['name'], 'reasoner') && $ok ? ds_lognorm(900, 0.6, 50, 12000) : 0;
            }
            if ($code === 400 || $code === 422) {
                $in = $ok ? $in : 0;
            }
            $cost = $ok ? round(($in * $m['pin'] + ($out + $reason) * $m['pout']) / 1e6, 8) : 0.0;
            $stream = $m['cap'] === 'text_generation' && mt_rand(1, 10) <= 7;
            $ttft = $m['cap'] === 'text_generation' ? ds_lognorm(str_contains($m['name'], 'reasoner') ? 2500 : 650, 0.45, 120, 20000) : 0;
            $latency = match ($m['cap']) {
                'embedding' => ds_lognorm(180, 0.4, 40, 3000),
                'image_generation' => ds_lognorm(14000, 0.3, 4000, 60000),
                'video_generation' => ds_lognorm(1800, 0.3, 500, 8000),
                default => $ttft + (int)($out * (15 + mt_rand(0, 25))),
            };
            if ($code === 504) {
                $latency = 60000 + mt_rand(0, 2000);
            }
            $records[] = [
                'ts' => $ts, 'key' => $k, 'model' => $m, 'channel' => $chan, 'code' => $code, 'ok' => $ok,
                'in' => $in, 'out' => $out, 'cached' => $cached, 'reason' => $reason, 'images' => $images, 'video' => $videoSec,
                'cost' => $cost, 'stream' => $stream, 'ttft' => $ok ? $ttft : 0, 'latency' => $latency,
                'err' => $ok ? null : $errors[$code], 'ua' => $ua[array_rand($ua)],
                'rid' => 'req_demo_' . bin2hex(random_bytes(10)), 'ip' => '10.20.' . mt_rand(1, 30) . '.' . mt_rand(2, 250),
            ];
        }
    }
    usort($records, static fn($a, $b) => $a['ts'] <=> $b['ts']);

    foreach ($detailTables as $t) {
        foreach ($records as $r) {
            $m = $r['model'];
            $total = $r['in'] + $r['out'] + $r['reason'];
            $end = $r['ts']->modify('+' . (int)ceil($r['latency'] / 1000) . ' seconds');
            $s->insert($t, [
                'request_id' => $r['rid'], 'trace_id' => $r['rid'], 'upstream_request_id' => $r['ok'] ? 'up_' . substr($r['rid'], 9) : null,
                'api_key_id' => $r['key']['id'], 'user_id' => $r['key']['user'], 'department_id' => $r['key']['dept'],
                'provider_id' => $m['provider'], 'model_id' => $m['id'], 'channel_id' => $r['channel'],
                'model' => $m['name'], 'model_name' => $m['name'], 'requested_model' => $m['name'], 'upstream_model' => $m['name'],
                'endpoint' => $m['endpoint'], 'path' => $m['endpoint'], 'route' => $m['endpoint'], 'method' => 'POST',
                'capability' => $m['cap'], 'protocol' => $m['protocol'], 'request_type' => $m['cap'],
                'status' => $r['ok'] ? ds_alt('success', 200) : ds_alt('failed', $r['code']),
                'status_code' => $r['code'], 'http_status' => $r['code'], 'response_status' => $r['code'],
                'success' => $r['ok'] ? 1 : 0, 'is_success' => $r['ok'] ? 1 : 0,
                'input_tokens' => $r['in'], 'prompt_tokens' => $r['in'], 'output_tokens' => $r['out'], 'completion_tokens' => $r['out'],
                'total_tokens' => $total, 'cached_tokens' => $r['cached'], 'cache_read_tokens' => $r['cached'], 'cached_input_tokens' => $r['cached'],
                'reasoning_tokens' => $r['reason'], 'image_count' => $r['images'], 'images' => $r['images'],
                'video_seconds' => $r['video'], 'audio_seconds' => 0,
                'cost' => $r['cost'], 'total_cost' => $r['cost'], 'cost_amount' => $r['cost'], 'amount' => $r['cost'],
                'currency' => $s->currency,
                'latency_ms' => $r['latency'], 'duration_ms' => $r['latency'], 'response_time_ms' => $r['latency'], 'elapsed_ms' => $r['latency'],
                'first_token_ms' => $r['ttft'], 'ttft_ms' => $r['ttft'], 'first_byte_ms' => $r['ttft'], 'time_to_first_token_ms' => $r['ttft'],
                'stream' => $r['stream'] ? 1 : 0, 'is_stream' => $r['stream'] ? 1 : 0,
                'client_ip' => $r['ip'], 'ip' => $r['ip'], 'ip_address' => $r['ip'], 'user_agent' => $r['ua'],
                'error_code' => $r['err'][0] ?? null, 'error_type' => $r['err'][0] ?? null, 'error_message' => $r['err'][1] ?? null,
                'retry_count' => $r['code'] === 429 ? 1 : 0, 'attempt_count' => $r['code'] === 429 ? 2 : 1,
                'started_at' => $r['ts'], 'requested_at' => $r['ts'], 'finished_at' => $end, 'completed_at' => $end,
                'created_at' => $r['ts'], 'updated_at' => $end,
            ]);
        }
    }

    // 汇总表（分钟/小时/天）
    $aggTables = array_values(array_unique(array_merge(
        $s->like('/^usage_(aggregates?|aggs?|stats?|statistics|summary|summaries)(_|$)/i'),
        $s->like('/^usage_cost_aggregates?/i'),
        $s->like('/^(request|model|api_key|department)_usage_(day|daily|hour|hourly)/i')
    )));
    $metricCols = ['request_count', 'requests', 'total_requests', 'request_total', 'calls', 'call_count',
        'success_count', 'successful_requests', 'success_requests', 'error_count', 'failed_count', 'failure_count', 'failed_requests', 'error_requests',
        'input_tokens', 'prompt_tokens', 'output_tokens', 'completion_tokens', 'total_tokens', 'cached_tokens', 'cache_read_tokens', 'reasoning_tokens',
        'cost', 'total_cost', 'cost_amount', 'amount', 'latency_ms_sum', 'total_latency_ms', 'latency_sum_ms', 'latency_total_ms', 'sum_latency_ms',
        'image_count', 'video_seconds', 'audio_seconds', 'stream_count'];
    foreach ($aggTables as $t) {
        $gran = preg_match('/minute/i', $t) ? 'minute' : (preg_match('/hour/i', $t) ? 'hour' : 'day');
        $hasDateCol = $s->hasCol($t, 'date') || $s->hasCol($t, 'day') || $s->hasCol($t, 'stat_date');
        $splitStatus = $s->hasCol($t, 'status_code') || $s->hasCol($t, 'status');
        $groups = [];
        foreach ($records as $r) {
            if ($gran === 'minute' && $r['ts'] < $now->modify('-24 hours')) {
                continue;
            }
            $b = match ($gran) {
                'minute' => $r['ts']->setTime((int)$r['ts']->format('G'), (int)$r['ts']->format('i')),
                'hour' => $r['ts']->setTime((int)$r['ts']->format('G'), 0),
                default => $r['ts']->setTime(0, 0),
            };
            $gk = $b->format('YmdHi') . '|' . $r['key']['id'] . '|' . $r['model']['id'] . '|' . $r['channel'] . '|' . ($splitStatus ? $r['code'] : '');
            $g = &$groups[$gk];
            if ($g === null) {
                $g = ['b' => $b, 'r' => $r, 'n' => 0, 'ok' => 0, 'in' => 0, 'out' => 0, 'cached' => 0, 'reason' => 0, 'cost' => 0.0, 'lat' => 0, 'img' => 0, 'vid' => 0, 'stream' => 0];
            }
            $g['n']++;
            $g['ok'] += $r['ok'] ? 1 : 0;
            $g['in'] += $r['in'];
            $g['out'] += $r['out'];
            $g['cached'] += $r['cached'];
            $g['reason'] += $r['reason'];
            $g['cost'] += $r['cost'];
            $g['lat'] += $r['latency'];
            $g['img'] += $r['images'];
            $g['vid'] += $r['video'];
            $g['stream'] += $r['stream'] ? 1 : 0;
            unset($g);
        }
        foreach ($groups as $g) {
            $r = $g['r'];
            $m = $r['model'];
            $b = $g['b'];
            $bucketStr = $gran === 'day' ? $b->format('Y-m-d') : $b->format('Y-m-d H:i:s');
            $bucket = ds_alt($bucketStr, $b->getTimestamp());
            $fail = $g['n'] - $g['ok'];
            $total = $g['in'] + $g['out'] + $g['reason'];
            $cost = round($g['cost'], 8);
            $sem = [
                'bucket' => $bucket, 'bucket_start' => $bucket, 'bucket_at' => $bucket, 'bucket_time' => $bucket, 'period_start' => $bucket,
                'stat_time' => $bucket, 'ts' => $bucket, 'time' => $bucket, 'stat_date' => $b->format('Y-m-d'), 'stat_day' => $b->format('Y-m-d'),
                'usage_date' => $b->format('Y-m-d'), 'date' => $b->format('Y-m-d'),
                'day' => $s->hasCol($t, 'day') && in_array($s->cols($t)['day']['DATA_TYPE'], ['date', 'datetime', 'timestamp', 'varchar', 'char'], true) ? $b->format('Y-m-d') : ds_alt($b->format('Y-m-d'), (int)$b->format('Ymd')),
                'hour' => $hasDateCol ? ds_alt($b->format('Y-m-d H:00:00'), (int)$b->format('G')) : $bucket,
                'minute' => $hasDateCol ? ds_alt($b->format('Y-m-d H:i:00'), (int)$b->format('i')) : $bucket,
                'api_key_id' => $r['key']['id'], 'user_id' => $r['key']['user'], 'department_id' => $r['key']['dept'],
                'provider_id' => $m['provider'], 'model_id' => $m['id'], 'channel_id' => $r['channel'], 'model' => $m['name'], 'model_name' => $m['name'],
                'capability' => $m['cap'], 'endpoint' => $m['endpoint'], 'currency' => $s->currency,
                'status_code' => $splitStatus ? $r['code'] : null, 'status' => $splitStatus ? ($r['ok'] ? ds_alt('success', 200) : ds_alt('failed', $r['code'])) : null,
                'request_count' => $g['n'], 'requests' => $g['n'], 'total_requests' => $g['n'], 'request_total' => $g['n'], 'calls' => $g['n'], 'call_count' => $g['n'],
                'success_count' => $g['ok'], 'successful_requests' => $g['ok'], 'success_requests' => $g['ok'],
                'error_count' => $fail, 'failed_count' => $fail, 'failure_count' => $fail, 'failed_requests' => $fail, 'error_requests' => $fail,
                'input_tokens' => $g['in'], 'prompt_tokens' => $g['in'], 'output_tokens' => $g['out'], 'completion_tokens' => $g['out'],
                'total_tokens' => $total, 'cached_tokens' => $g['cached'], 'cache_read_tokens' => $g['cached'], 'reasoning_tokens' => $g['reason'],
                'cost' => $cost, 'total_cost' => $cost, 'cost_amount' => $cost, 'amount' => $cost,
                'latency_ms_sum' => $g['lat'], 'total_latency_ms' => $g['lat'], 'latency_sum_ms' => $g['lat'], 'latency_total_ms' => $g['lat'], 'sum_latency_ms' => $g['lat'],
                'image_count' => $g['img'], 'video_seconds' => $g['vid'], 'audio_seconds' => 0, 'stream_count' => $g['stream'],
                '@at' => $b,
            ];
            $s->upsertAdd($t, $sem, $metricCols);
        }
    }
    $ctx['records'] = count($records);
}

function ds_seed_rag(DemoSeeder $s, array &$ctx): void
{
    $kbT = $s->pick(['rag_knowledge_bases', 'knowledge_bases', 'kb_knowledge_bases']);
    if ($kbT === null) {
        $s->notes[] = '没有找到知识库表，跳过 RAG';
        return;
    }
    $dsT = $s->pick(['rag_vector_datasets', 'rag_datasets', 'knowledge_datasets']);
    $upT = $s->pick(['rag_uploads']);
    $docT = $s->pick(['rag_documents', 'knowledge_documents']);
    $chunkT = $s->pick(['rag_chunks', 'knowledge_chunks']);
    $kbBindT = $s->pick(['rag_knowledge_base_bindings', 'rag_kb_bindings', 'knowledge_base_bindings']);
    $dept = $ctx['dept'] ?? [];
    $users = $ctx['users'] ?? [];
    $embedModel = null;
    foreach ($ctx['models'] ?? [] as $m) {
        if ($m['cap'] === 'embedding') {
            $embedModel = $m;
            break;
        }
    }

    $kbs = [
        [
            'code' => 'demo-product-manual', 'name' => '产品使用手册（演示）', 'visibility' => 'department', 'dept' => 'DEMO-OPS', 'owner' => 2,
            'desc' => 'TokenHub 接入与使用说明', 'grants' => [['DEMO-OPS', 'write'], ['DEMO-RD', 'read'], ['DEMO-CS', 'read']],
            'docs' => [
                ['快速接入指南', [
                    ['快速接入 > 获取 API Key', '登录管理后台，进入「API 密钥」页面点击「新建」。密钥只在创建时显示一次，请立即复制保存。每个密钥可以限制可用模型、IP 白名单和有效期。'],
                    ['快速接入 > 调用方式', 'TokenHub 兼容 OpenAI 与 Anthropic 协议。把 SDK 的 base_url 改成 TokenHub 地址，例如 https://<你的域名>/v1，api_key 填写 TokenHub 签发的密钥即可，无需改动业务代码。'],
                    ['快速接入 > 选择模型', '调用 GET /v1/models 可以看到当前密钥有权限使用的模型。请求里的 model 字段填写列表中的名称，TokenHub 会按渠道优先级自动路由并在失败时切换备用线路。'],
                ]],
                ['额度与计费说明', [
                    ['额度 > 额度维度', '额度可以按部门、用户、API Key 三个维度设置，支持按请求数、Token 数和费用限额。任一维度超限都会返回 QUOTA_EXCEEDED。'],
                    ['额度 > 费用计算', '费用按模型价格表计算：输入 Token、输出 Token、缓存读取和推理 Token 分别计价。流式请求同样按上游返回的实际用量结算。'],
                ]],
                ['常见错误码', [
                    ['错误码 > 429', '429 表示上游或 TokenHub 限流。TokenHub 会先切换备用渠道；所有渠道都限流时返回 429，请稍后重试或联系管理员提高限额。'],
                    ['错误码 > 422', '422 通常表示请求内容不被模型支持，例如向不支持视觉的模型发送图片，或额度不足。返回体中的 error.code 会说明具体原因。'],
                ]],
            ],
        ],
        [
            'code' => 'demo-cs-faq', 'name' => '客服常见问题（演示）', 'visibility' => 'shared', 'dept' => 'DEMO-CS', 'owner' => 4,
            'desc' => '智能客服机器人使用的问答库', 'grants' => [['DEMO-CS', 'write']],
            'docs' => [
                ['账号与登录 FAQ', [
                    ['FAQ > 忘记密码', '问：忘记密码怎么办？答：在登录页点击「忘记密码」，按提示通过绑定邮箱重置；企业账号请联系本部门管理员重置。'],
                    ['FAQ > 账号被锁定', '问：提示账号已停用？答：可能是部门已停用或管理员停用了账号，请联系部门负责人在「用户管理」中重新启用。'],
                ]],
                ['发票与付款 FAQ', [
                    ['FAQ > 开具发票', '问：如何开具发票？答：在「账单中心」选择对应月份，填写抬头和税号后提交，电子发票会在 3 个工作日内发送到预留邮箱。'],
                    ['FAQ > 账单周期', '问：账单什么时候出？答：每月 1 日生成上月账单，可以在后台导出明细，按部门、用户和模型分别汇总。'],
                ]],
            ],
        ],
        [
            'code' => 'demo-policy', 'name' => '内部制度库（演示）', 'visibility' => 'private', 'dept' => 'DEMO', 'owner' => 0,
            'desc' => '公司 AI 使用规范与数据安全制度', 'grants' => [['DEMO-RD', 'read']],
            'docs' => [
                ['生成式 AI 使用规范', [
                    ['使用规范 > 第一条 适用范围', '本规范适用于公司全体员工通过 TokenHub 使用生成式 AI 服务的行为，包括对话、代码生成、文档总结和知识库问答。'],
                    ['使用规范 > 第二条 数据分级', '禁止向外部模型发送客户身份证号、银行卡号等敏感个人信息。TokenHub 已启用脱敏策略，命中规则的内容会被替换或拦截。'],
                    ['使用规范 > 第三条 结果复核', 'AI 生成的对外内容（合同、公告、营销文案）必须经人工复核后才能发布，复核人对内容准确性负责。'],
                ]],
            ],
        ],
    ];

    $vis = static fn(string $v) => $v;
    foreach ($kbs as $ki => $kb) {
        $deptId = $dept[$kb['dept']] ?? null;
        $owner = $users[$kb['owner']]['id'] ?? null;
        $kbId = $s->insert($kbT, [
            'name' => $kb['name'], 'title' => $kb['name'], 'code' => $kb['code'], 'slug' => $kb['code'], 'description' => $kb['desc'],
            'visibility' => $vis($kb['visibility']), 'department_id' => $deptId, 'owner_user_id' => $owner, 'owner_id' => $owner, 'user_id' => $owner,
            'status' => ds_st('active'), 'enabled' => 1, 'is_active' => 1, 'lifecycle' => 'active',
            'chunking_profile' => ['version' => 'p2', 'target_tokens' => 400, 'overlap' => 0.12],
            'embedding_model_id' => $embedModel['id'] ?? null, 'remark' => '演示数据',
            '@at' => $s->now->modify('-' . (25 - $ki * 5) . ' days'),
        ]);
        $dsId = null;
        if ($dsT !== null) {
            $dsId = $s->insert($dsT, [
                'knowledge_base_id' => $kbId, 'kb_id' => $kbId, 'name' => $kb['name'] . ' · 文本', 'title' => $kb['name'] . ' · 文本',
                'code' => $kb['code'] . '-text', 'modality' => 'text', 'type' => 'text', 'description' => '演示数据',
                'embedding_model_id' => $embedModel['id'] ?? null, 'model_id' => $embedModel['id'] ?? null,
                'dimension' => 1536, 'vector_dimension' => 1536, 'dimensions' => 1536, 'distance_metric' => 'cosine', 'distance' => 'cosine',
                'collection_name' => 'demo_' . str_replace('-', '_', $kb['code']), 'vector_store' => 'mysql',
                'acl_mode' => 'inherit', 'permission_mode' => 'inherit', 'is_default' => $ki === 0 ? 1 : 0, 'priority' => 10 - $ki,
                'status' => ds_st('active'), 'enabled' => 1, 'is_active' => 1, 'lifecycle' => 'active', 'department_id' => $deptId,
            ]);
        }
        if ($kbBindT !== null) {
            foreach ($kb['grants'] as [$dcode, $perm]) {
                $s->insert($kbBindT, [
                    'knowledge_base_id' => $kbId, 'kb_id' => $kbId, 'subject_type' => 'department', 'subject_id' => $dept[$dcode] ?? null,
                    'department_id' => $dept[$dcode] ?? null, 'permission' => $perm, 'role' => $perm, 'access_level' => $perm,
                    'include_descendants' => 1, 'include_children' => 1, 'status' => ds_st('active'), 'enabled' => 1,
                ]);
            }
        }
        foreach ($kb['docs'] as $di => [$title, $chunks]) {
            $fname = $title . '.md';
            $full = implode("\n\n", array_map(static fn($c) => '## ' . $c[0] . "\n" . $c[1], $chunks));
            $sha = hash('sha256', $full);
            $upId = null;
            if ($upT !== null) {
                $upId = $s->insert($upT, [
                    'knowledge_base_id' => $kbId, 'kb_id' => $kbId, 'dataset_id' => $dsId, 'vector_dataset_id' => $dsId,
                    'file_name' => $fname, 'filename' => $fname, 'original_name' => $fname, 'name' => $fname, 'title' => $title,
                    'mime_type' => 'text/markdown', 'mime' => 'text/markdown', 'content_type' => 'text/markdown', 'file_type' => 'md', 'extension' => 'md',
                    'size' => strlen($full), 'file_size' => strlen($full), 'size_bytes' => strlen($full), 'sha256' => $sha, 'file_hash' => $sha,
                    'storage_path' => 'demo/rag/' . $kb['code'] . '/' . ($di + 1) . '.md', 'path' => 'demo/rag/' . $kb['code'] . '/' . ($di + 1) . '.md',
                    'status' => ds_st('confirmed'), 'department_id' => $deptId, 'uploaded_by' => $owner, 'user_id' => $owner,
                    'item_count' => count($chunks), 'total_items' => count($chunks),
                ]);
            }
            $docId = null;
            if ($docT !== null) {
                $docId = $s->insert($docT, [
                    'upload_id' => $upId, 'knowledge_base_id' => $kbId, 'kb_id' => $kbId, 'dataset_id' => $dsId, 'vector_dataset_id' => $dsId,
                    'title' => $title, 'name' => $title, 'doc_type' => 'markdown', 'type' => 'markdown', 'source' => 'upload', 'source_name' => $fname,
                    'file_name' => $fname, 'content' => $full, 'content_hash' => $sha, 'version' => 1, 'chunk_count' => count($chunks),
                    'status' => ds_st('active'), 'lifecycle' => 'active', 'enabled' => 1, 'is_active' => 1, 'department_id' => $deptId,
                    'published_at' => $s->now->modify('-' . (20 - $di) . ' days'),
                ]);
            }
            if ($chunkT !== null && $docId !== null) {
                foreach ($chunks as $ci => [$heading, $text]) {
                    $s->insert($chunkT, [
                        'document_id' => $docId, 'doc_id' => $docId, 'knowledge_base_id' => $kbId, 'dataset_id' => $dsId, 'upload_id' => $upId,
                        'chunk_index' => $ci, 'seq' => $ci, 'position' => $ci, 'ordinal' => $ci,
                        'content' => $text, 'text' => $text, 'title' => $heading, 'heading_path' => $heading, 'chunk_type' => 'text', 'type' => 'text',
                        'token_count' => mb_strlen($text), 'char_count' => mb_strlen($text), 'content_hash' => hash('sha256', $text),
                        'metadata' => ['heading_path' => explode(' > ', $heading), 'source' => $fname, 'demo' => true],
                        'status' => ds_st('active'), 'lifecycle' => 'active', 'enabled' => 1, 'is_active' => 1,
                        'chunker_version' => 'p2', 'chunking_version' => 'p2',
                    ]);
                }
            }
        }
    }
    $s->notes[] = '知识库只写了数据库记录，没有生成向量；关键词检索可用，向量检索需要在后台对演示知识库执行一次重建索引';
}

function ds_seed_alerts(DemoSeeder $s, array &$ctx): void
{
    $evT = $s->pick(['alert_events', 'alerts', 'alert_records', 'alert_incidents']);
    if ($evT === null) {
        $s->notes[] = '没有找到告警表，跳过告警';
        return;
    }
    $ruleT = $s->pick(['alert_rules', 'alert_policies']);
    if ($ruleT === $evT) {
        $ruleT = null;
    }
    $now = $s->now;
    $deepseek = null;
    foreach ($ctx['models'] ?? [] as $m) {
        if ($m['slug'] === 'deepseek') {
            $deepseek = $m;
            break;
        }
    }
    $events = [
        ['channel_circuit_open', '渠道熔断', 'critical', 'open', '-26 hours', '-25 hours', 14,
            'DeepSeek 主线路（演示）连续 5 次失败，已熔断 30 秒', '上游返回 502/504，已自动切换到备用线路。', 'channel', $deepseek['channels'][0] ?? null],
        ['quota_threshold', '额度预警', 'warning', 'acknowledged', '-2 days', '-5 hours', 3,
            '部门「市场销售部」本月费用额度已用 85%', '按当前速度预计 6 天后用尽，可在「额度」页调整。', 'department', $ctx['dept']['DEMO-MKT'] ?? null],
        ['latency_p95', '延迟异常', 'warning', 'open', '-3 hours', '-20 minutes', 6,
            'gpt-4o P95 延迟超过 8 秒', '最近 15 分钟 P95 = 9.4s，首字时间正常，主要是长输出请求。', 'model', $ctx['models'][0]['id'] ?? null],
        ['queue_backlog', '队列积压', 'info', 'resolved', '-4 days', '-4 days', 2,
            '用量写入队列积压已恢复', 'usage.write 积压峰值 3,200 条，worker 扩容后 4 分钟内清空。', 'system', null],
        ['rate_limited', '限流', 'critical', 'resolved', '-6 days', '-6 days', 21,
            'API Key「智能客服机器人」触发 429 限流', '每分钟请求超过 600 次，已在 12 分钟后恢复。', 'api_key', $ctx['keys'][4]['id'] ?? null],
        ['rag_index_health', '索引健康', 'warning', 'silenced', '-1 days', '-2 hours', 4,
            '知识库「产品使用手册（演示）」索引健康度下降', '有 2 个文档尚未生成向量，检索只走关键词召回。', 'knowledge_base', null],
    ];
    $ruleIds = [];
    foreach ($events as [$code, $ruleName, $sev, $status, $first, $last, $count, $title, $msg, $ttype, $tid]) {
        $ruleId = null;
        if ($ruleT !== null) {
            $ruleId = $ruleIds[$code] ??= $s->insert($ruleT, [
                'name' => $ruleName . '（演示）', 'title' => $ruleName . '（演示）', 'code' => 'demo-' . $code, 'type' => $code, 'metric' => $code,
                'severity' => $sev, 'level' => $sev, 'status' => ds_st('disabled'), 'enabled' => 0, 'is_active' => 0,
                'description' => '演示数据', 'condition' => ['metric' => $code], 'conditions' => ['metric' => $code],
            ]);
        }
        $firstAt = $now->modify($first);
        $lastAt = $now->modify($last);
        $s->insert($evT, [
            'rule_id' => $ruleId, 'alert_rule_id' => $ruleId, 'event_type' => 'demo.' . $code, 'type' => $code, 'alert_type' => $code, 'category' => $code, 'code' => $code,
            'fingerprint' => hash('sha256', 'demo|' . $code), 'dedupe_key' => 'demo|' . $code,
            'title' => $title, 'name' => $title, 'subject' => $title, 'message' => $msg, 'summary' => $title, 'description' => $msg, 'content' => $msg, 'detail' => $msg,
            'severity' => $sev, 'level' => $sev, 'priority' => $sev,
            'status' => ds_st($status === 'silenced' ? 'open' : $status), 'state' => ds_st($status === 'silenced' ? 'open' : $status),
            'source' => 'demo', 'target_type' => $ttype, 'target_id' => $tid, 'resource_type' => $ttype, 'resource_id' => $tid,
            'occurrence_count' => $count, 'occurrences' => $count, 'count' => $count, 'hit_count' => $count,
            'first_seen_at' => $firstAt, 'first_occurred_at' => $firstAt, 'triggered_at' => $firstAt, 'started_at' => $firstAt, 'fired_at' => $firstAt,
            'last_seen_at' => $lastAt, 'last_occurred_at' => $lastAt,
            'acknowledged_at' => in_array($status, ['acknowledged', 'resolved'], true) ? $firstAt->modify('+15 minutes') : null,
            'resolved_at' => $status === 'resolved' ? $lastAt : null, 'recovered_at' => $status === 'resolved' ? $lastAt : null,
            'silenced_until' => $now->modify('+30 days'), 'muted_until' => $now->modify('+30 days'),
            'notified_at' => $firstAt, 'escalated_at' => $firstAt,
            'metadata' => ['demo' => true], 'payload' => ['demo' => true], 'context' => ['demo' => true], 'labels' => ['demo' => 'true'],
            '@at' => $firstAt,
        ]);
    }
    $s->notes[] = '演示告警使用独立事件类型，规则默认停用，事件已静默并标记升级，不会自动触发真实通知';
}

// ==================================================================== 入口

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        ds_line("未知参数：{$a}");
        exit(2);
    }
}
if (isset($opts['help']) || isset($opts['h'])) {
    $src = (string)file_get_contents(__FILE__);
    preg_match('#/\*\*(.*?)\*/#s', $src, $m);
    ds_line(trim(preg_replace('/^\s*\* ?/m', '', $m[1] ?? '')));
    exit(0);
}

$envFile = ds_env_load();
$dsn = ds_env_first($envFile, ['DB_DSN']);
if ($dsn === null) {
    $host = ds_env_first($envFile, ['DB_HOST', 'MYSQL_HOST', 'DATABASE_HOST'], 'mysql');
    $port = ds_env_first($envFile, ['DB_PORT', 'MYSQL_PORT', 'DATABASE_PORT'], '3306');
    $name = ds_env_first($envFile, ['DB_DATABASE', 'DB_NAME', 'MYSQL_DATABASE', 'DATABASE_NAME'], 'tokenhub');
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
}
$user = ds_env_first($envFile, ['DB_USERNAME', 'DB_USER', 'MYSQL_USER', 'DATABASE_USER'], 'tokenhub');
$pass = ds_env_first($envFile, ['DB_PASSWORD', 'DB_PASS', 'MYSQL_PASSWORD', 'DATABASE_PASSWORD'], '');

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (PDOException $e) {
    ds_line('连不上数据库：' . $e->getMessage());
    ds_line("DSN：{$dsn}  用户：{$user}");
    ds_line('请确认在 php 容器里运行，或用 DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD 环境变量指定');
    exit(1);
}

$s = new DemoSeeder($pdo, $opts);
mt_srand(20261030);

if (isset($opts['inspect'])) {
    $pattern = is_string($opts['inspect']) ? $opts['inspect'] : 'depart|user|api_key|provider|model|channel|price|pricing|usage|request_log|alert|rag_|knowledge';
    foreach ($s->like('/' . $pattern . '/i') as $t) {
        if (str_starts_with($t, 'admin_session')) {
            continue;
        }
        $parts = [];
        $fks = $s->fks($t);
        foreach ($s->cols($t) as $n => $c) {
            $flag = ($c['IS_NULLABLE'] === 'NO' && $c['COLUMN_DEFAULT'] === null && !str_contains($c['EXTRA'], 'auto_increment')) ? '!' : '';
            $fk = isset($fks[$n]) ? '→' . $fks[$n][0] : '';
            $parts[] = "{$n}{$flag}:{$c['COLUMN_TYPE']}{$fk}";
        }
        $uq = [];
        foreach ($s->uniques($t) as $i => $cols) {
            $uq[] = $i . '(' . implode(',', $cols) . ')';
        }
        ds_line("## {$t}");
        ds_line('  ' . implode(' | ', $parts));
        ds_line('  唯一键：' . implode(' ', $uq));
    }
    ds_line('');
    ds_line('（! 表示必填且无默认值）');
    exit(0);
}

if (isset($opts['purge'])) {
    $pdo->beginTransaction();
    try {
        [$n, $forced] = $s->purge();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        ds_line('清理失败，已回滚：' . $e->getMessage());
        exit(1);
    }
    ds_line($n === 0 ? '没有需要清理的演示数据。' : "已清理 {$n} 条演示记录" . ($forced ? "（其中 {$forced} 条在关闭外键检查后删除）" : '') . '。');
    exit(0);
}

$only = isset($opts['only']) && is_string($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : ['org', 'catalog', 'usage', 'rag', 'alerts'];
$sections = ['org' => '部门 / 用户 / API Key', 'catalog' => '厂商 / 模型 / 渠道 / 价格', 'usage' => '调用记录与费用', 'rag' => 'RAG 知识库', 'alerts' => '告警'];

ds_line($s->apply ? '== 正式写入演示数据 ==' : '== 演练模式：写入后会整体回滚，不留数据（加 --apply 正式写入） ==');
ds_line("数据库：{$dsn}");
ds_line('');

if ($s->apply) {
    $s->ensureLedgerTable();
    $pdo->beginTransaction();
    try {
        [$n] = $s->purge();
        $pdo->commit();
        if ($n > 0) {
            ds_line("先清掉了上一次写入的 {$n} 条演示记录。");
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        ds_line('清理旧演示数据失败：' . $e->getMessage());
        exit(1);
    }
}

$ctx = [];
$failedSections = [];
$pdo->beginTransaction();
try {
    foreach ($sections as $key => $label) {
        if (!in_array($key, $only, true)) {
            continue;
        }
        $fn = "ds_seed_" . $key;
        $snapshot = [$s->ledger, $s->created, $s->stats, $ctx];
        $pdo->exec("SAVEPOINT sec_{$key}");
        $t0 = microtime(true);
        try {
            $fn($s, $ctx);
            $pdo->exec("RELEASE SAVEPOINT sec_{$key}");
            ds_line(sprintf('✓ %s（%.1fs）', $label, microtime(true) - $t0));
        } catch (Throwable $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT sec_{$key}");
            [$s->ledger, $s->created, $s->stats, $ctx] = $snapshot;
            $failedSections[$label] = $e->getMessage();
            ds_line("✗ {$label}：已跳过这一块");
            ds_line('    ' . str_replace("\n", "\n    ", $e->getMessage()));
        }
    }
    if ($s->apply) {
        $s->writeLedger();
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ds_line('出错，已全部回滚：' . $e->getMessage());
    exit(1);
}

ds_line('');
ds_line($s->apply ? '写入结果：' : '演练结果（已回滚）：');
ksort($s->stats);
foreach ($s->stats as $t => $n) {
    ds_line(sprintf('  %-40s %6d', $t, $n));
}
if (isset($ctx['records'])) {
    ds_line("  调用记录覆盖最近 " . (int)($opts['days'] ?? 30) . " 天，共 {$ctx['records']} 次请求，币种 {$s->currency}");
}
if ($s->notes !== []) {
    ds_line('');
    ds_line('说明：');
    foreach (array_unique($s->notes) as $n) {
        ds_line('  - ' . $n);
    }
}
if ($s->warnings !== []) {
    ds_line('');
    ds_line('自动调整：');
    foreach ($s->warnings as $w => $n) {
        ds_line('  - ' . $w . ($n > 1 ? "（{$n} 次）" : ''));
    }
}
if ($s->apply && $s->credentials !== []) {
    ds_line('');
    foreach ($s->credentials as $c) {
        ds_line('  ' . $c);
    }
}
ds_line('');
if ($failedSections !== []) {
    ds_line('有 ' . count($failedSections) . ' 块没写进去。把上面的报错和 `--inspect` 的输出发给 Claude 即可调整。');
}
ds_line($s->apply
    ? '完成。清理：php seed-demo-data.php --purge'
    : '演练完成，没有写入任何数据。确认无误后加 --apply 正式写入。');
exit($failedSections === [] ? 0 : 3);
