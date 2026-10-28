# TokenHub 开发文档

版本：v1.0  
日期：2026-07-08

开发任务清单已拆分到 `docs/development-tasks/`，总入口为 `docs/development-tasks/00-task-index.md`。

## 1. 项目目标

TokenHub 是一个统一的大模型 Token 服务管理平台，用于对接多个大模型 Token 厂商，并对企业内部用户、部门、API Key、模型用量、调用次数、输入输出 Token、可选成本和限额进行集中管理。

平台核心目标：

- 对外提供 OpenAI 兼容格式和 Anthropic 兼容格式 API。
- 通过 OpenAI/Anthropic 兼容 API 支持文本、视觉理解、ASR 语音识别、TTS 语音合成、文生图、文生视频等推理能力。
- 支持多个 Token 厂商、多个模型，以及可选的价格配置和成本查看。
- 记录每个用户、每个模型、每个 Key 的调用明细、Token 用量、次数和可选成本。
- 支持超级管理员、管理员、普通用户三级权限，以及适配不同公司组织结构的多级部门管理。
- 提供智慧大屏，展示用量、可选成本、调用趋势、模型占比、用户排行和异常状态。
- 对外生成 API Key，并绑定用户、部门、模型权限和限额策略。
- 全系统以数据不外泄为安全底线，用户输入、模型输出、厂商密钥、API Key、统计明细和导出文件都必须受权限、加密、审计和留存策略保护。

## 2. 角色和权限

### 2.1 角色定义

| 角色 | 权限范围 |
| --- | --- |
| 超级管理员 | 管理所有租户级配置、厂商、模型、价格、用户、部门、Key、限额、系统配置和全局统计 |
| 管理员 | 管理本部门或授权部门下的用户、Key、限额、用量统计和部门数据 |
| 普通用户 | 查看自己的 Key、调用明细、用量统计、限额状态，可按授权范围调用模型 |

### 2.2 权限规则

- 超级管理员拥有全局读写权限。
- 管理员只能访问自己负责部门及其子部门数据，部门层级按树结构向下递归生效。
- 普通用户只能访问自己的数据。
- API Key 必须绑定所属用户，用户必须属于一个部门。
- 删除用户时，不直接删除调用明细，应将用户标记为禁用或离职。
- 删除部门时，若存在用户、子部门、Key 或统计数据，不允许物理删除。
- 部门支持多级层级，不固定层数；不同公司可按事业部、中心、部门、小组、项目组等结构自行维护。
- 所有数据查询必须带权限范围过滤，禁止仅依赖前端隐藏菜单控制访问。
- 管理员查看跨用户内容、导出数据、修改安全配置时必须写入审计日志。

## 3. 总体架构

### 3.1 方案结构总览

TokenHub 按分层架构设计，避免把网关、安全、统计、内容留存、多模态任务和管理后台混在一起。

```text
访问层
  Caddy / HTTPS / IP 网段限制 / 增强 WAF / 证书自动续期

网关层
  OpenAI 兼容 API / Anthropic 兼容 API / API Key 鉴权 / 限额 / 路由 / 流式透传

适配层
  OpenAI / Anthropic / 腾讯云 / 阿里云 / 火山 / 百度 / 讯飞等厂商适配器

计量层
  Token / 次数 / 音频秒数 / TTS 字符数 / 图片数量 / 视频秒数 / 可选成本

安全层
  权限 / IP 白名单 / AST 脱敏 / 内容留存 / 审计 / 日志脱敏 / 导出控制

数据层
  用户 / 部门 / Key / 模型 / 价格 / 明细 / 聚合 / 多模态任务 / 媒体资产

运营层
  智慧大屏 / 用量明细 / 性能监控 / 日志查询 / 告警 / 报表导出
```

核心原则：

- Caddy 只做入口层能力，不承载业务权限和计量逻辑。
- TokenHub Gateway 是业务强校验点，所有外部请求都必须经过 Gateway。
- 厂商原生接口不直接对外暴露。
- 明细、日志、内容留存、导出都必须脱敏和审计。
- 统计和日志写入尽量异步化，主链路优先保证高速转发。

### 3.2 模块划分

| 模块 | 说明 |
| --- | --- |
| 用户与部门模块 | 用户、角色、部门、权限范围、登录认证 |
| 厂商管理模块 | 配置 OpenAI、Anthropic 及兼容厂商的服务地址、鉴权方式、状态 |
| 模型管理模块 | 模型名称、格式类型、模态类型、所属厂商、上下文长度、是否启用 |
| 价格配置模块 | 可选配置输入 Token 单价、输出 Token 单价、缓存 Token 单价、请求单价、币种，用于查看成本 |
| API Key 模块 | 对外 Key 生成、绑定用户、模型权限、限额、启停、过期时间 |
| 代理转发模块 | 接收外部请求，鉴权、限额校验、路由到目标厂商，统一响应 |
| 入口代理模块 | 使用 Caddy 或同类反向代理处理 TLS、HTTP/2、HTTP/3、压缩、访问日志、IP 网段限制、增强 WAF 和负载均衡 |
| 用量统计模块 | 记录请求明细、Token 用量、可选成本、耗时、状态码、错误信息 |
| 多模态任务模块 | 管理 ASR、TTS、文生图、文生视频等异步任务、文件资产和状态回调 |
| 内容留存模块 | 可选保存用户请求内容和模型生成内容，支持查看权限、脱敏和保留天数配置 |
| AST 脱敏模块 | 对代码、JSON、YAML、SQL、XML 等结构化内容做语法树级敏感信息识别和替换 |
| 智慧大屏模块 | 汇总展示平台用量、可选成本、趋势、排行、模型占比和异常告警 |
| 审计日志模块 | 记录登录、配置变更、Key 操作、限额调整、厂商配置变更 |

### 3.3 请求链路

1. 客户端使用 TokenHub 生成的 API Key 发起 OpenAI 兼容或 Anthropic 兼容格式请求。
2. Caddy 作为入口代理处理 TLS、HTTP/2、HTTP/3、连接复用、IP 网段限制、增强 WAF、基础限流和负载均衡。
3. TokenHub 应用网关识别 Key，加载用户、部门、模型权限和限额配置。
4. 校验 Key 状态、过期时间、模型权限、调用次数、Token 限额，以及启用价格后的成本限额。
5. 根据请求格式和模型配置选择目标厂商。
6. 根据外部协议转换或透传请求到 OpenAI、Anthropic 或内部厂商原生多模态适配器。
7. 如果启用脱敏策略，对请求内容执行 AST 脱敏或普通文本脱敏。
8. 对文本流式响应采用边读边写的流式透传，避免完整缓存响应体。
9. 解析上游响应中的 Token、音频时长、图片数量、视频时长、任务状态、耗时和错误。
10. 异步写入调用明细，并更新分钟、小时、日维度聚合统计。
11. 如果启用内容留存，按策略异步保存原文、脱敏内容或仅保存摘要。
12. 返回兼容原格式的响应给客户端。

## 4. 技术栈和开发规范

本项目后端以 PHP 为主，入口代理使用 Caddy，数据库使用 MySQL。整体目标是优先保证稳定、易部署、易维护，同时支持高并发转发、异步统计和安全审计。

### 4.1 技术选型

| 层级 | 技术 | 说明 |
| --- | --- | --- |
| 后端语言 | PHP 8.2+ | 主要业务开发语言 |
| 后端架构 | 原生 PHP 自研服务 | 不使用 Laravel、Hyperf、ThinkPHP、Symfony 等完整后端框架 |
| 入口代理 | Caddy 2.x | HTTPS、自动证书、反向代理、增强 WAF、IP 网段限制 |
| 数据库 | MySQL 9.7 | 用户、部门、Key、模型、明细、统计、审计等核心数据 |
| 缓存 | Redis 6+ | Key 状态、权限缓存、限额计数、并发计数 |
| 队列 | Redis Queue / RabbitMQ / MySQL Queue | 明细写入、聚合统计、日志归档、导出任务 |
| 对象存储 | 本地文件 / MinIO / S3 兼容存储 | 图片、音频、视频、导出文件、归档日志 |
| Admin 前端 | 原生 JavaScript / TypeScript + ES Modules | 自研管理后台、智慧大屏、日志中心 |
| 日志 | 独立文件 + 可选 ELK/Loki/ClickHouse | 先落本地独立日志文件，再扩展集中日志 |
| 部署 | Linux + PHP-FPM + Caddy | Caddy 作为唯一入口，PHP-FPM 处理 PHP API |

推荐组合：

- 生产推荐：PHP 8.2 + 原生 PHP 服务 + PHP-FPM + Caddy + MySQL + Redis + 队列。
- 管理后台推荐：Caddy 托管静态 JS/CSS/HTML，Admin 前端通过同域 `/admin-api/*` 调用 PHP 管理接口。
- 不引入后端框架，但可以使用 Composer 自动加载、PDO、Redis 扩展、HTTP Client、日志库、CSV 库等小型基础库。

### 4.2 PHP 服务划分

建议将 PHP 服务拆成以下逻辑模块：

```text
app/
  Bootstrap/
    app.php
    container.php
  Http/
    Controllers/
      Admin/
      OpenAICompat/
      AnthropicCompat/
  Services/
    Auth/
    Providers/
    Routing/
    Quota/
    Usage/
    ContentRetention/
    Desensitization/
    Dashboard/
    Export/
    Logging/
  Jobs/
    WriteUsageRecordJob.php
    AggregateUsageJob.php
    CleanupContentJob.php
    ExportCsvJob.php
    CleanupLogsJob.php
  Models/
  Middleware/
    AuthMiddleware.php
    CsrfMiddleware.php
    PermissionMiddleware.php
    WafMiddleware.php
  Support/
    Router.php
    Response.php
    Validator.php
    Database.php
  Console/
public/
  admin/
    index.html
    assets/
      app.js
      app.css
config/
database/
  migrations/
  seeders/
storage/
  logs/
  exports/
  media/
```

核心服务说明：

| 服务 | 职责 |
| --- | --- |
| `ProviderAdapter` | 对接 OpenAI、Anthropic、腾讯云等厂商 |
| `ModelRouter` | 按模型、部门、Key、优先级选择厂商 |
| `QuotaService` | 次数、Token、音频、图片、视频、成本限额校验 |
| `UsageService` | 调用明细、聚合统计、成本计算 |
| `ContentRetentionService` | 请求内容和生成内容保存、清理、查看 |
| `DesensitizationService` | AST 脱敏、文本脱敏、出站脱敏 |
| `DashboardService` | 大屏统计、排行、趋势、异常分析 |
| `ExportService` | 日志导出、会话存档 CSV、明细导出 |
| `LogService` | 独立日志文件、脱敏、轮转、归档 |

### 4.3 JS 管理后台结合方式

Admin 后台由项目自研，不依赖后端框架生成页面。推荐采用静态 HTML + 原生 JavaScript/TypeScript + ES Modules 的方式构建，Caddy 直接托管静态资源，PHP 只提供 Admin API。

页面形态：

```text
Browser
  -> Caddy /admin
  -> static admin/index.html, app.js, app.css

Browser
  -> Caddy /admin-api/*
  -> PHP Admin API
  -> MySQL / Redis / Queue
```

JS 与 PHP 的结合规则：

- Admin 前端只调用 `/admin-api/*`，不直接访问数据库、Redis、对象存储或厂商密钥。
- Admin 登录使用服务端会话或短期访问令牌；管理端优先使用 `HttpOnly`、`Secure`、`SameSite=Strict` Cookie。
- JS 只保存 UI 状态，不在 `localStorage` 或 `sessionStorage` 保存管理员 Token、API Key 明文、厂商密钥、导出链接长期凭证。
- 所有表单提交经过 PHP Admin API 校验，前端校验只作为体验优化。
- 大屏数据、排行、日志查询统一从聚合接口获取，不允许前端拼接 SQL 条件或透传任意查询语句。
- 导出 CSV、会话存档、日志下载必须通过后端创建导出任务，JS 轮询任务状态，拿到短期下载链接后再下载。
- 多模态媒体预览只使用后端签发的短期 URL，禁止在 JS 中暴露对象存储永久地址。

JS 安全要求：

| 风险 | 要求 |
| --- | --- |
| XSS | 所有动态文本使用 `textContent` 渲染，禁止直接拼接 `innerHTML`；确需富文本时必须经过白名单净化 |
| CSRF | Admin API 对 Cookie 登录请求校验 CSRF Token；高危操作二次确认并写审计 |
| 会话泄露 | 管理端 Cookie 使用 `HttpOnly`、`Secure`、`SameSite=Strict`，后台空闲超时自动退出 |
| 越权访问 | 前端隐藏按钮只作为体验，权限必须由 PHP Admin API 再校验 |
| 敏感信息 | API Key 明文只展示一次；厂商密钥、Cookie、Authorization、导出链接不进入前端日志 |
| 供应链 | 尽量少用 npm 依赖；如使用构建工具，锁定版本、提交 lockfile、开启依赖审计 |
| CSP | Caddy 或 PHP 设置严格 CSP，默认禁止外部脚本，禁止内联脚本 |
| 点击劫持 | 设置 `X-Frame-Options: DENY` 或 CSP `frame-ancestors 'none'` |

推荐 Admin 目录：

```text
public/admin/
  index.html
  assets/
    js/
      api.js
      auth.js
      router.js
      permissions.js
      dashboard.js
      logs.js
      keys.js
      users.js
      departments.js
    css/
      base.css
      layout.css
      components.css
```

### 4.4 PHP 网关实现要求

- OpenAI/Anthropic 兼容接口必须由 PHP Gateway 统一处理。
- 流式响应必须使用边读边写，不能完整缓存 SSE 响应。
- 上游请求使用支持连接复用和超时控制的 HTTP Client。
- 调用明细、日志、聚合、导出任务必须异步化。
- 限额、Key 状态、权限缓存优先走 Redis。
- MySQL 不参与每次请求的高频同步写入，避免拖慢转发链路。
- 所有请求必须生成 `request_id` 和 `trace_id`。
- 后端不使用完整框架，路由、中间件、响应、配置、权限、异常处理由项目自研基础层统一封装。
- 自研基础层必须保持小而清晰，禁止把业务逻辑写入全局脚本或入口文件。

### 4.5 MySQL 设计规范

MySQL 作为核心业务数据库，需要保证字段类型、索引、归档和写入压力可控。

规范：

- 所有表使用 `utf8mb4` 字符集。
- 所有业务表包含 `id`、`created_at`、`updated_at`。
- 需要软删除的表增加 `deleted_at`。
- 金额和成本使用 `decimal`，不使用浮点数。
- Token、次数、秒数等计数字段使用 `bigint unsigned`。
- 大文本内容不要直接放在高频明细表，可拆到 `usage_contents` 或对象存储。
- `usage_records` 按时间建立索引，必要时按月分表或冷热归档。
- 日聚合、小时聚合、分钟聚合独立建表。
- 审计日志不允许普通管理员物理删除。

建议数据库连接：

- 管理后台读写连接。
- Gateway 高频读连接。
- Worker 写入连接。
- 报表查询只读连接。

### 4.6 Caddy 和 PHP 部署链路

PHP-FPM 部署链路：

```text
Client -> Caddy -> PHP-FPM -> PHP App -> MySQL / Redis / Queue
```

Caddy 职责：

- 监听 80/443。
- 自动 HTTPS 和证书续期。
- 反向代理到 PHP 服务。
- IP 网段限制。
- 增强 WAF。
- 访问日志脱敏。
- 负载均衡多个 PHP Gateway 实例。
- 托管 Admin 静态资源。
- 为 Admin 静态资源和 Admin API 设置安全响应头。

PHP 服务职责：

- API Key 鉴权。
- 权限、限额、模型路由。
- 协议兼容。
- 厂商适配。
- 用量解析。
- 内容留存和脱敏。
- 业务日志和审计。
- Admin API。

### 4.7 环境变量

建议环境变量：

```env
APP_ENV=production
APP_KEY=
APP_URL=https://api.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tokenhub
DB_USERNAME=tokenhub
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

QUEUE_CONNECTION=redis

LOG_PATH=/var/log/tokenhub
EXPORT_PATH=/var/lib/tokenhub/exports
MEDIA_PATH=/var/lib/tokenhub/media

CADDY_ADMIN_API=disabled
CONTENT_RETENTION_DAYS=30
LOG_RETENTION_DAYS=90
AUDIT_LOG_RETENTION_DAYS=180
ADMIN_SESSION_TTL_MINUTES=120
ADMIN_CSRF_SECRET=
CSP_REPORT_URI=
```

### 4.8 开发规范

- API 响应统一结构，兼容接口除外。
- 管理端接口必须使用统一鉴权中间件。
- 对外模型接口必须使用统一 Gateway 中间件：请求 ID、Key 鉴权、权限、限额、路由、日志。
- SQL 查询必须走 ORM 或查询构造器，禁止拼接 SQL。
- 所有导出任务必须异步。
- 所有敏感字段必须通过统一脱敏器处理。
- 所有配置变更必须写审计日志。
- 所有高频查询必须有索引和分页。
- 大屏和报表不得直接扫描大明细表。
- 自研 PHP 基础层必须有清晰目录，不允许业务代码散落在 `public/index.php`。
- Admin JS 必须走统一 `api.js` 封装，统一处理 CSRF、401、403、请求 ID 和错误提示。
- Admin JS 禁止打印敏感响应到 `console`。

### 4.9 本地开发环境

本地建议使用 Docker Compose 或本机服务：

```text
php
caddy
mysql
redis
queue-worker
```

本地启动后至少验证：

- 管理端登录。
- API Key 创建。
- OpenAI 兼容文本调用。
- 用量明细写入。
- MySQL 聚合任务。
- Caddy 反向代理。
- 日志文件生成。
- 队列任务执行。

## 5. 厂商对接设计

### 5.1 厂商类型

系统对外支持两类兼容 API，对内支持厂商原生适配器：

| 格式 | 典型接口 | 说明 |
| --- | --- | --- |
| OpenAI 格式 | `/v1/chat/completions`, `/v1/responses`, `/v1/embeddings` | 适用于 OpenAI 及 OpenAI 兼容厂商 |
| Anthropic 格式 | `/v1/messages` | 适用于 Anthropic Claude 及 Anthropic 兼容厂商 |
| 厂商原生适配器 | 腾讯云、阿里云、火山、百度、讯飞等原生 API | 仅内部使用，用于适配 ASR、TTS、文生图、文生视频等多模态服务 |

### 5.2 多模态能力类型

| 能力类型 | 说明 | 典型计量指标 |
| --- | --- | --- |
| `text_generation` | 文本生成、对话、推理 | 输入 Token、输出 Token、请求次数 |
| `vision_understanding` | 图片理解、多模态对话 | 输入 Token、输出 Token、图片数量、图片大小 |
| `embedding` | 文本向量、图片向量 | 输入 Token、向量条数 |
| `asr` | 语音识别，音频转文字 | 音频时长、文件大小、请求次数 |
| `tts` | 语音合成，文字转语音 | 字符数、音频时长、请求次数 |
| `image_generation` | 文生图、图生图、图片编辑 | 图片张数、分辨率、请求次数 |
| `video_generation` | 文生视频、图生视频、视频编辑 | 视频秒数、分辨率、任务次数 |

### 5.3 对外协议和能力映射

TokenHub 对外只暴露 OpenAI 兼容 API 和 Anthropic 兼容 API。不同厂商的真实接口差异由内部适配器处理。

| 推理能力 | OpenAI 兼容 API | Anthropic 兼容 API | 说明 |
| --- | --- | --- | --- |
| 文本生成 | `/v1/chat/completions`, `/v1/responses` | `/v1/messages` | 支持普通对话、系统提示词、流式输出 |
| 视觉理解 | `/v1/chat/completions`, `/v1/responses` | `/v1/messages` | 通过图片 content block 或 image input 传入 |
| Embedding | `/v1/embeddings` | Anthropic 风格扩展接口 | 可按模型能力开启 |
| ASR | `/v1/audio/transcriptions` | Anthropic 风格任务消息 | 音频转文本，记录音频秒数 |
| TTS | `/v1/audio/speech` | Anthropic 风格任务消息 | 文本转语音，记录字符数和音频秒数 |
| 文生图 | `/v1/images/generations` | Anthropic 风格任务消息 | 返回图片资产或异步任务 ID |
| 文生视频 | `/v1/videos/generations` | Anthropic 风格任务消息 | 通常为异步任务，返回任务 ID |
| 任务查询 | `/v1/tasks/{id}` | `/v1/messages/{task_id}` 或 `/v1/tasks/{id}` | 查询异步图片、视频、音频任务状态 |

Anthropic 官方接口本身主要覆盖消息和视觉理解。对 ASR、TTS、文生图、文生视频等能力，TokenHub 提供 Anthropic 风格的消息封装和任务返回结构，保持鉴权、错误、任务状态和内容块结构一致。

### 5.4 多模态厂商适配

腾讯云等云厂商通常不是 OpenAI 或 Anthropic 兼容格式，应通过厂商原生适配器接入。适配器负责鉴权签名、参数映射、任务提交、状态查询、结果下载和用量归一化。

适配原则：

- 文本类接口必须对外兼容 OpenAI 或 Anthropic 格式。
- ASR、TTS、文生图、文生视频优先使用统一内部任务模型，不强行模拟成文本对话接口。
- 同步接口直接返回结果；异步接口先返回任务 ID，再通过轮询或回调更新任务状态。
- 生成的图片、音频、视频作为资产保存，只在授权范围内访问。
- 每个厂商适配器必须把原生用量转换成统一指标，例如 Token、音频秒数、图片张数、视频秒数。

### 5.5 厂商配置字段

| 字段 | 说明 |
| --- | --- |
| `id` | 厂商 ID |
| `name` | 厂商名称，例如 OpenAI、Anthropic、DeepSeek、Moonshot、通义千问、腾讯云 |
| `format` | `openai`、`anthropic` 或 `vendor_native` |
| `capabilities` | 支持能力，例如文本、ASR、TTS、文生图、文生视频 |
| `base_url` | 上游服务地址 |
| `api_key` | 上游厂商密钥，加密存储 |
| `secret_id` | 云厂商 SecretId，可为空，加密存储 |
| `secret_key` | 云厂商 SecretKey，可为空，加密存储 |
| `region` | 云厂商地域，可为空 |
| `default_headers` | 默认请求头，JSON 格式 |
| `timeout_ms` | 请求超时时间 |
| `retry_count` | 失败重试次数 |
| `status` | 启用、停用、异常 |
| `priority` | 多厂商路由优先级 |

### 5.6 文本接口适配原则

- OpenAI 兼容请求优先透传，避免不必要的字段修改。
- Anthropic 请求需要保留 `messages`、`system`、`max_tokens`、`temperature`、`tools` 等字段。
- 上游错误不直接暴露内部密钥、网关地址和系统堆栈。
- 每个厂商适配器必须输出统一的内部用量对象：

```json
{
  "provider_id": "provider_openai",
  "model": "gpt-4.1",
  "input_tokens": 1000,
  "output_tokens": 500,
  "cache_read_tokens": 0,
  "cache_write_tokens": 0,
  "request_count": 1,
  "latency_ms": 1200,
  "status": "success"
}
```

## 6. 模型和价格配置

### 6.1 模型配置

每个模型必须绑定厂商和格式。

| 字段 | 说明 |
| --- | --- |
| `id` | 模型 ID |
| `provider_id` | 所属厂商 |
| `model_name` | 上游真实模型名称 |
| `display_name` | 页面展示名称 |
| `format` | OpenAI 或 Anthropic |
| `modality` | 模态类型，例如文本、视觉、ASR、TTS、文生图、文生视频 |
| `context_window` | 上下文长度 |
| `max_output_tokens` | 最大输出 Token |
| `max_audio_seconds` | 最大音频时长，ASR/TTS 可用 |
| `max_image_count` | 最大图片数量，视觉理解或文生图可用 |
| `max_video_seconds` | 最大视频时长，文生视频可用 |
| `supports_stream` | 是否支持流式输出 |
| `supports_tools` | 是否支持工具调用 |
| `supports_async` | 是否异步任务 |
| `status` | 启用或停用 |

### 6.2 价格配置

价格配置用于查看模型使用成本，不是系统运行的必填项。对内使用或不需要核算成本时，可以不配置价格；系统仍然正常记录调用次数、输入 Token、输出 Token 和明细。

价格应独立于模型表，方便按时间生效和历史成本核算。

| 字段 | 说明 |
| --- | --- |
| `model_id` | 模型 ID |
| `currency` | 币种，例如 CNY、USD，可为空 |
| `input_price_per_1m` | 每 100 万输入 Token 单价，可为空 |
| `output_price_per_1m` | 每 100 万输出 Token 单价，可为空 |
| `cache_read_price_per_1m` | 每 100 万缓存读取 Token 单价，可为空 |
| `cache_write_price_per_1m` | 每 100 万缓存写入 Token 单价，可为空 |
| `request_price` | 单次请求固定价格，可为空 |
| `audio_price_per_minute` | 每分钟音频单价，ASR/TTS 可为空 |
| `tts_price_per_1k_chars` | 每 1000 字符 TTS 单价，可为空 |
| `image_price_per_unit` | 每张图片单价，可为空 |
| `video_price_per_second` | 每秒视频单价，可为空 |
| `effective_from` | 生效时间 |
| `effective_to` | 失效时间，可为空 |

配置价格后，成本计算公式：

```text
cost =
  input_tokens / 1_000_000 * input_price_per_1m
  + output_tokens / 1_000_000 * output_price_per_1m
  + cache_read_tokens / 1_000_000 * cache_read_price_per_1m
  + cache_write_tokens / 1_000_000 * cache_write_price_per_1m
  + audio_seconds / 60 * audio_price_per_minute
  + tts_chars / 1_000 * tts_price_per_1k_chars
  + image_count * image_price_per_unit
  + video_seconds * video_price_per_second
  + request_count * request_price
```

未配置价格时，调用明细中的 `cost` 和 `currency` 为空，大屏和报表隐藏或置灰成本相关指标。价格调整后，历史调用明细不重新计算。配置了价格的调用明细需要保存当时命中的价格快照。

## 7. API Key 管理

### 7.1 Key 生成规则

- Key 使用高强度随机数生成，不使用用户 ID、时间戳等可预测信息。
- 明文 Key 只在创建时展示一次。
- 数据库存储 Key 哈希值，不存储明文。
- Key 前缀用于识别来源，例如 `tkh_`。
- 支持 Key 备注、过期时间、启用停用、最后使用时间。
- Key 不允许出现在普通日志、错误日志、前端埋点和导出文件中。
- 展示 Key 时只能展示前缀和后 4 位，例如 `tkh_****abcd`。
- 支持为 Key 配置允许访问的 IP 或 CIDR 网段，例如 `192.168.1.10`、`10.0.0.0/8`。

### 7.2 Key 绑定关系

每个 Key 必须绑定：

- 所属用户
- 所属部门
- 可访问模型列表
- 限额策略
- IP 白名单或允许网段
- 创建人
- 状态

### 7.3 限额维度

支持按 Key、用户、部门三个层级配置限额。

| 限额类型 | 说明 |
| --- | --- |
| 调用次数限额 | 每分钟、每天、每月最大请求次数 |
| 输入 Token 限额 | 每天、每月最大输入 Token |
| 输出 Token 限额 | 每天、每月最大输出 Token |
| 总 Token 限额 | 输入加输出总量限制 |
| 音频时长限额 | 每天、每月最大 ASR/TTS 音频秒数 |
| TTS 字符限额 | 每天、每月最大合成字符数 |
| 图片数量限额 | 每天、每月最大生成或处理图片数量 |
| 视频时长限额 | 每天、每月最大生成视频秒数 |
| 成本限额 | 每天、每月最大成本，仅在模型配置价格后生效 |
| 并发限额 | 同一 Key 最大并发请求数 |

校验顺序：

1. Key 是否存在、启用、未过期。
2. 用户是否启用。
3. 部门是否启用。
4. 模型是否在 Key 授权范围内。
5. 当前调用次数是否超过限额。
6. 当前 Token 是否超过限额；如果启用了价格，再校验成本是否超过限额。
7. 并发请求数是否超过限额。

## 8. 用量统计和明细

### 8.1 调用明细

每一次请求都要记录明细，失败请求也要记录。

| 字段 | 说明 |
| --- | --- |
| `request_id` | 请求 ID |
| `api_key_id` | Key ID |
| `user_id` | 用户 ID |
| `department_id` | 部门 ID |
| `provider_id` | 厂商 ID |
| `model_id` | 模型 ID |
| `format` | OpenAI 或 Anthropic |
| `modality` | 文本、视觉、ASR、TTS、文生图、文生视频 |
| `endpoint` | 请求接口 |
| `input_tokens` | 输入 Token |
| `output_tokens` | 输出 Token |
| `total_tokens` | 总 Token |
| `cache_read_tokens` | 缓存读取 Token |
| `cache_write_tokens` | 缓存写入 Token |
| `audio_seconds` | 音频输入或输出秒数 |
| `tts_chars` | TTS 输入字符数 |
| `image_count` | 图片输入或输出数量 |
| `video_seconds` | 视频输入或输出秒数 |
| `asset_count` | 生成或上传资产数量 |
| `cost` | 本次成本，未配置价格时为空 |
| `currency` | 币种，未配置价格时为空 |
| `status` | 成功、失败、超时、限额拒绝 |
| `http_status` | HTTP 状态码 |
| `error_code` | 错误码 |
| `error_message` | 错误摘要 |
| `latency_ms` | 请求耗时 |
| `created_at` | 创建时间 |
| `content_stored` | 是否保存了请求和生成内容 |
| `content_retention_days` | 本次内容留存天数 |
| `content_expires_at` | 内容自动清理时间 |
| `task_id` | 异步多模态任务 ID，可为空 |

### 8.2 聚合统计

为了支撑大屏和列表查询，应建立聚合表或物化视图。

建议聚合维度：

- 分钟维度：用于实时大屏。
- 小时维度：用于当天趋势。
- 日维度：用于报表和排行。
- 月维度：用于报表、成本查看和限额。

建议统计指标：

- 请求次数
- 成功次数
- 失败次数
- 限额拒绝次数
- 输入 Token
- 输出 Token
- 总 Token
- 总成本，未配置价格时为空或不展示
- 平均延迟
- P95 延迟

### 8.3 明细查询

明细列表支持筛选：

- 时间范围
- 用户
- 部门
- API Key
- 厂商
- 模型
- 状态
- 错误码
- 最小或最大成本，仅在启用价格时可筛选
- 最小或最大 Token
- 音频时长
- 图片数量
- 视频时长
- 多模态任务状态

明细数据量会持续增长，应支持分页、索引和冷热数据归档。

### 8.4 多模态任务和资产

ASR、TTS、文生图、文生视频可能是同步接口，也可能是异步任务。系统应使用统一任务模型管理提交、执行、查询、完成、失败和取消。

任务字段：

| 字段 | 说明 |
| --- | --- |
| `task_id` | 内部任务 ID |
| `provider_task_id` | 上游厂商任务 ID |
| `usage_record_id` | 关联调用明细 ID |
| `modality` | ASR、TTS、文生图、文生视频等 |
| `provider_id` | 厂商 ID |
| `model_id` | 模型 ID |
| `status` | 排队中、处理中、成功、失败、已取消、已过期 |
| `progress` | 任务进度，可为空 |
| `submit_payload` | 提交参数摘要，敏感内容脱敏 |
| `result_assets` | 结果资产 ID 列表 |
| `error_code` | 错误码 |
| `error_message` | 错误摘要 |
| `created_at` | 创建时间 |
| `finished_at` | 完成时间 |

资产字段：

| 字段 | 说明 |
| --- | --- |
| `asset_id` | 资产 ID |
| `task_id` | 任务 ID |
| `usage_record_id` | 调用明细 ID |
| `asset_type` | 音频、图片、视频、字幕、文本 |
| `storage_path` | 对象存储路径或内部文件路径 |
| `mime_type` | 文件 MIME 类型 |
| `file_size` | 文件大小 |
| `duration_seconds` | 音频或视频时长 |
| `width` | 图片或视频宽度 |
| `height` | 图片或视频高度 |
| `expires_at` | 资产到期清理时间 |

资产访问规则：

- 资产不能直接暴露真实存储路径。
- 下载或预览必须通过鉴权接口生成短期访问链接。
- 资产留存天数应受内容留存策略控制。
- 到期后清理资产文件，保留结构化明细。
- 图片、音频、视频导出必须写入审计日志。

### 8.5 生成内容留存

系统支持按策略保存每个用户的请求内容和模型生成内容，便于管理员查看问题、审计模型使用情况，以及普通用户回看自己的生成记录。内容留存是可配置能力，可以关闭。

建议保存内容：

| 字段 | 说明 |
| --- | --- |
| `usage_record_id` | 关联调用明细 ID |
| `user_id` | 用户 ID |
| `department_id` | 部门 ID |
| `api_key_id` | Key ID |
| `model_id` | 模型 ID |
| `request_messages` | 用户输入消息，可加密存储 |
| `response_content` | 模型生成内容，可加密存储 |
| `tool_calls` | 工具调用内容，可为空 |
| `attachments_meta` | 附件元信息，不建议直接存大文件 |
| `content_hash` | 内容哈希，用于去重或审计 |
| `storage_status` | 已保存、已脱敏、已清理、保存失败 |
| `retention_days` | 实际留存天数 |
| `expires_at` | 到期清理时间 |

留存策略：

- 系统级默认留存天数，例如 7 天、30 天、90 天或永久。
- 部门级可覆盖系统默认值。
- API Key 级可覆盖部门默认值。
- 用户级可配置是否允许保存自己的生成内容。
- 留存天数为 `0` 表示不保存请求内容和生成内容，只保存调用明细、Token 和状态。
- 到期后由定时任务清理内容正文，调用明细和统计数据继续保留。
- 清理后保留 `usage_record_id`、用户、部门、模型、Token、状态、成本等结构化统计字段。
- 内容正文必须加密存储，解密只发生在已授权的查看接口中。
- 内容保存失败不能影响模型调用返回，但必须记录内部异常和审计线索。
- 默认不建议开启永久留存；如开启永久留存，必须由超级管理员确认。

查看权限：

- 超级管理员可查看全局内容明细，但所有查看行为必须写入审计日志。
- 管理员只能查看授权部门及子部门的内容明细。
- 普通用户只能查看自己生成的内容。
- 涉及敏感数据的字段应支持脱敏展示。
- 导出生成内容需要单独权限，不应默认开放。
- 导出文件应设置有效期，到期自动删除。

### 8.6 会话存档和 CSV 导出

会话存档用于按用户、部门、API Key、模型和时间范围导出一次或多次模型调用的上下文记录。它不是普通日志，而是基于 `usage_records`、`usage_contents`、`multimodal_tasks` 和 `media_assets` 生成的业务归档。

会话边界：

- 如果请求中有 `conversation_id` 或 `session_id`，按该字段归档。
- 如果没有会话 ID，可按用户、API Key、模型和时间窗口聚合。
- 每条调用明细都应保留 `request_id`，用于还原单次调用。
- 多模态任务和媒体资产只导出元信息和短期下载链接，不直接嵌入大文件。

导出权限：

- 普通用户只能导出自己的会话存档。
- 管理员只能导出授权部门及子部门用户的会话存档。
- 超级管理员可导出全局会话存档。
- 导出原文需要单独权限；默认导出脱敏内容或摘要。
- 所有会话导出必须写入审计日志。

CSV 建议字段：

```text
session_id,request_id,created_at,user_id,department_id,api_key_id,
provider_id,model_id,modality,status,input_tokens,output_tokens,total_tokens,
audio_seconds,tts_chars,image_count,video_seconds,cost,currency,
prompt_summary,response_summary,prompt_content,response_content,
content_redacted,asset_ids,asset_download_urls,error_code,error_message
```

导出策略：

- 支持 CSV 格式。
- 大范围导出必须异步生成任务。
- 导出文件必须加密存储。
- 下载链接短期有效。
- 到期后自动删除导出文件。
- 若内容已按留存策略清理，只导出结构化明细，不导出已清理正文。
- 若选择导出原文，必须二次确认并记录审计。

当前实现使用 `system_settings.setting_key = content_retention.policy` 保存内容留存策略。策略 JSON 字段为 `mode`、`default_days`、`max_days`、`raw_allowed_roles`、`departments`、`users`、`api_keys`。策略优先级固定为 API Key > 用户 > 最近部门/祖先部门 > 系统默认，实际保留天数必须被 `max_days` 截断。

内容查看必须通过专用归档接口完成，不能直接依赖通用 CRUD 暴露原文。普通用户只能查看本人内容，管理员只能查看授权部门及子部门内容，超级管理员可查看全局内容但仍写审计。原文查看和原文导出必须满足 `raw_allowed_roles` 并写独立审计事件。

## 9. 用户和部门管理

部门管理需要支持多级部门树，不限制固定层数，以适配不同公司的组织架构。常见结构可以是“公司 / 事业部 / 中心 / 部门 / 小组”，也可以是“公司 / 项目组 / 成员组”。系统不应把部门层级写死在代码里。

### 9.1 用户字段

| 字段 | 说明 |
| --- | --- |
| `id` | 用户 ID |
| `username` | 登录账号 |
| `display_name` | 用户姓名 |
| `email` | 邮箱 |
| `phone` | 手机号 |
| `role` | 超级管理员、管理员、普通用户 |
| `department_id` | 所属部门 |
| `status` | 启用、禁用 |
| `last_login_at` | 最后登录时间 |

### 9.2 部门字段

| 字段 | 说明 |
| --- | --- |
| `id` | 部门 ID |
| `parent_id` | 上级部门 |
| `name` | 部门名称 |
| `path` | 部门路径，便于查询子部门 |
| `level` | 部门层级，从根部门开始递增 |
| `sort_order` | 同级部门排序 |
| `full_name` | 部门完整名称，例如 `总部 / 技术中心 / AI 平台组` |
| `manager_user_id` | 部门负责人 |
| `status` | 启用、停用 |

### 9.3 多级部门规则

- 部门层级不写死，默认建议限制最大层级，例如 10 级，避免误操作形成过深结构。
- 根部门可以代表公司、租户或组织主体。
- 每个部门只能有一个直接上级部门。
- 部门可新增、编辑、停用、排序、移动到其他上级部门。
- 移动部门时，需要同步更新该部门及全部子部门的 `path`、`level` 和 `full_name`。
- 不允许把部门移动到自己的子部门下面，避免形成循环。
- 管理员的数据权限默认包含其负责部门和全部子部门。
- 用户只能归属一个主部门；如需兼任多个部门，可扩展用户部门关联表。
- 部门停用后，该部门及子部门下用户和 Key 默认不可继续调用模型。

### 9.4 管理能力

- 超级管理员可创建、编辑、禁用所有用户和部门。
- 管理员可管理授权部门下的普通用户。
- 普通用户不可创建其他用户。
- 用户禁用后，对应 Key 应立即不可用。
- 部门停用后，该部门下用户和 Key 不可继续调用模型。

## 10. 智慧大屏

### 10.1 核心指标

智慧大屏展示平台运行、用量、可选成本、安全、性能和多模态调用情况。大屏既要支持全局概览，也要支持按部门、员工、模型、厂商、API Key、模态类型下钻分析。

| 指标 | 说明 |
| --- | --- |
| 今日调用次数 | 当天所有请求次数 |
| 今日 Token 总量 | 输入和输出 Token 总和 |
| 今日文本调用次数 | 文本生成、对话、Embedding 调用次数 |
| 今日图片调用次数 | 视觉理解、文生图、图生图、图片编辑调用次数 |
| 今日语音调用次数 | ASR、TTS 调用次数 |
| 今日视频调用次数 | 文生视频、图生视频、视频编辑调用次数 |
| 今日成本 | 按价格配置计算的当天成本，未配置价格时不展示 |
| 成功率 | 成功请求数 / 总请求数 |
| 平均延迟 | 成功请求平均耗时 |
| P95 延迟 | 反映高延迟请求情况 |
| 活跃用户数 | 当天有调用的用户数 |
| 活跃部门数 | 当天有调用的部门数 |
| 活跃 Key 数 | 当天有调用的 Key 数 |
| 异常请求数 | 失败、超时、限额拒绝请求数 |
| 安全拦截次数 | Caddy WAF、IP 白名单、脱敏阻断等拦截次数 |
| 队列堆积量 | 明细、日志、聚合、导出任务队列堆积 |

### 10.2 分析视图

建议将大屏拆成多个分析视图，避免所有指标堆在同一屏。

| 视图 | 内容 |
| --- | --- |
| 全局概览 | 今日调用、Token、成功率、成本、活跃用户、异常请求、系统健康 |
| 趋势分析 | 分钟、小时、日维度调用趋势、Token 趋势、成本趋势、错误趋势 |
| 部门分析 | 部门调用量、Token、成本、成功率、异常数、同比环比 |
| 员工分析 | 员工调用量、Token、模型使用、成本、异常调用、活跃时间段 |
| 模态分析 | 文本、图片、语音、视频等不同能力的调用次数和用量 |
| 模型分析 | 模型调用量、Token、延迟、错误率、成本占比 |
| 厂商分析 | 厂商调用量、成功率、延迟、错误率、成本、熔断情况 |
| API Key 分析 | Key 调用量、限额使用率、最后调用时间、异常来源 IP |
| 安全分析 | WAF 命中、IP 拦截、脱敏命中、越权访问、导出行为 |
| 性能分析 | 网关延迟、首包延迟、SSE 连接数、队列堆积、Redis 耗时 |

### 10.3 排行榜

排行榜用于快速定位高频使用者、高成本部门、高异常来源和热门模型。

| 排行榜 | 排序指标 |
| --- | --- |
| 部门使用排行榜 | 调用次数、Token、文本次数、图片次数、语音次数、视频次数、成本 |
| 员工使用排行榜 | 调用次数、Token、模型数、活跃天数、成本 |
| API Key 使用排行榜 | 调用次数、Token、失败次数、限额使用率、来源 IP 数 |
| 模型热度排行榜 | 调用次数、Token、成功率、平均延迟、成本 |
| 厂商稳定性排行榜 | 成功率、错误率、P95 延迟、超时次数 |
| 异常调用排行榜 | 失败次数、限额拒绝次数、WAF 命中次数、脱敏阻断次数 |
| 成本排行榜 | 部门成本、员工成本、模型成本、厂商成本 |

排行榜规则：

- 支持今天、昨天、最近 7 天、最近 30 天、自定义时间范围。
- 未配置价格时隐藏成本排行榜。
- 管理员只能看到授权部门及子部门排行。
- 普通用户只能看到自己的排行和个人趋势。
- 排行榜可点击下钻到明细列表。

### 10.4 多模态调用分析

多模态分析用于区分不同推理能力的使用情况。

| 模态 | 分析指标 |
| --- | --- |
| 文本 | 调用次数、输入 Token、输出 Token、平均输出长度、失败率 |
| 视觉理解 | 调用次数、图片数量、输入 Token、输出 Token、失败率 |
| ASR | 调用次数、音频秒数、平均音频时长、识别失败率 |
| TTS | 调用次数、TTS 字符数、输出音频秒数、失败率 |
| 文生图 | 调用次数、生成图片数量、分辨率分布、失败率 |
| 文生视频 | 调用次数、视频秒数、任务成功率、平均生成耗时 |
| Embedding | 调用次数、输入 Token、向量条数、失败率 |

建议图表：

- 模态调用次数堆叠趋势图。
- 文本 Token 输入输出趋势图。
- 图片调用次数和生成数量趋势图。
- 语音秒数和 TTS 字符数趋势图。
- 视频任务成功率和平均生成耗时趋势图。
- 各模态错误率对比图。

### 10.5 异常和告警看板

异常看板用于运营和安全值守。

| 异常类型 | 说明 |
| --- | --- |
| 上游厂商异常 | 超时、5xx、限流、鉴权失败 |
| 模型异常 | 单模型错误率升高、延迟升高 |
| 用户异常 | 单用户调用突增、失败突增、来源 IP 异常 |
| 部门异常 | 部门用量突增、成本突增、限额接近耗尽 |
| Key 异常 | Key 失败次数过高、来源 IP 过多、疑似泄漏 |
| 安全异常 | WAF 命中、越权访问、脱敏阻断、导出异常 |
| 系统异常 | 队列堆积、日志写入失败、明细写入失败、证书即将过期 |

### 10.6 大屏钻取

大屏指标必须支持从总览钻取到明细：

- 点击部门排行，进入该部门用量明细。
- 点击员工排行，进入该员工调用明细和会话存档。
- 点击模型排行，进入模型维度的厂商、错误、延迟分析。
- 点击厂商异常，进入上游错误日志和调用明细。
- 点击安全拦截，进入安全日志和脱敏事件。
- 点击导出异常，进入导出任务和审计日志。

### 10.7 数据刷新

- 实时指标：5 秒到 30 秒刷新一次。
- 趋势图：1 分钟刷新一次。
- 月度统计：按需刷新或定时任务刷新。
- 大屏查询只访问聚合表，不直接扫描调用明细表。
- 排行榜读取日聚合或小时聚合数据。
- 异常告警可以使用实时事件流或短周期轮询。
- 大屏展示数据必须受权限范围过滤。

### 10.8 独立大屏链接和访问账号

智慧大屏需要支持生成独立访问链接，用于会议室电视、运营看板、客户现场或领导查看。独立链接不能等同于管理后台登录，也不能直接暴露完整后台权限。

推荐访问方式：

```text
管理员后台
  -> 创建大屏链接
  -> 设置独立用户名、密码、数据范围、链接登录有效期
  -> 生成 /screen/{share_code}

访问者
  -> 打开 /screen/{share_code}
  -> 输入大屏用户名和密码
  -> 只读查看授权范围内的大屏指标
  -> 未主动退出时保持登录状态
```

大屏链接配置项：

| 配置项 | 说明 |
| --- | --- |
| `share_code` | 随机生成的不可猜测链接码，不使用自增 ID |
| `name` | 链接名称，例如会议室大屏、运营中心大屏 |
| `username` | 独立大屏登录用户名，不复用管理员账号 |
| `password_hash` | 密码哈希存储，明文只在创建或重置时出现 |
| `scope_type` | 数据范围：全局、指定部门、指定用户、指定模型、指定厂商 |
| `scope_ids` | 允许查看的数据对象 ID 列表 |
| `allowed_metrics` | 允许展示的指标，例如调用、Token、成本、安全、性能、多模态 |
| `allow_drilldown` | 是否允许从大屏下钻，默认关闭 |
| `allow_sensitive_cost` | 是否展示成本，默认按价格配置和权限决定 |
| `allowed_ip_cidrs` | 可选 IP/CIDR 白名单，例如只允许内网或会议室出口 IP |
| `expires_at` | 链接登录有效期，控制是否允许新登录；已登录会话不因该时间自动失效 |
| `refresh_interval` | 页面刷新间隔，避免过高频率打爆接口 |
| `status` | 启用、停用、过期 |

安全规则：

- 独立大屏账号只能访问 `/screen/*` 和 `/screen-api/*`，不能访问 `/admin/*`、`/admin-api/*`。
- 密码必须哈希存储，建议支持密码复杂度、过期提醒和重置。
- 大屏登录成功后使用单独会话 Cookie，例如 `screen_session`，与管理后台 Cookie 隔离。
- 大屏 Cookie 使用 `HttpOnly`、`Secure`、`SameSite=Strict`。
- 大屏会话不设置固定登录过期时间；输入密码登录后保持有效，正常情况下只有访问者主动退出后才需要重新登录。
- 大屏会话建议使用持久 Cookie；服务端会话记录不因空闲时间、浏览器关闭或页面刷新自动失效。
- 每次请求仍必须重新校验链接状态、IP 白名单和权限范围；如果链接停用、管理员强制撤销会话、密码重置、来源 IP 不再允许或安全策略触发锁定，应直接拒绝访问或强制下线。这属于安全处置，不属于会话自动过期。
- `expires_at` 只控制未登录访问者是否还能登录，不让已经输入密码并登录成功的大屏会话自动失效。
- 链接码泄漏后仍需要用户名和密码才能查看。
- 连续登录失败需要限速，超过阈值可临时锁定链接或来源 IP。
- 默认禁止查看用户生成内容原文、API Key 明文、厂商密钥、导出下载链接和审计详情。
- 如开启下钻，只允许进入聚合数据或脱敏后的明细摘要，不允许进入完整会话原文。
- 所有登录、失败、退出、刷新、IP 拒绝、新登录因链接到期被拒绝、会话撤销都写入独立访问日志。
- 超级管理员可以停用链接、重置密码、强制退出所有大屏会话。

独立大屏页面建议：

- `/screen/{share_code}`：大屏访问页。
- `/screen-login/{share_code}`：大屏登录页，也可以由同一页面弹出登录态。
- `/screen-api/auth/login`：大屏账号登录。
- `/screen-api/dashboard/realtime`：只读实时指标。
- `/screen-api/dashboard/trends`：只读趋势数据。
- `/screen-api/auth/logout`：退出大屏会话。

## 11. 数据库表设计

### 11.1 核心表

```text
users
departments
providers
models
model_prices
api_keys
api_key_model_permissions
quota_policies
quota_bindings
usage_records
usage_contents
multimodal_tasks
media_assets
usage_aggregates_minute
usage_aggregates_hour
usage_aggregates_day
audit_logs
system_settings
log_retention_policies
export_tasks
desensitization_policies
desensitization_events
waf_rules
waf_rule_versions
waf_events
dashboard_share_links
dashboard_share_access_logs
```

### 11.2 表关系

- 一个部门包含多个用户。
- 一个用户可以拥有多个 API Key。
- 一个 API Key 可以授权多个模型。
- 一个模型属于一个厂商。
- 一个模型可以有多条可选价格配置。
- 一条调用明细绑定一个用户、部门、Key、模型和厂商。
- 一条调用明细可以关联一条内容留存记录。
- 一条调用明细可以关联一个多模态任务和多个媒体资产。
- 限额策略可以绑定到 Key、用户或部门。
- 脱敏策略可以绑定到系统、部门、API Key、模型或厂商。
- 日志留存策略可以按日志类型配置。
- 日志导出和会话存档导出通过 `export_tasks` 异步任务管理。
- Caddy WAF 结构化规则保存到 `waf_rules`，每次发布生成 `waf_rule_versions`，命中事件写入 `waf_events`。
- 独立大屏链接保存到 `dashboard_share_links`，访问、登录、失败和 IP 拒绝记录到 `dashboard_share_access_logs`。

### 11.3 索引建议

```text
usage_records(created_at)
usage_records(user_id, created_at)
usage_records(department_id, created_at)
usage_records(api_key_id, created_at)
usage_records(model_id, created_at)
usage_records(provider_id, created_at)
usage_records(status, created_at)
usage_contents(usage_record_id)
usage_contents(user_id, created_at)
usage_contents(department_id, created_at)
usage_contents(expires_at)
multimodal_tasks(user_id, created_at)
multimodal_tasks(provider_task_id)
multimodal_tasks(status, created_at)
media_assets(usage_record_id)
media_assets(task_id)
media_assets(expires_at)
log_retention_policies(log_type)
export_tasks(created_by, created_at)
export_tasks(export_type, status)
export_tasks(expires_at)
desensitization_policies(scope_type, scope_id)
desensitization_events(request_id, created_at)
desensitization_events(policy_id, created_at)
waf_rules(scope, enabled, priority)
waf_rule_versions(status, created_at)
waf_events(rule_id, created_at)
waf_events(source_ip, created_at)
dashboard_share_links(share_code)
dashboard_share_links(status, expires_at)
dashboard_share_access_logs(share_link_id, created_at)
dashboard_share_access_logs(source_ip, created_at)
usage_aggregates_day(day, department_id)
usage_aggregates_day(day, user_id)
api_keys(key_hash)
users(department_id)
departments(parent_id)
departments(path)
departments(level)
departments(parent_id, sort_order)
```

## 12. API 设计

### 12.1 管理端接口

```text
POST   /admin/login
GET    /admin/me

GET    /admin/users
POST   /admin/users
PUT    /admin/users/{id}
PATCH  /admin/users/{id}/status

GET    /admin/departments
GET    /admin/departments/tree
POST   /admin/departments
PUT    /admin/departments/{id}
PATCH  /admin/departments/{id}/status
POST   /admin/departments/{id}/move
POST   /admin/departments/sort

GET    /admin/providers
POST   /admin/providers
PUT    /admin/providers/{id}
PATCH  /admin/providers/{id}/status

GET    /admin/models
POST   /admin/models
PUT    /admin/models/{id}
PATCH  /admin/models/{id}/status

GET    /admin/model-prices
POST   /admin/model-prices
PUT    /admin/model-prices/{id}

GET    /admin/api-keys
POST   /admin/api-keys
PATCH  /admin/api-keys/{id}/status
DELETE /admin/api-keys/{id}

GET    /admin/usage/records
GET    /admin/usage/records/{id}/content
DELETE /admin/usage/records/{id}/content
POST   /admin/usage/sessions/export
GET    /admin/usage/summary
GET    /admin/multimodal/tasks
GET    /admin/multimodal/tasks/{id}
GET    /admin/media-assets/{id}/download-url
GET    /admin/dashboard/realtime
GET    /admin/dashboard/trends
GET    /admin/dashboard/share-links
POST   /admin/dashboard/share-links
PUT    /admin/dashboard/share-links/{id}
PATCH  /admin/dashboard/share-links/{id}/status
POST   /admin/dashboard/share-links/{id}/reset-password
POST   /admin/dashboard/share-links/{id}/revoke-sessions
GET    /admin/dashboard/share-links/{id}/access-logs
GET    /admin/system/content-retention
PUT    /admin/system/content-retention
GET    /admin/system/security
PUT    /admin/system/security
GET    /admin/system/log-retention
PUT    /admin/system/log-retention
GET    /admin/system/desensitization
PUT    /admin/system/desensitization
GET    /admin/system/waf
PUT    /admin/system/waf
GET    /admin/desensitization/policies
POST   /admin/desensitization/policies
PUT    /admin/desensitization/policies/{id}
PATCH  /admin/desensitization/policies/{id}/status
GET    /admin/desensitization/events
GET    /admin/waf/rules
POST   /admin/waf/rules
PUT    /admin/waf/rules/{id}
PATCH  /admin/waf/rules/{id}/status
POST   /admin/waf/publish
GET    /admin/waf/versions
POST   /admin/waf/versions/{id}/rollback
GET    /admin/waf/events
GET    /admin/audit-logs
POST   /admin/logs/export
GET    /admin/export-tasks
GET    /admin/export-tasks/{id}
GET    /admin/export-tasks/{id}/download-url
GET    /admin-api/system/content-retention
PUT    /admin-api/system/content-retention
GET    /admin-api/content-archive
GET    /admin-api/content-archive/{id}
POST   /admin-api/exports/{type}
GET    /admin-api/exports/{export_id}/download-url
```

### 12.2 独立大屏访问接口

独立大屏接口只服务 `/screen/{share_code}` 页面，不使用管理端会话，不允许调用管理端接口。

```text
GET    /screen/{share_code}
POST   /screen-api/auth/login
POST   /screen-api/auth/logout
GET    /screen-api/auth/me
GET    /screen-api/dashboard/realtime
GET    /screen-api/dashboard/trends
GET    /screen-api/dashboard/rankings
GET    /screen-api/dashboard/security
GET    /screen-api/dashboard/performance
```

接口要求：

- 所有 `/screen-api/*` 接口必须校验大屏会话、链接状态、IP/CIDR 白名单和数据范围；大屏会话本身不因时间、空闲、刷新、关闭浏览器或链接登录有效期到达而自动过期。
- `expires_at` 只在未登录访问者登录时校验；链接到期后不允许新登录，但不踢出已经登录的大屏会话。
- 返回数据必须按 `dashboard_share_links.scope_type`、`scope_ids` 和 `allowed_metrics` 过滤。
- 成本、员工排行、API Key 排行、安全详情等敏感指标需要单独开关控制。
- 如果未开启 `allow_drilldown`，接口只返回聚合指标，不返回可跳转的明细 ID。
- 大屏接口只能读聚合表，不直接扫描调用明细表，不返回用户生成内容原文。

### 12.3 对外兼容接口

对外调用方只需要理解两种协议：OpenAI 兼容格式和 Anthropic 兼容格式。TokenHub 内部可以路由到 OpenAI、Anthropic、腾讯云、阿里云、火山、百度、讯飞等厂商。

OpenAI 兼容格式：

```text
POST /v1/chat/completions
POST /v1/responses
POST /v1/embeddings
POST /v1/audio/transcriptions
POST /v1/audio/speech
POST /v1/images/generations
POST /v1/videos/generations
GET  /v1/tasks/{id}
GET  /v1/assets/{id}/download-url
```

Anthropic 兼容格式：

```text
POST /v1/messages
GET  /v1/messages/{task_id}
GET  /v1/tasks/{id}
GET  /v1/assets/{id}/download-url
```

Anthropic 兼容格式说明：

- 文本和视觉理解使用 `/v1/messages`。
- ASR、TTS、文生图、文生视频使用 Anthropic 风格 content block 或任务消息封装。
- 异步能力返回 `task_id`，再通过任务查询接口获取状态和结果资产。
- 响应保持 Anthropic 风格的 `content` 结构，并在需要时返回媒体资产 ID。

OpenAI 兼容多模态能力：

```text
POST /v1/asr/transcriptions
POST /v1/tts/speech
POST /v1/images/generations
POST /v1/videos/generations
GET  /v1/tasks/{id}
GET  /v1/assets/{id}/download-url
```

其中 `/v1/audio/transcriptions` 和 `/v1/audio/speech` 是推荐的 OpenAI 兼容音频端点；`/v1/asr/transcriptions` 和 `/v1/tts/speech` 可作为语义化别名。腾讯云等厂商原生接口不直接暴露给外部调用方，外部始终使用 OpenAI 兼容或 Anthropic 兼容 API。TokenHub 在内部完成签名、参数转换、任务查询和结果资产管理。

对外接口使用 TokenHub Key：

```http
Authorization: Bearer tkh_xxxxxxxxxxxxxxxxx
```

## 13. 限额和计量实现

### 13.1 请求前校验

请求进入代理层后，先执行轻量校验：

- Key 哈希查询。
- 用户和部门状态。
- 模型权限。
- 当前周期内调用次数。
- 当前周期内已用 Token；启用价格后再读取当前周期内已用成本。
- 当前周期内已用音频秒数、TTS 字符数、图片数量、视频秒数。
- 并发锁或计数器。

对于流式响应，请求前无法知道最终输出 Token，需要先校验请求次数和预估 Token，响应结束后再回写实际用量。

### 13.2 请求后计量

请求完成后，统一写入：

- 调用明细。
- 用户输入和模型生成内容，只有在内容留存策略启用时保存。
- 多模态任务状态和媒体资产元信息。
- 限额已用量。
- 聚合统计。
- 审计或异常日志。

如果上游没有返回 Token usage，可使用 tokenizer 进行估算，并在明细中标记 `usage_source = estimated`。

### 13.3 并发和一致性

- 次数限额和并发限额建议使用 Redis 原子计数。
- 月度 Token 限额可使用聚合统计加缓存；成本限额仅在配置价格后启用。
- 调用明细写库失败时，应写入备用队列或本地降级日志，避免数据完全丢失。
- 对于已通过限额校验但最终超额的流式请求，允许本次完成，后续请求拦截。

## 14. 性能和高速转发设计

TokenHub 的高性能目标是让平台转发开销尽量小，避免因为鉴权、统计、日志、内容留存拖慢模型响应。推荐采用 Caddy 作为入口代理，TokenHub 应用网关负责业务转发。

### 14.1 推荐部署架构

```text
Client
  -> Caddy
  -> TokenHub Gateway
  -> Provider Adapter
  -> OpenAI / Anthropic / 腾讯云 / 其他厂商
```

Caddy 负责：

- TLS 证书和 HTTPS 入口。
- HTTP/2、HTTP/3 和连接复用。
- 反向代理和多实例负载均衡。
- 基础 IP 网段限制、IP 限流和访问日志。
- 增强 WAF 规则，例如限制方法、路径、Header、Query、请求体大小、可疑请求特征和管理端访问网段。
- 静态管理端资源托管。
- 健康检查和故障实例摘除。

TokenHub Gateway 负责：

- API Key 鉴权。
- 用户、部门、模型权限校验。
- 限额校验。
- 模型路由。
- OpenAI/Anthropic 协议兼容。
- 厂商适配和签名。
- 流式响应透传。
- 用量解析、明细记录和内容留存。

### 14.2 高速转发原则

- 对 OpenAI SSE、Anthropic stream 等流式响应必须边读边写，禁止等待完整响应后再返回。
- 非必要不复制大请求体和大响应体。
- 请求日志只记录摘要，不记录完整 prompt、完整响应、音视频文件内容。
- 上游 HTTP 客户端必须启用连接池、Keep-Alive、合理超时和并发限制。
- 限额、Key 状态、模型权限等热数据应缓存在 Redis 或本地短缓存中。
- 调用明细、内容留存、聚合统计通过队列异步写入。
- 聚合统计采用批量写入，避免每个请求同步更新多张统计表。
- 多模态大文件上传和下载应走对象存储或受控资产服务，网关只处理授权和短期链接。
- 视频生成、文生图等长任务必须异步化，避免占用同步请求线程。

### 14.3 Caddy 使用建议

Caddy 可以作为生产入口，但不建议把业务逻辑写在 Caddy 层。业务逻辑应留在 TokenHub Gateway，便于权限、审计、统计和多厂商路由统一管理。

建议配置能力：

- 自动 HTTPS。
- HTTP/2 和 HTTP/3。
- `reverse_proxy` 到多个 TokenHub Gateway 实例。
- 合理的读写超时，支持长时间流式响应。
- 禁止代理层缓存模型响应。
- 访问日志脱敏，不记录 Authorization。
- 管理端和 API 可使用不同域名或路径。

### 14.4 Caddy 证书和自动续期

Caddy 可自动启用 HTTPS、申请证书并续期。证书策略需要区分域名、公网 IP、内网 IP 和私有化部署。

推荐策略：

| 场景 | 建议方案 | 说明 |
| --- | --- | --- |
| 公网生产 API | 使用域名，例如 `api.example.com` | Caddy 通过公共 ACME CA 自动签发和续期，推荐方案 |
| 管理端 | 使用独立域名，例如 `admin.example.com` | 配合 IP 白名单或 VPN，只允许办公网段访问 |
| 内网 API | 使用内网域名或内网 IP | 可使用 Caddy 本地 CA 或企业内部 CA，客户端需要信任根证书 |
| 公网 IP 直接访问 | 不推荐作为主方案 | 公共可信 IP 证书并非所有 CA 都支持，优先绑定域名 |
| 私有化部署 | 支持上传企业证书或接入企业 CA | 适合内网、政企、离线环境 |

自动续期要求：

- 使用域名时，DNS A/AAAA 记录必须指向 Caddy 所在入口。
- HTTP-01 / TLS-ALPN-01 方式需要外部可访问 80 / 443 端口。
- 如果 80 / 443 不能开放，可使用 DNS-01 挑战，但需要配置 DNS Provider 插件和凭据。
- Caddy 的数据目录必须持久化，避免重启或容器重建后丢失证书和账户信息。
- 多个 Caddy 实例共享同一组域名时，应使用共享证书存储，避免重复申请和触发 CA 限流。
- 证书续期失败必须告警，至少监控证书剩余有效期、自动续期错误和 Caddy 日志。
- 测试环境应使用 ACME staging，避免触发公共 CA 频率限制。

IP 证书策略：

- 对公网服务，优先使用域名证书，不建议让客户直接使用公网 IP 调用。
- 对内网 IP 或 `localhost`，Caddy 可以使用本地 CA 自动签发证书，但调用方必须安装或信任 Caddy 本地根证书。
- 如果必须使用公网 IP 证书，需要确认所选 CA 支持 IP 地址证书，并在 Caddy 中配置对应 issuer 或手动加载证书。
- IP 白名单和 IP 证书是两件事：IP 白名单控制谁能访问，IP 证书控制 TLS 连接是否可信。

Caddyfile 域名证书示例：

```caddyfile
api.example.com {
  encode zstd gzip
  reverse_proxy tokenhub-gateway-1:8080 tokenhub-gateway-2:8080
}
```

内网 IP 或内网域名示例：

```caddyfile
https://10.10.0.20 {
  tls internal
  reverse_proxy tokenhub-gateway:8080
}

https://tokenhub.internal {
  tls internal
  reverse_proxy tokenhub-gateway:8080
}
```

手动证书示例：

```caddyfile
api.example.com {
  tls /etc/tokenhub/certs/fullchain.pem /etc/tokenhub/certs/privkey.pem
  reverse_proxy tokenhub-gateway:8080
}
```

证书配置不应替代业务鉴权。即使 TLS 证书有效，TokenHub Gateway 仍必须校验 API Key、IP 白名单、用户、部门、模型和限额。

### 14.5 API 访问限制和增强 Caddy WAF

Caddy WAF 是本方案的重要安全边界，但它不能替代 PHP Gateway 的业务鉴权。推荐采用三层防护：Caddy 边缘 WAF 先挡明显异常流量，PHP Gateway 做业务级安全校验，日志和审计系统负责追踪、告警和复盘。

防护分层：

| 层级 | 负责内容 | 说明 |
| --- | --- | --- |
| Caddy 边缘层 | IP/CIDR、TLS、方法、路径、Header、Query、请求体大小、基础频率限制、安全响应头 | 在请求进入 PHP 前先拦截低成本风险 |
| PHP Gateway 层 | API Key、用户、部门、模型权限、限额、AST 脱敏、JSON Body 风险识别、SSE 内容审计 | 理解业务语义，适合做深度校验 |
| 日志审计层 | WAF 命中、规则版本、来源 IP、请求摘要、处置动作、告警 | 所有安全事件可追踪、可导出、可复盘 |

Caddy WAF 必须支持的规则类型：

| 规则类型 | 动作 | 说明 |
| --- | --- | --- |
| IP/CIDR 白名单 | `allow` / `deny` | 管理端默认只允许内网、VPN、办公网段 |
| HTTP 方法限制 | `deny` | 对 API 只允许 `GET`、`POST`、`OPTIONS` |
| 高风险路径拦截 | `deny` / `hide` | 拦截 `.env`、`.git`、备份文件、调试路径、源码路径 |
| Header 规则 | `deny` / `mask` / `drop` | 拦截异常 `User-Agent`，删除内部 Header，脱敏 Authorization/Cookie |
| Query 规则 | `deny` / `mask` | 拦截 `token`、`key`、`secret`、SQL 注入特征、路径穿越特征 |
| 请求体大小 | `deny` | 不同能力配置不同大小，例如文本、ASR 上传、图片任务分开限制 |
| 静态资源安全头 | `set` | CSP、HSTS、X-Frame-Options、Referrer-Policy、Permissions-Policy |
| 频率和并发 | `limit` / `deny` | Caddy 做粗粒度限制，精确 Key 限额由 PHP Gateway 做 |
| 违规字段脱敏 | `mask` | Caddy 日志和简单 Header 可显示 `****` |
| 安全事件记录 | `log` | 写入 `caddy-security.log`，包含规则 ID 和动作 |

边缘层策略：

- 管理端只允许内网、VPN 或指定办公网段访问。
- 对外 API 可按业务需要开放公网，但必须支持 API Key 级 IP 白名单。
- 私有部署场景下，对外 API 也可以只允许内网网段访问，例如 `10.0.0.0/8`、`172.16.0.0/12`、`192.168.0.0/16`。
- 禁止非必要 HTTP 方法，例如只允许 `GET`、`POST`、`OPTIONS`。
- 限制请求体大小，避免大文件或异常请求打爆网关。
- 限制高风险路径，例如隐藏文件、管理后台调试路径、源码路径。
- 对可疑 Header、Query、Path 做基础拦截。
- 对违规 Header、Query 参数、响应 Header、访问日志字段做星号脱敏或删除。
- 对 SQL 注入、XSS、Bot、CC 等复杂防护，可采用 Caddy 插件、前置专业 WAF 或云 WAF；即便启用专业 WAF，PHP Gateway 仍必须保留业务校验。

WAF 规则管理要求：

- 后台提供 WAF 配置页面，但不直接让管理员手写任意 Caddyfile。
- 后台保存结构化规则，例如 `rule_id`、`scope`、`matcher`、`action`、`priority`、`enabled`、`description`。
- 由后端生成 Caddy 配置片段，经过语法校验后才能发布。
- 每次规则发布生成版本号，支持查看差异、回滚、审计。
- 高危规则发布需要超级管理员确认，例如全局阻断、管理端网段变更、请求体大小降低。
- Caddy reload 失败必须保留旧配置，不允许导致入口不可用。
- WAF 命中事件写入独立日志，并在智慧大屏展示命中趋势、来源 IP、规则排行。

Caddyfile 示例：

```caddyfile
(security_headers) {
  header {
    Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
    X-Frame-Options "DENY"
    X-Content-Type-Options "nosniff"
    Referrer-Policy "no-referrer"
    Permissions-Policy "camera=(), microphone=(), geolocation=()"
  }
}

api.example.com {
  encode zstd gzip
  import security_headers

  @not_allowed_ip not remote_ip 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16
  respond @not_allowed_ip 403

  @bad_methods not method GET POST OPTIONS
  respond @bad_methods 405

  @blocked_paths path /.env /.git/* /.svn/* /debug/* /admin/debug/* /backup/* /*.bak /*.sql
  respond @blocked_paths 404

  @bad_query query token=* key=* secret=* password=* passwd=*
  respond @bad_query 400

  @sql_probe query *select* *union* *sleep* *benchmark* *information_schema*
  respond @sql_probe 403

  @path_traversal path *../* */..* *%2e%2e*
  respond @path_traversal 403

  request_body {
    max_size 50MB
  }

  reverse_proxy tokenhub-gateway-1:8080 tokenhub-gateway-2:8080

  log {
    output file /var/log/caddy/tokenhub-access.log
    format json
  }
}
```

管理端建议使用独立域名、静态 JS 托管和办公网段限制：

```caddyfile
admin.example.com {
  root * /var/www/tokenhub/public/admin
  encode zstd gzip
  import security_headers

  @not_admin_net not remote_ip 10.10.0.0/16 192.168.10.0/24
  respond @not_admin_net 403

  @admin_api path /admin-api/*
  reverse_proxy @admin_api tokenhub-admin-api:8080

  header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"

  try_files {path} /index.html
  file_server
}
```

业务层必须继续校验：

- API Key 是否存在、启用、未过期。
- API Key 是否绑定允许的 IP 白名单或网段。
- 用户、部门、模型权限。
- 调用次数、Token、音频、图片、视频和成本限额。
- 并发限制和异常调用检测。
- JSON Body、SSE 流式内容、模型输出内容、代码片段和配置文件的深度脱敏。
- WAF 规则命中后的业务封禁策略，例如临时封禁 Key、来源 IP 降权、二次验证。

### 14.6 Caddy 违规字段脱敏

Caddy 可作为边缘层做轻量字段脱敏，适合处理 Header、Query、Path、访问日志和简单响应 Header。对于 JSON 请求体、JSON 响应体、SSE 流式响应、模型生成内容等需要理解结构的内容，应由 TokenHub Gateway 或 AST 脱敏模块处理。

可在 Caddy 层处理的内容：

| 类型 | 处理方式 |
| --- | --- |
| 请求 Header | 匹配 `Authorization`、`Cookie`、`X-Api-Key` 等字段后删除或替换为 `****` |
| 响应 Header | 删除或替换 `Set-Cookie`、内部 Trace、内部 Server 信息等 |
| Query 参数 | 对 `token`、`key`、`secret` 等参数做拦截或脱敏记录 |
| 访问日志 | 日志中只记录脱敏后的 Header、Query、路径摘要 |
| 错误响应 | 不返回内部字段，统一返回安全错误摘要 |

Caddyfile 轻量示例：

```caddyfile
api.example.com {
  @sensitive_query query token=* key=* secret=*
  respond @sensitive_query 400

  reverse_proxy tokenhub-gateway:8080 {
    header_up -Authorization
    header_up X-Api-Key "****"
    header_down -Server
    header_down X-Internal-Trace "****"
  }

  log {
    output file /var/log/caddy/tokenhub-access.log
    format filter {
      wrap json
      fields {
        request>headers>Authorization replace "****"
        request>headers>Cookie replace "****"
        request>uri query {
          replace token "****"
          replace key "****"
          replace secret "****"
        }
      }
    }
  }
}
```

注意事项：

- 不建议在 Caddy 层改写 JSON 请求体或响应体，容易破坏流式响应和大响应性能。
- OpenAI SSE、Anthropic stream 等流式内容必须优先由 Gateway 做内容级脱敏或审计，不应在 Caddy 中缓存后替换。
- 如果规则命中的是访问凭证，建议阻断请求，而不是只显示 `****` 后继续转发。
- Caddy 层脱敏只做第一层保护，TokenHub Gateway 仍必须执行 AST 脱敏、日志脱敏和内容留存策略。
- 所有 Caddy 层违规字段命中事件应记录安全事件摘要。

### 14.7 缓存和限额性能

- API Key 哈希查询结果短缓存，例如 30 秒到 5 分钟。
- 用户、部门、模型授权缓存，配置变更后主动失效。
- 次数限额、并发限额使用 Redis 原子计数。
- Token 和成本限额可使用聚合缓存，响应完成后异步回写。
- 大屏读取聚合表，不扫描调用明细表。

### 14.8 写入性能

- 调用明细先进入队列，再批量写入数据库。
- 内容留存和媒体资产元信息异步写入。
- 聚合统计按分钟批量聚合。
- 写入失败进入重试队列。
- 队列堆积时应降级：优先保证转发可用，再补写统计。

### 14.9 性能指标

建议设定以下指标：

| 指标 | 目标 |
| --- | --- |
| 网关额外首包延迟 | 普通请求小于 50ms，流式请求小于 100ms |
| 转发成功率 | 不低于 99.9%，不包含上游厂商自身错误 |
| 流式响应 | 首包到达后立即透传 |
| 明细写入 | 允许异步延迟，但正常情况下 1 分钟内可查询 |
| 大屏刷新 | 读取聚合数据，接口响应小于 1 秒 |
| 限额校验 | Redis 原子操作，单次校验毫秒级 |

### 14.10 压测要求

- 对普通文本请求压测并发和吞吐。
- 对 SSE 流式响应压测首包延迟和长连接稳定性。
- 对 ASR/TTS/文生图/文生视频压测任务提交、查询和资产下载。
- 对调用明细队列压测堆积和恢复能力。
- 对 Redis 限额计数压测并发一致性。
- 对 Caddy 到 Gateway 的负载均衡和故障摘除做压测。

## 15. 安全设计

系统安全目标是防止用户数据、生成内容、厂商密钥、TokenHub API Key、统计明细、导出文件和备份文件外泄。所有接口、页面、日志、任务和存储都必须按最小权限原则设计。

### 15.1 认证和权限

- 管理端接口必须鉴权，并在服务端校验角色、部门和数据范围。
- 普通用户只能访问自己的 Key、调用明细、生成内容和统计数据。
- 管理员只能访问授权部门及子部门数据。
- 超级管理员访问敏感数据时也必须记录审计日志。
- 所有列表、详情、导出、删除接口都必须在后端强制加权限过滤。
- 支持用户和部门禁用后实时拦截请求。

### 15.2 密钥安全

- 上游厂商 API Key 必须加密存储。
- TokenHub 对外 API Key 只保存哈希。
- API Key 明文只在创建时展示一次。
- 日志、异常、审计、导出、前端状态管理中不得保存 API Key 明文。
- 支持 Key 立即停用、过期、轮换和删除。

### 15.3 数据存储安全

- 用户输入和模型生成内容必须加密存储。
- 图片、音频、视频等媒体资产必须通过受控存储保存，并通过鉴权接口访问。
- 厂商密钥、系统密钥、导出文件和备份文件必须加密存储。
- 数据库连接必须使用独立账号，并按读写权限拆分。
- 生产环境禁止使用默认密码和弱密码。
- 内容留存天数应可配置，默认不建议永久保存。
- 到期内容必须自动清理，清理任务需要记录执行结果。

### 15.4 传输和网络安全

- 管理端和对外 API 必须使用 HTTPS。
- 内部服务调用应限制在内网或安全网络内。
- 上游厂商请求必须使用 HTTPS。
- 支持管理端 IP 白名单。
- 支持 API Key 级 IP 白名单。
- 支持按 CIDR 配置允许访问的 IP 段，例如仅允许内网、VPN、办公网段。
- 管理端默认不建议开放公网，应默认只允许内网或办公网段访问。
- Caddy 层可以先拦截不允许的 IP 段，TokenHub Gateway 层仍必须二次校验 API Key 的 IP 白名单。
- 私有化部署场景下，API 入口可配置为仅内网可访问。
- 所有被 Caddy 或 Gateway 拦截的访问应记录安全事件摘要。
- 不允许把数据库、Redis、对象存储等内部服务直接暴露到公网。

### 15.5 日志和错误安全

- 错误响应不得暴露上游密钥、内部地址、数据库结构、系统堆栈和敏感请求内容。
- 普通日志不得记录完整请求内容、完整响应内容、API Key、厂商密钥、Authorization 请求头。
- 如需要排查问题，只记录请求 ID、用户 ID、模型、状态码、耗时、Token 数和错误摘要。
- 日志中出现敏感字段时必须脱敏，例如手机号、邮箱、Key、Authorization、Cookie。

### 15.6 AST 脱敏对接设计

AST 脱敏用于处理代码、配置、JSON、YAML、SQL、XML、HTML 等结构化内容。它通过解析语法树识别字段名、变量名、字符串字面量、连接串、密钥、Token、手机号、邮箱、身份证、银行卡、URL 参数等敏感信息，再按策略替换。普通自然语言内容无法稳定构建 AST，应使用正则、字典、NER 或大模型分类器辅助脱敏。

对接位置：

| 位置 | 处理方式 |
| --- | --- |
| 请求入站 | 在转发给上游前可选执行脱敏，适合不允许敏感数据离开内网的场景 |
| 内容留存 | 可选择保存原文、保存脱敏内容、仅保存摘要或完全不保存 |
| 日志记录 | 只允许写入脱敏摘要，禁止写入原始 prompt、响应、密钥和文件内容 |
| 明细导出 | 导出前再次执行脱敏，避免历史数据策略变更造成泄漏 |
| 生成内容查看 | 根据查看人权限决定展示原文、脱敏内容或摘要 |
| 厂商适配器 | 对腾讯云等外部厂商调用前执行出站脱敏策略 |

策略配置：

- 系统级默认脱敏策略。
- 部门级脱敏策略。
- API Key 级脱敏策略。
- 模型级脱敏策略，例如只对外部厂商启用出站脱敏。
- 内容类型策略，例如代码、JSON、SQL、普通文本分别使用不同规则。
- 动作策略：阻断请求、替换敏感值、保留后 4 位、哈希化、仅记录摘要。

建议支持的 AST 解析类型：

| 类型 | 示例 |
| --- | --- |
| JSON | API 请求体、配置文件 |
| YAML | CI 配置、部署配置 |
| JavaScript / TypeScript | 前端代码、Node.js 服务代码 |
| Python | 脚本、服务代码 |
| SQL | 查询语句、连接串 |
| XML / HTML | 配置、页面模板 |
| Shell / Env | `.env`、启动脚本 |

脱敏示例：

```json
{
  "api_key": "sk-xxxx",
  "database_url": "postgres://user:password@host/db",
  "phone": "13800138000"
}
```

脱敏后：

```json
{
  "api_key": "[REDACTED:api_key]",
  "database_url": "[REDACTED:database_url]",
  "phone": "138****8000"
}
```

失败处理：

- 如果 AST 解析失败，应降级到文本规则脱敏。
- 如果文本规则也无法确认安全，按策略决定继续、阻断或只保存摘要。
- 脱敏失败不能静默放行敏感内容，必须记录审计事件。
- 对发往外部厂商的请求，建议默认采用“失败阻断”或“失败只发送脱敏摘要”策略。

审计要求：

- 脱敏策略新增、修改、删除必须审计。
- 查看原文、导出原文、关闭脱敏必须审计。
- 每次脱敏可记录命中规则数量、内容类型、处理动作和请求 ID，但不记录敏感原文。

### 15.7 导出和下载安全

- 管理端导出明细、生成内容、用户数据时必须校验权限。
- 导出生成内容需要单独权限，不应默认开放。
- 导出任务必须记录操作人、时间、筛选条件、数据范围和下载次数。
- 导出文件应设置有效期，到期自动删除。
- 导出文件建议加水印或记录下载人信息，便于追踪泄漏来源。
- 媒体资产下载链接必须短期有效，过期后需要重新鉴权生成。

### 15.8 审计安全

- 敏感操作必须写入审计日志。
- 查看、导出、删除生成内容必须记录审计日志。
- 修改价格、限额、厂商密钥、用户角色、部门权限、内容留存策略必须审计。
- 修改脱敏策略、关闭脱敏、查看原文、导出原文必须审计。
- 审计日志不允许普通管理员删除。
- 审计日志需要保留足够时间，建议不短于 180 天。

### 15.9 备份和恢复安全

- 数据库备份、对象存储备份、导出备份必须加密。
- 备份文件必须限制访问权限。
- 备份恢复操作必须审计。
- 备份文件应设置保留周期，到期自动清理。
- 恢复演练不能使用真实敏感内容，除非在受控环境内执行。

## 16. 日志与可观测性

日志能力必须独立设计，不能只依赖控制台输出。系统需要按用途拆分独立日志文件，并支持脱敏、轮转、留存、查询、告警和链路追踪。

### 16.1 日志分类和独立文件

建议日志目录：

```text
/var/log/tokenhub/
  caddy-access.log
  caddy-security.log
  gateway-access.log
  gateway-error.log
  security.log
  audit.log
  usage-write.log
  provider.log
  multimodal-task.log
  media-asset.log
  desensitization.log
  performance.log
  worker.log
```

| 日志文件 | 内容 | 是否允许包含敏感正文 |
| --- | --- | --- |
| `caddy-access.log` | Caddy 入口访问摘要、状态码、耗时、IP、路径 | 否 |
| `caddy-security.log` | Caddy IP 拦截、WAF 命中、违规字段命中 | 否 |
| `gateway-access.log` | API Key 鉴权后的请求摘要、模型、用户、部门、状态 | 否 |
| `gateway-error.log` | Gateway 内部错误、上游调用失败、异常栈摘要 | 否 |
| `security.log` | 安全事件、异常 IP、越权访问、限额异常、Key 异常 | 否 |
| `audit.log` | 管理员操作、配置变更、查看原文、导出、删除 | 否 |
| `usage-write.log` | 明细写入、聚合写入、队列重试、补偿结果 | 否 |
| `provider.log` | 上游厂商耗时、状态码、错误摘要、重试和熔断 | 否 |
| `multimodal-task.log` | ASR、TTS、文生图、文生视频任务状态变化 | 否 |
| `media-asset.log` | 媒体资产生成、下载链接生成、清理结果 | 否 |
| `desensitization.log` | 脱敏策略命中、处理动作、失败降级 | 否 |
| `performance.log` | P50/P95/P99、队列堆积、Redis 耗时、首包延迟 | 否 |
| `worker.log` | 后台任务、清理任务、备份任务、定时任务 | 否 |

### 16.2 日志字段规范

所有日志建议使用 JSON Lines 格式，便于检索和接入日志平台。

通用字段：

| 字段 | 说明 |
| --- | --- |
| `timestamp` | 日志时间 |
| `level` | `debug`、`info`、`warn`、`error` |
| `request_id` | 请求 ID，全链路一致 |
| `trace_id` | 链路追踪 ID |
| `user_id` | 用户 ID，可为空 |
| `department_id` | 部门 ID，可为空 |
| `api_key_id` | Key ID，不记录明文 Key |
| `provider_id` | 厂商 ID |
| `model_id` | 模型 ID |
| `modality` | 文本、视觉、ASR、TTS、文生图、文生视频 |
| `client_ip` | 客户端 IP |
| `status` | 成功、失败、拒绝、超时 |
| `latency_ms` | 耗时 |
| `error_code` | 错误码 |
| `message` | 脱敏后的摘要 |

禁止写入：

- API Key 明文。
- 上游厂商密钥。
- Authorization、Cookie 明文。
- 完整 prompt。
- 完整模型响应。
- 用户上传的图片、音频、视频内容。
- 数据库连接串、服务器内网地址、完整异常堆栈中的敏感值。

### 16.3 日志脱敏和安全

- 所有日志写入前必须经过统一脱敏器。
- Caddy 日志先做 Header、Query、Path 层脱敏。
- Gateway 日志再做业务字段、JSON、AST 脱敏。
- 脱敏失败时，日志只记录 `redaction_failed=true` 和摘要，不记录原文。
- 调试日志默认关闭，生产环境临时开启时必须有有效期。
- 日志文件权限只允许服务账号写入、运维或安全账号读取。
- 日志导出必须审计，并设置导出文件有效期。

### 16.4 日志轮转和留存

日志留存时间必须可配置，支持按日志类型设置不同保留天数。系统应提供默认策略，也允许超级管理员调整。

配置字段：

| 字段 | 说明 |
| --- | --- |
| `log_type` | 日志类型，例如 access、security、audit、provider、performance |
| `retention_days` | 在线日志保留天数 |
| `archive_days` | 压缩归档保留天数 |
| `compress_enabled` | 是否压缩归档 |
| `encrypt_archive_enabled` | 是否加密归档 |
| `cleanup_enabled` | 是否启用到期清理 |
| `min_retention_days` | 最小允许保留天数 |
| `max_retention_days` | 最大允许保留天数 |
| `updated_by` | 最后修改人 |
| `updated_at` | 最后修改时间 |

默认建议：

| 日志类型 | 留存周期 | 轮转策略 |
| --- | --- | --- |
| 访问日志 | 30 到 90 天 | 按天或按大小轮转 |
| 错误日志 | 90 到 180 天 | 按天或按大小轮转 |
| 安全日志 | 不少于 180 天 | 按天轮转，归档加密 |
| 审计日志 | 不少于 180 天，重要场景 1 年以上 | 不允许普通管理员删除 |
| 性能日志 | 30 到 90 天 | 聚合后可降采样 |
| 任务日志 | 90 天 | 与任务明细留存一致 |

约束规则：

- 审计日志和安全日志不允许设置过短留存，建议最少 180 天。
- 普通访问日志可根据磁盘容量设置为 30 到 90 天。
- 性能日志可在聚合后缩短明细留存。
- 修改日志留存策略必须写入审计日志。
- 关闭日志清理必须由超级管理员操作。
- 到期清理只删除过期日志文件，不删除业务明细和审计数据。

日志文件应支持：

- 按大小轮转。
- 按日期轮转。
- 压缩归档。
- 加密归档。
- 到期自动清理。
- 清理任务结果写入 `worker.log`。

### 16.5 日志查询和告警

后台应提供日志查询入口，但必须受权限控制。

查询条件：

- 时间范围。
- 日志类型。
- 请求 ID / Trace ID。
- 用户、部门、API Key。
- 厂商、模型、模态。
- 错误码、状态码。
- 客户端 IP。
- 安全事件类型。

告警建议：

- Caddy WAF 命中次数异常。
- 非允许网段访问次数异常。
- API Key 鉴权失败次数异常。
- 单用户或单部门用量突增。
- 上游厂商错误率升高。
- 队列堆积超过阈值。
- 明细写入失败。
- 证书即将过期或续期失败。
- 脱敏失败或关闭脱敏。
- 审计日志写入失败。

### 16.6 日志和业务数据关系

- `usage_records` 是业务明细，不等同于日志。
- 日志用于排障、安全追踪和运行观测。
- 调用明细用于用量、统计、报表和成本查看。
- 审计日志用于追责和合规。
- 生成内容留存用于查看用户输入和模型输出，必须独立受权限和留存策略控制。

### 16.7 日志导出

日志导出用于排障、安全审计和合规留档。导出必须走异步任务，避免大范围日志查询阻塞管理端。

导出规则：

- 支持按日志类型导出，例如访问日志、安全日志、审计日志、上游厂商日志、性能日志。
- 支持按时间范围、请求 ID、用户、部门、API Key、厂商、模型、错误码、安全事件筛选。
- 导出格式支持 CSV，后续可扩展 JSONL。
- 导出前必须执行权限校验和日志脱敏。
- 导出任务必须写入审计日志。
- 导出文件必须设置有效期，到期自动删除。
- 导出文件下载通过短期链接完成。
- 普通管理员只能导出授权部门范围内的日志。
- 审计日志导出建议仅超级管理员或安全管理员可用。

日志 CSV 建议字段：

```text
timestamp,level,log_type,request_id,trace_id,user_id,department_id,
api_key_id,provider_id,model_id,modality,client_ip,status,http_status,
latency_ms,error_code,message
```

导出任务字段：

| 字段 | 说明 |
| --- | --- |
| `id` | 导出任务 ID |
| `export_type` | `logs`、`session_archive`、`usage_records` 等 |
| `format` | `csv`、`jsonl` |
| `filters` | 导出筛选条件，JSON 格式 |
| `status` | 排队中、处理中、成功、失败、已过期 |
| `file_path` | 导出文件内部路径 |
| `download_count` | 下载次数 |
| `expires_at` | 下载链接和文件过期时间 |
| `created_by` | 创建人 |
| `created_at` | 创建时间 |
| `finished_at` | 完成时间 |

## 17. 错误码

| 错误码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `KEY_INVALID` | 401 | Key 不存在或格式错误 |
| `KEY_DISABLED` | 403 | Key 已停用 |
| `KEY_EXPIRED` | 403 | Key 已过期 |
| `USER_DISABLED` | 403 | 用户已禁用 |
| `DEPARTMENT_DISABLED` | 403 | 部门已停用 |
| `ADMIN_AUTH_REQUIRED` | 401 | 管理端未登录或会话过期 |
| `ADMIN_CSRF_INVALID` | 403 | 管理端 CSRF 校验失败 |
| `ADMIN_PERMISSION_DENIED` | 403 | 管理员无权访问该资源 |
| `SCREEN_AUTH_REQUIRED` | 401 | 独立大屏未登录、已主动退出或会话被撤销 |
| `SCREEN_LINK_DISABLED` | 403 | 独立大屏链接已停用 |
| `SCREEN_LINK_EXPIRED` | 403 | 独立大屏链接登录有效期已过，不允许新登录 |
| `SCREEN_PERMISSION_DENIED` | 403 | 独立大屏无权查看该指标或数据范围 |
| `WAF_BLOCKED` | 403 | Caddy WAF 或 Gateway WAF 规则拦截 |
| `WAF_CONFIG_INVALID` | 400 | WAF 规则配置不合法 |
| `WAF_PUBLISH_FAILED` | 500 | WAF 规则发布或 Caddy reload 失败 |
| `MODEL_FORBIDDEN` | 403 | Key 未授权该模型 |
| `QUOTA_EXCEEDED` | 429 | 调用次数、Token 或成本超过限额 |
| `CONCURRENCY_EXCEEDED` | 429 | 并发请求超过限制 |
| `PROVIDER_TIMEOUT` | 504 | 上游厂商超时 |
| `PROVIDER_ERROR` | 502 | 上游厂商返回错误 |
| `USAGE_PARSE_FAILED` | 500 | 用量解析失败 |

## 18. 后台页面建议

### 18.1 菜单结构

```text
智慧大屏
大屏分享链接
用量明细
生成内容
会话存档
多模态任务
媒体资产
性能监控
统计报表
API Key 管理
用户管理
部门管理
厂商管理
模型管理
价格配置
限额策略
审计日志
日志中心
脱敏策略
WAF 安全策略
WAF 命中事件
系统设置
```

### 18.2 页面重点

- 智慧大屏：看全局概览、部门排行、员工排行、模型排行、厂商分析、多模态调用、安全异常和性能健康。
- 大屏分享链接：生成独立大屏链接，设置独立用户名、密码、数据范围、可看指标、IP 白名单、过期时间、是否允许下钻和访问日志。
- 用量明细：查每一次调用、Token 用量和成本来源。
- API Key 管理：创建 Key、绑定用户、授权模型、配置限额。
- 价格配置：按模型可选维护价格生效时间，用于查看成本并避免历史成本漂移。
- 厂商管理：配置上游地址、密钥、格式和启停状态。
- 部门管理：以树形结构维护多级部门，支持新增子部门、移动部门、同级排序、停用部门和查看部门成员。
- 用量明细：支持查看本次请求的输入内容、生成内容、工具调用和清理状态。
- 会话存档：按用户、部门、Key、模型、时间范围查询会话，并异步导出 CSV。
- 多模态任务：查看 ASR、TTS、文生图、文生视频任务状态、耗时、错误和结果资产。
- 媒体资产：按权限预览或下载图片、音频、视频，查看文件大小、时长、分辨率和到期时间。
- 性能监控：查看请求量、P50/P95/P99 延迟、首包延迟、流式连接数、队列堆积、Redis 限额耗时、上游厂商耗时。
- 日志中心：按独立日志文件、请求 ID、用户、部门、Key、厂商、模型、错误码、安全事件查询日志。
- 导出任务：查看日志导出、会话存档导出、明细导出的任务状态、下载链接和过期时间。
- 脱敏策略：配置 AST 脱敏规则、文本脱敏规则、作用范围、失败处理动作和命中审计。
- WAF 安全策略：配置 Caddy 增强 WAF 结构化规则、规则优先级、作用域、发布版本、回滚和 Caddy reload 状态。
- WAF 命中事件：查看规则命中、来源 IP、请求摘要、处置动作、关联用户或 API Key、告警状态和审计记录。
- 系统设置：配置内容留存开关、默认留存天数、最大留存天数、是否允许导出内容。
- 系统设置：配置管理端 IP 白名单、API 入口允许网段、Key 级 IP 白名单开关、Caddy 违规字段脱敏规则、证书模式、证书剩余有效期告警、导出文件有效期、日志脱敏规则、日志留存天数、日志归档天数、备份保留天数。

## 19. 开发阶段拆分

### 第一阶段：基础可用

- PHP 基础工程、Caddy 入口、MySQL 迁移脚本、Redis 和队列基础设施。
- 原生 JS/TypeScript Admin 静态页面、统一 `api.js`、登录态和 CSRF 处理。
- 独立大屏链接生成、独立用户名密码登录、只读指标接口和访问日志。
- 用户、角色、部门管理。
- 多级部门树、新增子部门、移动部门和部门权限范围。
- 厂商、模型配置；价格配置作为成本查看能力可选开启。
- API Key 生成和鉴权。
- OpenAI 格式代理。
- Caddy 入口代理和 TokenHub Gateway 反向代理链路。
- Caddy 自动 HTTPS、证书自动续期和证书有效期监控。
- 管理端和 API 入口 IP 网段限制。
- 独立日志文件、JSON Lines 格式、基础日志轮转和日志留存时间配置。
- 调用明细记录。
- 生成内容留存开关和默认留存天数配置。
- 基础脱敏策略和日志脱敏。
- 基础用量统计。

### 第二阶段：完整计量

- Anthropic 格式代理。
- 流式响应高速透传。
- 输入输出 Token、次数、可选成本、错误明细。
- Key、用户、部门限额。
- 聚合统计表。
- 明细筛选和导出。
- 按用户、部门、Key 查看生成内容。
- 会话存档 CSV 导出。
- ASR、TTS 接口适配和音频时长统计。

### 第三阶段：运营管理

- 智慧大屏。
- 部门、用户、模型排行。
- 文本、图片、语音、视频等模态调用分析。
- API Key 使用排行和异常来源 IP 分析。
- 安全拦截、脱敏命中、导出异常和系统健康看板。
- 成本趋势和异常分析，未配置价格时只展示用量趋势。
- 审计日志。
- 多厂商优先级和故障切换。
- 安全设置、日志脱敏、导出有效期和审计查询。
- Caddy 增强 WAF、IP 网段白名单、请求体大小限制、违规字段脱敏、规则版本发布和异常访问审计。
- WAF 安全策略页面、WAF 命中事件页面、规则发布审计和回滚。
- 大屏分享链接管理、密码重置、会话撤销、访问日志查询和过期清理。
- 证书续期告警、Caddy 证书存储持久化和多实例证书共享。
- AST 脱敏策略、脱敏事件查询和出站脱敏。
- 日志中心、日志查询、按类型配置日志留存、日志告警和日志导出审计。
- 导出任务中心、日志导出、会话存档导出和导出文件到期清理。
- 性能监控、队列监控、上游厂商延迟分析。
- 文生图、文生视频任务管理和媒体资产管理。

### 第四阶段：增强能力

- 流式请求完整计量。
- Token 估算兜底。
- 多币种成本查看。
- 租户隔离。
- 告警通知。
- 冷热数据归档。
- 生成内容按留存天数自动清理。
- 备份加密、备份保留周期和恢复审计。
- 腾讯云等厂商原生多模态接口扩展，支持更多云厂商适配器。

## 20. 测试重点

- PHP Gateway 可以在 PHP-FPM 模式下正常启动，并通过健康检查。
- Admin 静态 JS 页面可以通过 Caddy 正常访问，并只调用同域 `/admin-api/*`。
- Admin API Cookie 登录、CSRF Token、会话过期、401/403 错误处理符合预期。
- Admin JS 不在 `localStorage`、`sessionStorage`、控制台日志中保存或打印敏感凭证。
- CSP 生效后，外部脚本、内联脚本和点击劫持被阻止。
- MySQL 迁移脚本可从空库完成初始化，关键表、索引、唯一约束符合设计。
- Redis 缓存、限额计数和队列任务可正常读写，队列失败后支持重试和审计。
- Key 创建后明文只展示一次，数据库不保存明文。
- 禁用 Key、用户、部门后，请求必须立即失败。
- 不同角色只能访问授权范围内的数据。
- 多级部门树可以新增、移动、排序；移动部门后子部门路径和权限范围同步更新。
- 管理员只能访问自己负责部门及其子部门，不能访问平级或上级部门数据。
- 价格未配置时系统仍能正常统计次数和 Token；价格变更后，历史成本不被重新计算。
- OpenAI 兼容格式和 Anthropic 兼容格式请求能正确转发。
- 文本、视觉理解、ASR、TTS、文生图、文生视频都能通过 OpenAI 兼容或 Anthropic 兼容 API 调用。
- 成功、失败、超时、限额拒绝请求都能写入明细。
- 启用内容留存后，可以按用户查看输入和生成内容；到期后内容正文被清理但调用明细保留。
- 普通用户不能查看其他用户的生成内容，管理员不能越权查看非授权部门内容。
- API Key、厂商密钥、Authorization、Cookie 不会出现在日志、错误响应和导出文件中。
- AST 脱敏能识别 JSON、YAML、代码、SQL、Env 中的密钥、连接串、Token、手机号、邮箱等敏感内容。
- AST 解析失败时能降级到文本脱敏；按策略阻断、替换或只保存摘要。
- 发往外部厂商前启用出站脱敏时，敏感字段不能离开系统。
- 导出文件到期后自动删除，导出操作能在审计日志中查询。
- 禁用用户、部门、Key 后，相关数据仍保留但不能继续调用或越权访问。
- 限额在并发请求下不会明显超卖。
- 大屏查询不会直接扫描大明细表。
- 智慧大屏按权限展示数据，管理员不能看到非授权部门排行。
- 独立大屏链接必须先输入独立用户名和密码，只有链接码不能直接访问数据。
- 独立大屏账号不能访问 `/admin/*` 和 `/admin-api/*`。
- 独立大屏按配置的数据范围、指标范围和 IP/CIDR 白名单过滤数据。
- 独立大屏输入密码登录后会话不因时间自动过期，刷新页面或关闭浏览器后再次打开仍保持登录。
- 独立大屏只有主动退出后才需要重新登录；刷新页面、关闭浏览器或长时间保持展示不应自动退出。
- 链接停用、密码重置、管理员撤销会话、来源 IP 不再允许或安全策略触发时，系统可以强制下线或拒绝访问，这是安全处置，不作为普通会话过期处理。
- 独立大屏访问日志能记录登录成功、登录失败、退出、刷新、IP 拒绝、新登录因链接到期被拒绝和会话撤销。
- 部门、员工、API Key、模型、厂商排行榜能按调用次数、Token、模态次数、异常数排序。
- 文本、图片、ASR、TTS、文生图、文生视频调用次数和用量能分别统计。
- 大屏指标可以下钻到用量明细、会话存档、日志、导出任务或审计日志。
- 流式响应结束后能正确记录输出 Token。
- ASR 能记录音频时长、识别结果、任务状态和错误。
- TTS 能记录输入字符数、输出音频时长和媒体资产。
- 文生图能记录图片数量、分辨率、资产路径和下载权限。
- 文生视频能记录任务状态、视频秒数、分辨率、资产路径和下载权限。
- 媒体资产下载链接必须短期有效，不能绕过权限访问。
- Caddy 入口能正确转发到多个 TokenHub Gateway 实例。
- 域名模式下 Caddy 能自动申请和续期 HTTPS 证书。
- 内网 IP 或内网域名模式下，Caddy 能使用内部 CA 证书，客户端信任根证书后可正常访问。
- Caddy 数据目录持久化后，重启不会重复申请证书。
- 证书即将过期或续期失败时能产生告警。
- Caddy 能按 CIDR 网段限制访问，非允许网段请求被拒绝。
- 管理端只能通过内网、VPN 或办公网段访问。
- API Key 级 IP 白名单在 Gateway 层仍然生效，不能只依赖 Caddy。
- Caddy 增强 WAF 能拦截非法方法、高风险路径、超大请求体、可疑 Query、异常 Header 和非授权网段。
- Caddy 能对命中的违规 Header、Query、响应 Header 和访问日志字段显示 `****` 或阻断请求。
- Caddy 不应缓存并改写 SSE 流式响应，流式内容脱敏应由 TokenHub Gateway 处理。
- 流式响应不能等待完整响应后再返回，应验证首包透传延迟。
- 调用明细、内容留存、聚合统计异步写入时，不阻塞主转发链路。
- 限额计数在高并发下保持原子性和一致性。
- 各类日志写入独立文件，且均为脱敏后的 JSON Lines。
- 日志轮转、压缩、加密归档和到期清理按策略执行。
- 不同日志类型可以配置不同留存天数，审计日志和安全日志不能低于最小留存要求。
- 修改日志留存时间必须写入审计日志。
- 审计日志不能被普通管理员删除。
- 日志查询受权限控制，导出日志必须记录审计。
- 日志导出通过异步任务生成 CSV，导出文件到期后自动删除。
- 会话存档可按权限导出 CSV，原文导出需要单独权限和审计。
- 已清理正文的会话导出时，只导出结构化明细和清理状态。

## 21. 验收标准

- 后端以 PHP 为主，入口使用 Caddy，核心业务数据存储在 MySQL。
- 不使用 Laravel、Hyperf、ThinkPHP、Symfony 等完整后端框架；Admin 后台由原生 JS/TypeScript 静态页面和 PHP Admin API 自研实现。
- 基础环境可以完成 PHP 服务启动、MySQL 迁移、Redis 连接、队列 Worker 启动和 Caddy 反向代理。
- 可以配置至少两个厂商：一个 OpenAI 格式，一个 Anthropic 格式。
- 对外可以提供 OpenAI 兼容格式和 Anthropic 兼容格式 API。
- 可以使用 Caddy 作为入口代理，并将请求高速转发到 TokenHub Gateway。
- 可以配置 Caddy 域名证书自动签发和自动续期。
- 可以配置内网 IP 或内网域名证书，支持 Caddy 本地 CA 或企业内部 CA。
- 可以监控证书剩余有效期，并在续期失败或即将过期时告警。
- 可以配置管理端和 API 入口允许访问的 IP 段，支持仅允许内网或指定网段访问。
- 可以配置 Caddy 增强 WAF 规则，对非法方法、高风险路径、超大请求体、可疑 Query、异常 Header 和非授权网段进行第一层拦截。
- 可以配置 Caddy 违规字段规则，命中后对 Header、Query、响应 Header、访问日志显示 `****` 或直接阻断。
- JSON 正文、SSE 流式内容和模型输出的深度脱敏由 TokenHub Gateway 或 AST 脱敏模块处理。
- 即使 Caddy 放行，TokenHub Gateway 仍会执行 API Key、IP 白名单、用户、部门、模型和限额校验。
- 流式响应支持边读边写，平台额外首包延迟满足性能指标。
- 可以按日志类型生成独立日志文件，并支持脱敏、轮转、留存时间配置、归档、查询、告警和审计导出。
- 可以为访问日志、安全日志、审计日志、性能日志等分别配置保留天数和归档天数。
- 可以按筛选条件异步导出日志 CSV，并通过短期链接下载。
- 可以按用户、部门、API Key、模型和时间范围导出会话存档 CSV。
- 会话存档导出支持脱敏内容和原文内容权限控制，所有导出行为可审计。
- 可以不配置模型价格，仅统计次数和 Token；配置价格后可按输入输出 Token 查看成本。
- 可以创建超级管理员、管理员、普通用户和多级部门。
- 可以按公司实际组织结构添加多级部门，并支持部门树查询、移动、排序和停用。
- 可以为用户生成对外 Key，并配置模型权限和用量限额。
- 使用 Key 调用模型后，可以在明细中看到用户、部门、模型、次数、输入 Token、输出 Token、可选成本和状态。
- 可以配置生成内容是否保存和保存天数，并按权限查看每个用户生成的内容。
- 系统具备防数据外泄设计：敏感数据加密存储、传输使用 HTTPS、日志脱敏、导出有权限和有效期、敏感操作可审计。
- 可以配置 AST 脱敏策略，对代码、JSON、YAML、SQL、Env 等结构化内容做语法树级脱敏。
- 可以按系统、部门、API Key、模型或厂商启用脱敏策略，并查看脱敏命中事件。
- 可以配置 ASR、TTS、文生图、文生视频模型，并通过 OpenAI/Anthropic 兼容 API 对外提供推理能力。
- 可以接入腾讯云等厂商原生接口，但对外仍通过 OpenAI/Anthropic 兼容 API 提供服务。
- 生成的图片、音频、视频只能通过授权后的短期链接访问。
- 智慧大屏可以展示今日调用、Token、成功率、趋势、部门排行、员工排行、模型排行、厂商分析和异常分析；启用价格后展示成本。
- 智慧大屏可以分别展示文本、图片、语音、视频等不同推理能力的调用次数和用量趋势。
- 智慧大屏支持从排行和异常指标下钻到明细、会话、日志和审计记录。
- 可以生成独立大屏访问链接，并为该链接设置独立用户名、密码、数据范围、可看指标、IP 白名单、过期时间和是否允许下钻。
- 独立大屏输入密码后保持登录状态，不因时间、空闲、刷新页面或关闭浏览器自动过期；正常情况下只有用户主动退出后才需要重新登录。
- 管理员撤销会话、链接停用、密码重置、来源 IP 不再允许或安全策略触发时可以强制下线或拒绝访问，作为安全例外处理。
- 独立大屏账号只能访问大屏只读接口，不能进入管理后台，不能查看用户生成内容原文、API Key 明文、厂商密钥和导出下载链接。
- 独立大屏所有访问行为可审计，支持查看访问日志、登录失败、IP 拒绝、新登录因链接到期被拒绝和会话撤销记录。
- 超出次数、Token 或启用后的成本限额时，请求被拒绝并记录原因。

## 22. 完整性分析和待补全清单

### 22.1 当前方案已覆盖

| 领域 | 覆盖情况 |
| --- | --- |
| 技术栈 | PHP 8.2+ 原生自研服务、Caddy 2.x、MySQL 9.7、Redis、队列、对象存储、原生 JS/TypeScript Admin |
| 对外协议 | OpenAI 兼容 API、Anthropic 兼容 API |
| 厂商接入 | OpenAI、Anthropic、腾讯云等原生适配器 |
| 推理能力 | 文本、视觉理解、Embedding、ASR、TTS、文生图、文生视频 |
| 权限组织 | 超级管理员、管理员、普通用户、多级部门 |
| API Key | 生成、哈希存储、模型权限、IP 白名单、限额、过期、停用 |
| 用量统计 | 次数、Token、音频秒数、TTS 字符数、图片数量、视频秒数 |
| 成本查看 | 可选价格配置，未配置价格时只统计用量 |
| 内容留存 | 可配置保存天数、按权限查看、到期清理 |
| 多模态资产 | 任务、媒体资产、短期下载链接 |
| 大屏分享 | 独立链接、独立用户名密码、只读范围、IP 白名单、过期、访问日志 |
| 安全 | HTTPS、IP 网段、Caddy 增强 WAF、AST 脱敏、日志脱敏、审计 |
| 性能 | Caddy 入口、流式透传、连接池、Redis 限额、异步写入 |
| 日志 | 独立日志文件、JSON Lines、轮转、留存、查询、告警 |
| 运维 | 证书自动续期、证书告警、备份加密、恢复审计 |

### 22.2 仍需进一步细化

这些内容不是当前方案的阻塞项，但进入开发前建议继续补细。

| 待补全项 | 原因 | 建议 |
| --- | --- | --- |
| 自研 PHP 基础层边界 | 不使用后端框架后，路由、中间件、异常、配置、权限容易被写散 | 明确自研基础层接口、目录规范、入口文件约束和单元测试边界 |
| 运行容量参数 | 已有性能方向，但未绑定机器规格和容量目标 | 明确单机 QPS、SSE 长连接数、Worker 数、MySQL/Redis 规格 |
| 数据库字段类型 | 当前主要是字段级说明 | 输出正式 ERD、字段类型、索引、唯一约束、迁移脚本 |
| 适配器 SDK 规范 | 厂商很多，容易各写各的 | 定义 Provider Adapter 接口、错误映射、重试、熔断、用量归一化 |
| OpenAI/Anthropic 兼容细节 | 多模态扩展容易产生兼容差异 | 为每个端点补请求/响应示例和错误示例 |
| 限额一致性 | 高并发下可能超卖 | 明确 Redis key 设计、过期策略、原子脚本和补偿机制 |
| 队列可靠性 | 明细、日志、聚合异步后会有丢失风险 | 明确消息队列、重试、死信队列、幂等键 |
| 日志落地方案 | 独立文件只是第一步 | 明确是否接入 Loki、ELK、ClickHouse 或云日志服务 |
| 告警渠道 | 只写了告警类型 | 明确企业微信、钉钉、邮件、Webhook、短信等渠道 |
| Caddy 配置生成 | 多租户或多环境时配置会复杂 | 设计后台生成 Caddyfile 或配置模板的流程 |
| 安全合规 | 涉及用户内容和日志 | 明确等保、数据分级、审计保留、隐私协议和操作审批 |
| 备份恢复演练 | 只写了备份要求 | 定义 RPO、RTO、演练频率和恢复检查清单 |
| 压测指标 | 已有目标但未绑定规模 | 明确目标 QPS、并发连接数、SSE 长连接数、队列堆积阈值 |

### 22.3 建议实施优先级

MVP 必须先做：

- OpenAI 兼容 API。
- API Key 鉴权、模型权限、IP 白名单。
- 用户、角色、多级部门。
- 厂商、模型、可选价格配置。
- 调用明细、基础用量统计。
- Caddy 入口、HTTPS、IP 网段限制。
- 独立日志文件、日志脱敏、审计日志。
- 基础大屏和明细查询。

第二阶段做完整：

- Anthropic 兼容 API。
- ASR、TTS、文生图、文生视频。
- 多模态任务和媒体资产。
- 内容留存、AST 脱敏、导出控制。
- 完整限额、聚合统计、性能监控。
- Caddy 增强 WAF、证书告警、日志中心。

第三阶段做增强：

- 多厂商故障切换。
- 成本分析和利润分析。
- 多租户。
- 专业 WAF 或云 WAF。
- 高可用队列、死信补偿。
- 全链路追踪和集中日志平台。
- 自动化备份恢复演练。

### 22.4 方案主要风险

- 多模态能力跨度大，建议不要和文本网关一次性全部开发。
- 内容留存和日志如果处理不好，会成为数据泄漏风险点。
- Caddy 层规则不能替代业务鉴权，Gateway 必须始终强校验。
- 流式响应不能为了脱敏或统计而完整缓存，否则会影响体验和性能。
- 厂商原生接口差异很大，必须通过适配器接口统一边界。
- 如果价格配置只是内部成本查看，不要过早做复杂账单和充值系统。
