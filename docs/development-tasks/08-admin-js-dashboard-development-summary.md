# 08 Admin 后台和智慧大屏开发总结

## 总结

08 任务已完成 Admin 后台、智慧大屏、Dashboard 聚合查询、权限化菜单、资源管理页、系统设置入口、安全/性能/多模态分析、下钻入口、CSP 和测试闭环。

本轮收口重点处理了三个剩余缺口：

- Dashboard 查询链路改为读取 `usage_aggregates_minute/hour/day` 等聚合表，并应用 `PermissionService` 的权限范围过滤，避免排行和趋势接口扫描大明细表。
- 性能看板补齐延迟分桶写入和 P95/P99 计算，旧库通过幂等迁移补齐 `latency_bucket_*` 字段。
- 分享版 `/screen/` 按当前 09 任务约束只请求 `realtime`、`trends`、`rankings`、`security`、`performance` 必需指标；Admin Dashboard 保留多模态分析能力。

## 已交付范围

### 前端架构

- Admin 前端继续使用 `public/admin/index.html`、`public/admin/assets/js/*`、`public/admin/assets/css/app.css`，没有引入 TypeScript、npm 构建链或前端状态库。
- `api.js` 统一封装同源请求、CSRF、401/403/419、非 JSON 响应、错误消息和 `request_id` 展示。
- 前端不使用 `localStorage` / `sessionStorage` 保存管理员 Token、API Key 明文或厂商密钥。
- 动态内容使用 `textContent`、`replaceChildren` 和节点 API 渲染，静态测试覆盖敏感 console 输出和 `innerHTML` 赋值约束。

### Admin 管理台

- 权限菜单按 `me.data.user.role` 渲染：
  - `user` 仅显示本人可查资源。
  - `admin` 显示部门范围内用户、Key、用量、多模态、媒体、导出等资源。
  - `super_admin` 显示厂商、模型、价格、WAF、脱敏、日志留存、系统设置等全局配置。
- 资源页覆盖用户、部门、厂商、模型、价格、API Key、用量、内容归档、多模态任务、媒体资产、导出任务、WAF、脱敏、日志留存、审计日志、系统设置。
- API Key 创建后的一次性明文展示保留在创建响应区域，列表不回显明文。
- 通用表格支持筛选、分页、排序、创建/编辑弹窗、资源操作按钮和 Dashboard 下钻跳转。

### Dashboard 后端

- `DashboardService` 提供：
  - `realtime`
  - `trends`
  - `rankings`
  - `security`
  - `performance`
  - `multimodal`
- 趋势和排行支持 `department_id`、`user_id`、`provider_id`、`model_id`、`capability`、`from`、`to`、`sort` 等白名单参数。
- 排行维度覆盖部门、用户、模型、厂商、API Key，排序支持调用次数、Token、模态次数、异常数。
- Dashboard 聚合查询通过 `PermissionService::tableScopeSql($actor, 'usage_records')` 套用 actor 权限范围，服务端权限仍是最终裁决。
- `/screen-api/dashboard/{metric}` 复用同一 `DashboardService` 聚合逻辑，并根据分享会话的 `scope_type` / `scope_ids` 限制数据范围。

### 聚合与性能指标

- `UsageService` 写入 `usage_records` 后同步更新 minute/hour/day 聚合表。
- 聚合表新增延迟分桶：
  - `latency_bucket_100`
  - `latency_bucket_300`
  - `latency_bucket_1000`
  - `latency_bucket_3000`
  - `latency_bucket_over_3000`
- `DashboardService::performance()` 基于延迟分桶计算 `avg_latency_ms`、`p95_latency_ms`、`p99_latency_ms`。
- 新库在 `0001_create_tokenhub_schema.sql` 中直接具备分桶字段，旧库通过 `2026_07_08_000010_foundation_usage_aggregate_latency_buckets.sql` 幂等补齐。

### 安全与 WAF

- Caddy CSP 已覆盖 Admin/Screen 所需策略：
  - 允许同源模块脚本和样式。
  - 阻止外部脚本和内联脚本。
  - 保留 `frame-ancestors 'none'`、`base-uri 'self'`、`form-action 'self'` 等安全头。
- WAF 规则、WAF 发布版本、WAF 事件和安全日志入口已加入 super admin 资源。
- WAF 片段生成会在阻断规则中写入 `vars waf_rule` / `vars waf_action`，`caddy-security.jsonl` 导入后可保留命中规则编码和动作。
- 安全看板展示 WAF 命中、IP 拦截、脱敏事件、导出和审计相关统计。

### 智慧大屏

- Admin Dashboard 支持全局指标、趋势、排行、安全、性能、多模态分析和下钻入口。
- 分享版 `/screen/{share_code}` 自动从路径解析 `share_code`，不要求用户手动输入分享码。
- 分享版大屏支持登录、会话检查、退出、链接停用/未认证错误处理。
- 分享版大屏当前按 09 任务约束只请求必需指标，不暴露多模态面板或下载 URL 接口。

## API 与路由

保留并扩展以下前缀：

- `/admin-api/*`
- `/screen-api/*`

Dashboard 相关接口：

- `GET /admin-api/dashboard/realtime`
- `GET /admin-api/dashboard/trends`
- `GET /admin-api/dashboard/rankings`
- `GET /admin-api/dashboard/security`
- `GET /admin-api/dashboard/performance`
- `GET /admin-api/dashboard/multimodal`
- `GET /screen-api/dashboard/{metric}`

分享大屏相关接口由 09 任务继续承接，包括分享链接、访问日志、密码重置、会话撤销和 `/screen/{share_code}` 静态入口。

## 数据与迁移

- `0001_create_tokenhub_schema.sql` 已包含 Dashboard 所需主表、聚合表、WAF 表、审计表、导出表和系统设置表。
- `2026_07_08_000010_foundation_usage_aggregate_latency_buckets.sql` 为旧库补齐 minute/hour/day 聚合表的延迟分桶。
- 兼容迁移已调整为 `information_schema + PREPARE` 的幂等写法，避免 MySQL 环境中 `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS` 语法不兼容。
- 当前迁移 runner 执行全部 `database/migrations/*.sql`，历史 no-op 兼容迁移保留用于迁移记录连续性。

## 测试覆盖

新增和强化的测试覆盖：

- `DashboardServiceSourceTest`：确认 Dashboard 使用聚合表，不直接 `FROM usage_records` / `JOIN usage_records` 做趋势或排行聚合；确认安全、性能、多模态、权限过滤和 P95/P99 相关源码约束。
- `DashboardScreenScopeTest`：确认分享大屏 metric 白名单、scope 限制和无下钻时隐藏详情 ID。
- `AdminFrontendSecurityTest`：确认 Admin JS 不使用本地存储、不打印敏感响应、不用动态 `innerHTML`，并检查 CSP、WAF 版本资源和 Caddy 安全日志配置。
- `ScreenShareFrontendTest`：确认 `/screen/{share_code}` 路径解析、分享登录错误处理、必需指标限制、无多模态面板和无下载 URL 暴露。
- `WafServiceTest`：确认 WAF 片段生成、发布回滚、阻断规则日志变量和 `caddy-security` 日志导入。
- `UsageWritePathTest` / `WorkerServiceTest`：覆盖用量写入与聚合更新路径。

## 验证结果

已在 Docker PHP/MySQL/Redis 环境验证：

```bash
docker compose run --rm --entrypoint php php bin/migrate.php
docker compose run --rm --entrypoint php php bin/run-tests.php
```

结果：

- 迁移执行完成，当前测试库已具备审计列、多模态资产列和聚合延迟桶字段。
- 全量测试通过：`33 test file(s) passed.`

前端 JS 语法检查：

```bash
Get-ChildItem public/admin/assets/js,public/screen/assets -Filter *.js | ForEach-Object { node --check $_.FullName }
```

结果：所有 Admin/Screen JS 文件通过 `node --check`。

## 注意事项

- 当前验证以 Docker PHP 环境为准；本机 shell 环境不依赖直接安装 PHP。
- 当前目录不是可用 git 工作树状态，无法用 `git diff` / commit 记录作为交付凭据；交付状态以文件内容、迁移执行和测试结果为准。
- 分享版 `/screen/` 的功能边界受 09 任务约束限制：不请求多模态指标，不暴露媒体下载 URL；Admin Dashboard 仍保留完整多模态分析。
- 建议后续在浏览器登录态下做一次人工验收：API Key 一次性明文展示、Dashboard 下钻、WAF 发布/回滚、Screen 分享登录和强制下线。
