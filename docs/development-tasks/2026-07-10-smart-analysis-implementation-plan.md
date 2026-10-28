# 智慧分析 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将管理后台“智能大屏”改为“智慧分析”，支持部门筛选、员工关键词筛选，并同时展示部门与员工用量排行。

**Architecture:** 在 `DashboardService` 的聚合查询入口集中解析员工关键词，并把它转为受当前管理员范围约束的 `user_id` 集合。前端从现有受权限保护的部门资源读取筛选项，把部门 ID 和员工关键词附加到所有仪表请求；排行请求拆为部门和员工两个并行请求，其他图表共享同一筛选口径。

**Tech Stack:** 原生 PHP 8、MySQL 聚合表、原生 ES Modules、Docker Compose PHP 测试运行时。

---

## 文件结构

- `app/Services/DashboardService.php`：解析 `employee_query`，在聚合表查询中追加受权限约束的员工集合条件。
- `public/admin/assets/js/dashboard.js`：渲染部门与员工筛选，传递统一参数，并同时展示部门、员工用量排行。
- `public/admin/assets/js/resources.js`：将运营入口 `dashboard` 的最终导航名称统一为“智慧分析”。
- `tests/Services/DashboardServiceEmployeeFilterTest.php`：验证员工关键词的匹配、部门叠加与管理员范围隔离。
- `tests/Frontend/AdminSmartAnalysisFrontendTest.php`：验证导航名称、筛选控件、统一请求参数和双排行渲染契约。
- `docs/development-tasks/2026-07-10-smart-analysis-design.md`：已确认的设计说明，不再改动功能边界。

## Task 1: 锁定员工关键词聚合条件

**Files:**

- Create: `tests/Services/DashboardServiceEmployeeFilterTest.php`
- Modify: `app/Services/DashboardService.php`

- [ ] **Step 1: 写出失败的服务回归测试**

在测试中创建两个部门、各自员工及对应 `usage_aggregates_day` 行；以管理员上下文调用 `DashboardService::rankings()`。断言 `employee_query` 仅返回用户名包含关键词的员工，`department_id` 与关键词交集只返回该部门员工，且管理员不能通过关键词命中其权限范围外的员工。

```php
$rows = $service->rankings([
    'dimension' => 'user',
    'sort' => 'total_tokens',
    'from' => '2026-07-01 00:00:00',
    'employee_query' => 'alice',
]);
assert_same([$aliceId], array_map(static fn (array $row): int => (int) $row['id'], $rows), 'Employee keyword should restrict rankings to matching accessible users.');
```

- [ ] **Step 2: 运行该测试并确认失败原因是功能尚未实现**

Run: `docker compose exec -T php php tests/Services/DashboardServiceEmployeeFilterTest.php`

Expected: 失败，关键词仍未参与 `DashboardService` 聚合条件。

- [ ] **Step 3: 在聚合条件中实现最小关键词过滤**

在 `aggregateWhere()` 的通用 ID 条件之后调用一个私有 helper。该 helper 去除关键词首尾空白；空关键词不添加条件。非空关键词用当前管理员的 `PermissionService::tableScopeSql($actor, 'users')` 作为子查询范围，并以参数化 `username LIKE ?` 查询匹配 ID；匹配为空时追加 `1 = 0`，否则追加 `user_id IN (...)`。不要将原始关键词拼入 SQL。

```php
private function employeeQueryWhere(array $query, ?array $actor): array
{
    $keyword = trim((string) ($query['employee_query'] ?? ''));
    if ($keyword === '') {
        return ['', []];
    }
    [$scope, $scopeBindings] = (new PermissionService())->tableScopeSql($actor ?? [], 'users');
    $users = App::db()->select('SELECT id FROM users' . $scope . (trim($scope) === '' ? ' WHERE' : ' AND') . ' username LIKE ?', [...$scopeBindings, '%' . $keyword . '%']);
    // 返回可追加到 usage_aggregates_* 查询的条件和绑定参数。
}
```

- [ ] **Step 4: 运行该测试并确认通过**

Run: `docker compose exec -T php php tests/Services/DashboardServiceEmployeeFilterTest.php`

Expected: PASS。

## Task 2: 增加智慧分析页面的前端筛选与双排行

**Files:**

- Create: `tests/Frontend/AdminSmartAnalysisFrontendTest.php`
- Modify: `public/admin/assets/js/dashboard.js`

- [ ] **Step 1: 写出失败的前端契约测试**

断言页面源文件包含部门选择器、员工搜索框、`employee_query` 查询参数、`dimension: "department"` 与 `dimension: "user"` 的两个排行请求，以及“部门用量排行”和“员工用量排行”标题。测试还应断言现有趋势、安全、性能、多模态接口仍从同一个 `params` 对象取得筛选条件。

```php
assert_true(str_contains($source, 'select("department_id"'), 'Smart analysis should expose a department filter.');
assert_true(str_contains($source, 'name: "employee_query"'), 'Smart analysis should expose employee keyword input.');
assert_true(str_contains($source, 'dimension: "department"'), 'Smart analysis should request department usage rankings.');
assert_true(str_contains($source, 'dimension: "user"'), 'Smart analysis should request employee usage rankings.');
```

- [ ] **Step 2: 运行该测试并确认失败原因是控件和双排行尚不存在**

Run: `docker compose exec -T php php tests/Frontend/AdminSmartAnalysisFrontendTest.php`

Expected: 失败，断言找不到新增的筛选与排行契约。

- [ ] **Step 3: 实现部门选项加载与员工即时搜索**

在 `renderDashboard()` 中通过既有受认证的 `/admin-api/departments` 读取部门列表并生成“全部部门”加选项；将 `department_id` 加入 `params`。新增 `input[name="employee_query"]`，输入时用短防抖调用 `load()`；提交、部门变化和刷新按钮立即调用 `load()`。空搜索词不得传递为有效过滤值。

```js
const employeeQuery = el("input", { type: "search", name: "employee_query", placeholder: "搜索员工账号" });
const params = {
  bucket: bucket.value,
  sort: sort.value,
  from: range.from,
  to: range.to,
  department_id: department.value,
  employee_query: employeeQuery.value.trim()
};
```

- [ ] **Step 4: 实现双排行请求和面板渲染**

保留现有趋势、安全、性能和多模态并行请求。用 `Promise.all` 额外发起两次 `/admin-api/dashboard/rankings`，分别传入 `{ ...params, dimension: "department" }`、`{ ...params, dimension: "user" }`；在图表网格中使用现有 `rankingChart()` 分别渲染“部门用量排行”和“员工用量排行”。不要修改独立分享大屏的任何 JavaScript。

```js
const [realtime, trends, departmentRankings, userRankings, security, performance, multimodal] = await Promise.all([
  api("/admin-api/dashboard/realtime", { query: params }),
  api("/admin-api/dashboard/trends", { query: params }),
  api("/admin-api/dashboard/rankings", { query: { ...params, dimension: "department" } }),
  api("/admin-api/dashboard/rankings", { query: { ...params, dimension: "user" } }),
  api("/admin-api/dashboard/security", { query: params }),
  api("/admin-api/dashboard/performance", { query: params }),
  api("/admin-api/dashboard/multimodal", { query: params })
]);
```

- [ ] **Step 5: 运行新增前端测试并确认通过**

Run: `docker compose exec -T php php tests/Frontend/AdminSmartAnalysisFrontendTest.php`

Expected: PASS。

## Task 3: 统一导航名称并回归现有分析页

**Files:**

- Modify: `public/admin/assets/js/resources.js`
- Modify: `tests/Frontend/AdminDashboardChartsFrontendTest.php`

- [ ] **Step 1: 先扩展现有图表回归测试并确认失败**

为 `resources.js` 增加“智慧分析”导航断言；在图表页测试中断言双排行不替换趋势、安全、性能和多模态渲染入口。保留现有 “智慧大屏” 断言前，先改为期待新名称，确保当前源代码失败。

```php
assert_true(str_contains($resources, '"dashboard": { label: "智慧分析", group: "运营" }'), 'Operations navigation should label the dashboard as Smart Analysis.');
assert_true(str_contains($dashboardSource, '"部门用量排行"'), 'Dashboard should retain a department ranking panel.');
assert_true(str_contains($dashboardSource, '"员工用量排行"'), 'Dashboard should retain an employee ranking panel.');
```

- [ ] **Step 2: 运行前端回归测试并确认失败原因正确**

Run: `docker compose exec -T php php tests/Frontend/AdminDashboardChartsFrontendTest.php`

Expected: 失败，导航仍为“智能大屏”且双排行标题未出现。

- [ ] **Step 3: 将运营入口名称改为“智慧分析”**

仅修改 `resources.js` 中 `dashboard` 的运营导航元数据为 `"智慧分析"`。不得修改 `public/screen/*`、`ScreenService`、`ScreenController`、`/screen-api/*` 路由或分享链接资源名称。

- [ ] **Step 4: 运行前端回归测试并确认通过**

Run: `docker compose exec -T php php tests/Frontend/AdminDashboardChartsFrontendTest.php`

Expected: PASS。

## Task 4: 集成验证

**Files:**

- Verify: `tests/Services/DashboardServiceEmployeeFilterTest.php`
- Verify: `tests/Frontend/AdminSmartAnalysisFrontendTest.php`
- Verify: `tests/Frontend/AdminDashboardChartsFrontendTest.php`
- Verify: `tests/Frontend/ScreenShareFrontendTest.php`

- [ ] **Step 1: 运行所有本次相关测试**

Run: `docker compose exec -T php php tests/Services/DashboardServiceEmployeeFilterTest.php; docker compose exec -T php php tests/Frontend/AdminSmartAnalysisFrontendTest.php; docker compose exec -T php php tests/Frontend/AdminDashboardChartsFrontendTest.php; docker compose exec -T php php tests/Frontend/ScreenShareFrontendTest.php`

Expected: 四个命令全部以退出码 `0` 完成。

- [ ] **Step 2: 运行 PHP 语法检查**

Run: `docker compose exec -T php php -l app/Services/DashboardService.php`

Expected: `No syntax errors detected`。

- [ ] **Step 3: 审查变更边界**

检查变更只涉及分析页、聚合筛选条件、测试和文档；确认没有修改 `.env`、Docker Compose、Caddy、`public/screen/*`、`/screen-api/*` 或分享权限逻辑。

- [ ] **Step 4: 保留工作区变更供用户审阅**

当前工作区状态无法由 Git 工具可靠读取，且可能包含用户已有改动；不要自动提交、重置或清理。交付时列出实际修改文件和每个验证命令的结果。

## 计划自检

- 规格覆盖：导航更名、部门筛选、员工即时搜索、部门与员工双排行、既有分析保留、权限约束、分享大屏隔离均有对应任务。
- 占位符：没有待办标记或未指定的测试命令。
- 一致性：前端统一使用 `department_id`、`employee_query`，后端在 `aggregateWhere()` 解析同名参数；双排行维度固定为 `department` 和 `user`。
