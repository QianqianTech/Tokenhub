# 03 厂商、模型和价格配置开发总结

## 结论

`03-provider-model-price` 任务已完成。当前清单中的开发任务、测试任务和验收标准均已勾选，相关全量测试已通过。

验证命令：

```bash
docker compose exec php php bin/run-tests.php
```

验证结果：

```text
30 test file(s) passed.
```

前端语法验证：

```bash
node --check public/admin/assets/js/app.js
node --check public/admin/assets/js/dashboard.js
node --check public/admin/assets/js/resources.js
```

## 开发范围

本任务围绕厂商接入、模型配置、能力声明、价格配置和成本快照闭环展开，保留并完善以下核心数据表：

- `providers`
- `models`
- `model_prices`
- `usage_records.price_snapshot`
- `audit_logs`

实现目标是让管理员可以配置上游厂商、模型和价格，让 Gateway 能按启用状态和能力选择模型，并在用量写入时保留命中的价格快照，保证历史成本不受后续改价影响。

## 已完成能力

### 厂商配置

- 支持 OpenAI、Anthropic、native 三类协议枚举。
- 支持厂商名称、基础地址、超时、重试次数、默认 Header 等配置。
- 厂商密钥通过服务端加密后写入 `encrypted_secret`。
- API 响应和 Admin 前端状态不返回 `encrypted_secret` 明文或密文。
- 厂商密钥仅暴露 `secret_status`，用于显示 `configured` 或 `empty`。
- 禁用厂商后，模型路由不会再选择该厂商。

关键代码：

- `app/Services/CrudService.php`
- `app/Services/SensitiveDataService.php`
- `app/Services/ProviderHttpClient.php`
- `app/Services/Gateway/ModelRouter.php`

### 模型配置

- 支持模型归属厂商、内部名称、上游名称、能力类型、上下文长度、路由优先级和状态。
- 能力枚举覆盖：
  - `text_generation`
  - `vision_understanding`
  - `embedding`
  - `asr`
  - `tts`
  - `image_generation`
  - `video_generation`
- 禁用模型后，路由不会选择该模型，API Key 模型权限查询也只返回 active 模型。
- native 协议可用于多模态长任务和资产闭环。

关键代码：

- `app/Services/Gateway/ModelRouter.php`
- `app/Services/ApiKeyService.php`
- `public/admin/assets/js/resources.js`

### 价格配置

- 支持 `model_prices` 新增、编辑和列表查询。
- 支持按 `effective_at` 命中当前生效价格。
- 价格字段允许为空；未配置价格时只统计用量，不计算成本。
- 支持多种成本维度：
  - 输入 token
  - 输出 token
  - 音频秒数
  - TTS 字符数
  - 图片数量
  - 视频秒数
  - 请求次数
- 新调用使用当前命中的价格，历史调用保留旧的 `price_snapshot`。

关键代码：

- `app/Services/PriceService.php`
- `app/Services/UsageService.php`
- `app/Services/CrudService.php`

### Admin 页面

- Admin 资源配置中已提供厂商、模型、价格入口。
- 厂商表单支持协议、基础地址、密钥、超时、重试和默认 Header。
- 模型表单支持厂商、名称、能力、上下文长度、状态和路由优先级。
- 价格表单支持各类单价、币种和生效时间。
- 价格列表支持按生效时间倒序查询。
- 厂商密钥不在前端表格、状态或 API 响应中泄漏。

关键代码：

- `public/admin/assets/js/resources.js`
- `public/admin/assets/js/app.js`
- `public/admin/assets/js/table.js`

## 验收覆盖

已通过的 03 相关测试包括：

- `tests/Console/MigrationFilesTest.php`
- `tests/Services/PriceServiceTest.php`
- `tests/Services/ModelRouterTest.php`
- `tests/Services/SensitiveDataServiceTest.php`
- `tests/Services/ProviderHttpClientTest.php`
- `tests/Services/UsageWritePathTest.php`
- `tests/Services/CrudServiceRetentionExportTest.php`
- `tests/Frontend/AdminFrontendSecurityTest.php`

覆盖场景：

- `providers`、`models`、`model_prices` 表结构存在。
- 未配置价格时成本为空。
- 配置价格后能够计算成本并写入价格快照。
- 改价后历史成本不重算。
- 禁用厂商后路由不选择该厂商。
- 禁用模型后路由不选择该模型。
- 厂商密钥不出现在 API 响应、日志敏感输出和前端状态中。
- Admin 前端不使用 `localStorage`、`sessionStorage`，不输出 `console.*`，不使用动态 `innerHTML` 写入。

## 本轮补充验证的失败点

本轮额外验证并收敛了以下失败点：

- Caddy 增加 `caddy-security.jsonl` 专用安全日志，满足 WAF 安全日志验收。
- WAF 版本资源和 `wafPublish` 元数据已在 Admin 资源配置中暴露。
- 脱敏策略按实际命中项累计 `match_count`。
- 媒体资产下载 token 支持 API Key 和大屏 scope 两类权限校验。

相关验证结果：

```text
PASS tests/Frontend/AdminFrontendSecurityTest.php
PASS tests/Services/DesensitizationPolicyTest.php
PASS tests/Services/MediaAssetServiceTest.php
PASS tests/Services/WafServiceTest.php
```

## 当前状态

03 已满足任务清单要求，可以作为后续 Gateway 主链路、计量聚合、内容留存、导出、安全日志和 Ubuntu 验收工作的基础。

当前仍需在后续任务继续推进的内容不属于 03 范围，包括真实上游全链路压测、生产 Caddy reload 流程、systemd Worker 部署、备份恢复和最终 Ubuntu 生产验收清单。
