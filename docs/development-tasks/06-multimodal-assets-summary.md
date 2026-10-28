# 06 多模态和媒体资产开发总结

## 开发结论

`06-multimodal-assets.md` 对应的多模态和媒体资产能力已完成。当前实现覆盖文本、视觉理解、Embedding、ASR、TTS、文生图、文生视频等 capability 映射，并统一了多模态任务、媒体资产、短期下载链接、用量记录、权限校验和到期清理。

本次开发保持项目现有 Native PHP 8.2 架构，不接入具体云厂商 SDK。OpenAI/Anthropic 兼容入口继续作为对外协议，厂商原生差异通过内部 parser、adapter、task service 和 asset service 屏蔽。

## 已完成内容

- 多模态 capability：`OpenAIRequestParser` 和 `AnthropicRequestParser` 支持文本、视觉理解、Embedding、ASR、TTS、文生图和文生视频能力识别。
- 视觉理解：包含图片 content block 的 OpenAI/Anthropic 请求会映射为 `vision_understanding`，并记录 `image_count`、输入 token 和输出 token。
- ASR：`/v1/audio/transcriptions` 映射为 `asr`，记录音频秒数、任务状态、上游识别结果和失败原因。
- TTS：`/v1/audio/speech` 映射为 `tts`，记录输入字符数；上游二进制音频响应会转为内部媒体资产。
- 文生图和文生视频：统一创建 `multimodal_tasks`，记录图片数量、视频秒数、分辨率、任务状态和结果资产。
- 异步任务：长任务统一返回内部 `task_id`；同步成功会立即完成任务，异步上游状态会保持为 `processing`。
- 任务查询：`GET /v1/tasks/{id}` 通过 `MultimodalTaskService` 返回任务状态、结果、错误和公开资产信息。
- 资产短链：`GET /v1/assets/{id}/download-url` 生成 300 秒短期下载链接，不暴露 `storage_path` 或上游永久 URL。
- 大屏分享下载：新增 `/screen-api/assets/{id}/download-url`，按 `dashboard_share_links.scope_type` 和 `scope_ids` 校验大屏分享范围。
- 下载安全：`DownloadController` 会基于 token 内保存的 API Key、用户、部门或大屏 scope 重新校验资产访问权限。
- 资产存储：支持 provider URL、base64 内容和资产数组响应；base64 内容会保存到 `storage/media`。
- 到期清理：`WorkerService` 到期清理只删除或清空资产文件路径，不删除 `media_assets` / `multimodal_tasks` 业务明细，并写入审计事件。
- 用量关联：`UsageService` 写入 `usage_records` 后会回填 `multimodal_tasks.usage_record_id`，便于任务和调用明细关联。
- 短链过期：`RedisClient` 内存 fallback 支持 TTL，保证无 Redis 扩展环境下短期下载链接也会过期。
- 大屏模态分析：`DashboardService::multimodal()` 聚合 capability、任务状态和资产类型；screen API 暴露 `multimodal` 指标路由。

## 数据库变更

新增迁移：

- `database/migrations/2026_07_08_000006_multimodal_assets_v1.sql`

主要字段：

- `multimodal_tasks.usage_record_id`
- `multimodal_tasks.progress`
- `multimodal_tasks.submit_payload`
- `multimodal_tasks.finished_at`
- `media_assets.usage_record_id`
- `media_assets.api_key_id`
- `media_assets.access_scope`

迁移执行已在 Docker MySQL 环境验证：

```sh
docker compose exec -T php php bin/migrate.php
```

执行结果：`Applied 0 migration(s).`，说明当前环境已无待执行迁移；`2026_07_08_000006_multimodal_assets_v1.sql` 已记录在 `migrations` 表中。

## 主要变更文件

- `app/Services/GatewayService.php`
- `app/Services/MultimodalTaskService.php`
- `app/Services/MediaAssetService.php`
- `app/Services/UsageService.php`
- `app/Services/WorkerService.php`
- `app/Services/Gateway/OpenAIRequestParser.php`
- `app/Services/Gateway/AnthropicRequestParser.php`
- `app/Services/Providers/OpenAIAdapter.php`
- `app/Services/Providers/AnthropicAdapter.php`
- `app/Services/Providers/NativeMultimodalAdapter.php`
- `app/Http/Controllers/DownloadController.php`
- `app/Http/Controllers/ScreenController.php`
- `app/Bootstrap/Kernel.php`
- `app/Support/RedisClient.php`
- `app/Console/MigrationRunner.php`

## 新增和更新测试

- `tests/Services/GatewayRequestParserTest.php`
- `tests/Services/MediaAssetServiceTest.php`
- `tests/Services/MultimodalTaskServiceTest.php`
- `tests/Services/WorkerServiceTest.php`
- `tests/Support/RedisClientTest.php`
- `tests/Frontend/ScreenShareFrontendTest.php`
- `tests/Console/MigrationFilesTest.php`
- `tests/run.php`

## 验证结果

完整测试套件已在 Docker PHP 容器中通过：

```sh
docker compose exec -T php php tests/run.php
```

执行结果：`All tests passed`。

Composer 测试脚本也已通过：

```sh
docker compose exec -T php composer test
```

执行结果：`38 test file(s) passed.`。

## 验收状态

- 多模态能力统一通过 OpenAI/Anthropic 兼容接口对外提供：已完成。
- 厂商原生差异通过内部适配器和任务模型屏蔽：已完成。
- 媒体资产访问受用户、部门、API Key、大屏分享范围、短链有效期和审计控制：已完成。
- 到期清理保留业务明细，只删除文件或清空可下载路径：已完成。
- 用量统计和大屏模态分析可读取多模态调用数据：已完成。

## 后续建议

06 通用 v1 已完成。后续如果需要接入腾讯云、阿里云、讯飞等厂商，应按厂商单独增加 native adapter、签名逻辑、任务轮询或回调处理，并补充端到端 smoke test。
