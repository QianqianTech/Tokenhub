# 平台范围与验收证据矩阵（TH-001 / TH-017 / TH-036）

本文件定义“完成”的口径：每项对外能力写明交付级别、验收场景和证据位置。证据级别从低到高分开记录，**低级别证据不能替代高级别结论**。

## 证据级别

| 级别 | 含义 | 证据位置 |
| --- | --- | --- |
| L0 预设存在 | 代码/配置中有入口或预设 | 源码路径 |
| L1 静态检查 | `php -l`、源码合同断言 | CI lint、`tests/Support`、`tests/Frontend` |
| L2 行为测试 | 假上游/模拟存储下的行为 | `php bin/run-unit-tests.php` 输出 |
| L3 集成测试 | MySQL + Redis（+Qdrant）下的完整测试 | `php tests/run.php` 输出（含 commit） |
| L4 真实联调 | 真实账号、具体模型 ID、地域 | `scripts/provider-acceptance.php --out=...` 报告 |
| L5 生产验收 | 目标环境、目标负载、恢复演练 | 压测报告、恢复演练记录 |

报告必须记录 commit、环境、模型、执行时间与 通过/失败/未测；**未测不得记为通过**。

## 交付级别（建议起点，待产品确认 Q01–Q10）

| 能力 | 级别 | 当前最高证据 | 说明 |
| --- | --- | --- | --- |
| Chat Completions（含流式） | 核心交付 | L2 | 授权、预算、故障切换、流式终态与用量已有行为测试 |
| Anthropic Messages（含流式） | 核心交付 | L2 | 用量与流事件已有夹具；Kimi 桥接已修复跨分片 |
| Responses | 条件发布 | L2 | 无状态调用；存储/检索类接口明确 501；`previous_response_id` 固定渠道 |
| 工具调用 / JSON Schema | 条件发布 | L2 | 合同校验与流式重建；逐模型需 L4 |
| Embeddings | 核心交付 | L1 | 需 L4 |
| 语音转写（multipart） | 条件发布 | L2 | multipart 解析与转发；需 SDK 验证 |
| 语音合成（二进制） | 条件发布 | L1 | 默认返回二进制；需 SDK 验证 |
| 图片/视频生成（火山方舟） | 条件发布 | L1 | 后台轮询与超时终态；需 L4 |
| native 渠道 | 暂不承诺 | — | 无执行后端，路由时返回 501 |
| OpenRouter 治理 | 条件发布 | L2 | 参数策略已测；generation 对账需 L4 |
| RAG | 核心交付 | L3（原有测试） | 安全链调整需 L3 复验；生命周期需 Qdrant 环境 |
| 额度/成本 | 核心交付 | L2 | 硬预算与结算已测；分币种聚合需 L3 |
| 告警处置 | 条件发布 | L1 | 去重/静默/恢复需 L3 |
| 备份恢复 | 核心交付 | L1 | 需 L5 演练 |

## 首批模型验收矩阵（TH-017，全部待验证）

按实际账号填写具体模型 ID、地域、账号类型。运行：

```bash
TOKENHUB_URL=https://tokenhub.example TOKENHUB_API_KEY=tkh_... \
php scripts/provider-acceptance.php --models=<id1>,<id2> --scenarios=models,chat,stream,tools,json_schema --out=evidence/<date>-<provider>.json
```

| 平台 | 具体模型 ID | 地域/账号 | models | chat | stream | tools | json_schema | vision | embedding | 报告 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| OpenAI | 待定 | 待定 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | — |
| Anthropic | 待定 | 待定 | 未测 | 未测 | 未测 | 未测 | — | 未测 | — | — |
| Google Gemini（OpenAI 兼容） | 待定 | 待定 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | — |
| DeepSeek | 待定 | 待定 | 未测 | 未测 | 未测 | 未测 | 未测 | — | — | — |
| 百炼 / Qwen | 待定 | 待定 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | — |
| Moonshot / Kimi | 待定 | 待定 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | — | — |
| 智谱 / GLM | 待定 | 待定 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | — |
| 火山方舟 / 豆包 | 待定 | 待定 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | — |
| OpenRouter | 待定（命名空间 ID） | — | 未测 | 未测 | 未测 | 未测 | 未测 | 未测 | — | — |

验收通过的特性写入模型能力合同的 `verified` 层（`models.capabilities.verified.features`），之后路由才会把该模型用于需要该特性的请求。

## 发布演练清单

开通厂商与模型 → 分配权限和额度 → 成功调用与工具多轮 → 异常与断连 → 重试后对账（`bin/usage-reconcile.php --check`）→ 撤权与删除 → 告警处理 → 备份恢复。每一步保留请求或任务 ID，并能通过 `GET /admin-api/diagnostics/requests/{request_id}` 追到实际渠道、权限依据和计量结果。
