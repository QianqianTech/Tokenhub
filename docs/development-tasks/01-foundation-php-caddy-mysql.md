# 01 基础工程任务清单

## 目标

搭建 TokenHub 的最小可运行基础：原生 PHP 服务、Caddy 入口、MySQL、Redis、队列、配置、目录结构和健康检查。

## 前置依赖

- PHP 8.2+
- Caddy 2.x
- MySQL 9.7
- Redis 6+
- Composer 仅用于自动加载和小型基础库，不引入完整后端框架

## 建议目录

```text
app/
  Bootstrap/
  Http/
  Middleware/
  Services/
  Models/
  Support/
  Console/
config/
database/
  migrations/
public/
  index.php
  admin/
storage/
  logs/
  exports/
  media/
```

## 开发任务

- [ ] 创建原生 PHP 项目目录，配置 Composer PSR-4 自动加载。
- [ ] 实现 `public/index.php` 统一入口，只负责加载 Bootstrap、路由分发和异常兜底。
- [ ] 实现 `Support\Router`，支持 `GET`、`POST`、`PUT`、`PATCH`、`DELETE` 路由注册和匹配。
- [ ] 实现 `Support\Response`，统一 JSON 响应、错误响应、下载响应和 SSE 响应头。
- [ ] 实现 `Support\Config`，加载环境变量和 `config/*.php` 配置。
- [ ] 实现 `Support\Database`，基于 PDO 管理 MySQL 连接、事务和查询异常。
- [ ] 实现 `Support\RedisClient`，封装 Redis 连接、缓存、限额计数和队列基础操作。
- [ ] 实现全局 `request_id` 和 `trace_id` 生成，中间件、日志、响应头统一携带。
- [ ] 建立 MySQL 基础迁移框架，支持按文件顺序执行迁移和记录迁移版本。
- [ ] 创建基础表：`system_settings`、`audit_logs`、`log_retention_policies`。
- [ ] 实现健康检查接口：`GET /health`、`GET /admin-api/health`。
- [ ] 编写 Caddyfile，包含 HTTPS、反向代理、静态 Admin 托管、日志输出和安全响应头。
- [ ] 配置 PHP-FPM 运行方式，确认 Caddy 可以代理到 PHP 服务。
- [ ] 配置本地开发环境：PHP、Caddy、MySQL、Redis、queue-worker。
- [ ] 编写基础队列 Worker，支持从 Redis 队列取任务、执行、失败重试和失败日志。
- [ ] 设置 `.env.example`，包含 DB、Redis、队列、日志、导出、媒体、Caddy 相关配置。

## 测试任务

- [ ] 空库执行迁移，确认 `system_settings`、`audit_logs`、`log_retention_policies` 创建成功。
- [ ] 请求 `GET /health` 返回 `200`，包含 PHP、MySQL、Redis 状态。
- [ ] 停止 MySQL 后请求健康检查，返回非健康状态但不泄露数据库密码和内部路径。
- [ ] Caddy 访问 `/admin/` 能返回静态页面。
- [ ] Caddy 访问 `/admin-api/health` 能反代到 PHP。
- [ ] Caddy 日志不记录 `Authorization`、`Cookie`、API Key 明文。
- [ ] Queue Worker 可以消费测试任务并写入执行日志。

## 验收标准

- 可以在本地启动 Caddy、PHP-FPM、MySQL、Redis 和 Worker。
- 健康检查可区分服务正常、数据库异常、Redis 异常。
- 项目不依赖 Laravel、Hyperf、ThinkPHP、Symfony 等完整后端框架。
- 所有入口请求都有 `request_id`。
- 基础日志、审计日志和错误响应不泄露敏感信息。
