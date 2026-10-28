# TokenHub 平台完善任务执行报告

执行日期：2026-10-28（America/Los_Angeles）。基线：`1ea2251`，并与工作区中同期进行的“移除 WAF / 品牌资源”改动三方合并（`AlertService` 冲突已解决，WAF 告警评估随之移除）。对应清单：`2026-10-28-platform-completeness-audit.md`，结构化状态已回写 `2026-10-28-platform-completeness-tasks.json`（每项任务的 `status` 与 `execution` 字段）。

## 结论

42 项候选任务中，可以用代码收口的部分已经完成：**M1 的 12 项 P0 全部有代码修复与行为测试**；M2/M3 的大部分完成服务端实现；需要真实账号、目标环境或产品决策的任务只提供工具、模板和决策记录，状态如实标记为未验证。

| 状态 | 数量 | 任务 |
| --- | --- | --- |
| 已实现，有行为测试 | 20 | TH-002 003 004 005 006 007 008 009 010 012 013 021 022 023 029 031 032 033 035 039 |
| 已实现，需 MySQL/真实环境复验 | 7 | TH-011 014 016 020 027 034 038 |
| 部分实现（服务端完成，后台界面或验收待补） | 8 | TH-001 015 019 024 025 026 028 036 |
| 需环境验收（提供工具，未执行） | 3 | TH-017 030 037 |
| 产品决策（已起草决策记录） | 4 | TH-018 040 041 042 |

## 验证证据

- 语法检查：`app/ bin/ scripts/ tests/` 全部 PHP 文件 `php -l` 通过。
- 行为测试：`php bin/run-unit-tests.php`，8 个文件全部通过（PHP 8.3.6 + Redis 7）。
  - `GatewayFlowTest`：真实 `GatewayService` 对接假上游，SQLite 模拟关系库，覆盖渠道级授权、429 故障切换、聚合参数策略、native 拒绝、流式终态与用量、工具调用合同、模型额度拒绝时退还通用额度、硬输出预算与结算。
  - `ProviderHttpClientBehaviourTest`：503 重试、429 Retry-After、不跟随重定向、出站拦截不重试、跨分片 SSE、读超时、客户端断连。
  - `SseStreamAccumulatorTest`：逐字节拆分、合片、CRLF、注释、多行 data、流内 error、缺失终止帧；Chat/Responses/Anthropic/OpenRouter 用量与工具参数重建。
  - `ReservationLifecycleAndQueueTest`：拒绝/结算/异常终止三种终态的额度退还，重复终结无负数，崩溃租约回收，熔断器，配置代际失效，队列原子重试、死信、续租。
  - `CredentialProtectionTest`、`OutboundAndEndpointPolicyTest`、`RoutingPolicyAndBudgetTest`、`ContractValidationTest`。
- 原有测试：189 个测试文件（已合并同期移除 WAF 的改动）在无 MySQL 的环境中与合并前逐文件对比，未引入新的失败（122 通过）。依赖 MySQL/Qdrant 的 61 个文件本轮无法运行（合并前同样无法运行），需在 compose 环境执行 `php tests/run.php`；另有 5 个前端缓存版本号断言与 `ConfigTest` 在合并前即失败，与本轮改动无关，未处理。
- 有两处原有测试按新契约调整：`ApiKeyServiceManagementTest`（缓存键带配置代际）、`ProviderHttpClientTest`（流式只在首字节前重试、不跟随重定向）；`MediaAssetServiceTest` 按新的默认访问范围改写并增加用例。
- 未执行：真实厂商/OpenRouter 调用、SDK 兼容验证、压测、备份恢复演练、MySQL 迁移实跑。

## 按任务的处理结果

### M1 核心正确性（P0）

- **TH-002 授权约束路由**：Key 缓存行携带已授权的模型记录 id，`ModelRouter` 在同一候选集合内授权与排序（`m.id IN (...)`），按 Key 授权集合分区缓存；无候选时 `diagnose()` 区分 `MODEL_NOT_FOUND / MODEL_FORBIDDEN / MODEL_UNAVAILABLE / MODEL_CAPABILITY_UNSUPPORTED`；实际渠道与每次尝试写入用量记录。
- **TH-003 二次路由约束**：`AggregatorPayloadPolicy` 默认拒绝客户端 `provider/plugins`；`models[]` 仅允许同渠道已授权模型并改写为上游 id；服务端 `tokenhub_provider_policy` 强制注入，客户端只能收窄（vendor 列表、价格上限、数据收集、fallback）；自动路由模型默认禁用。所有拒绝发生在上游调用之前。
- **TH-004 资产访问边界**：`AssetAccessPolicy` 统一任务、媒体、文件、下载令牌；默认 `user` 范围；删除/过期不可见；下载时复核签发 Key 是否仍有效；未授权与不存在返回相同错误。
- **TH-005 头部凭据与主密钥**：`ProviderHeaderVault` 加密敏感头部、掩码显示、保留旧值；`Crypto` 生产环境拒绝缺失/默认/过短密钥并在启动时失败。
- **TH-006 出站策略**：`OutboundUrlPolicy` 统一 provider / 远程资产 / webhook；元数据与链路本地地址始终禁止；私网需显式放行；`SafeRemoteFetcher` 逐跳复核重定向并限制大小与超时；上游调用不跟随重定向。
- **TH-007 预留释放与终态**：`abort_json` 改为抛出 `HttpAbortException`（入口统一渲染）；`RequestLifecycle` 管理并发、token、费用预留，`reject`（全额退还含请求数）/`settle`（按实测结算）幂等；shutdown 钩子覆盖 exit、致命错误与断连；Redis 租约 + worker 清扫回收进程崩溃留下的并发占用。
- **TH-008 输出与费用预算**：按最终 payload 与声明的最大输出预留，未声明使用可配置默认值；费用限额时按价格预留成本，未知价格按策略处理；RAG 注入后计算增量并追加预留，超额在调用前拒绝。
- **TH-009 SSE**：`SseEventParser` 跨分片缓冲；`StreamAccumulator` 识别 `[DONE]`、`message_stop`、`response.completed/incomplete/failed` 与流内 error；`ProviderHttpClient::stream` 报告 `eof/timeout/client_aborted`；异常流记为失败（`PROVIDER_STREAM_ERROR / PROVIDER_STREAM_INCOMPLETE / PROVIDER_TIMEOUT / CLIENT_CANCELLED`），仅首字节前允许重试。Kimi 桥接的逐行解析同样改为跨分片缓冲。
- **TH-010 用量归一**：`UsageNormalizer` 统一 Chat、Responses、Anthropic、DeepSeek、OpenRouter 口径（输入含缓存、输出含推理），累计值取最新而非相加；记录 `usage_source`（measured/estimated）与 `upstream_cost`。
- **TH-011 记账可恢复**：明细、内容、关联与聚合在一个事务内完成并写 `aggregated_at`，事件后写 `events_emitted_at`；重试从未完成阶段继续；并发插入冲突转为续做；生产者写入业务时间（`created_at`），按业务时间定价和分桶；提供 `rebuildAggregates` 与漂移检查。
- **TH-012 队列可靠性**：重试与死信改为单个 Lua 原子迁移；按任务租约续期（SIGALRM）；`scripts/worker.php` 改用同一可靠 worker；未知任务进入死信；`bin/queue-replay-failed.php` 诊断与重放。
- **TH-029 RAG 安全链**：流程为 脱敏 → 检索 → 按协议注入（Anthropic `system`、Responses `instructions`、Chat system 消息）→ 再次脱敏 → 预算重算；上下文字符预算与截断计数。

### M2 接入、目录与计价

- **TH-013 端点映射**：`ProviderEndpointResolver` 识别 `/v1`、`/v1beta/openai`、`/api/v3`、`/api/paas/v4`、`/compatible-mode/v1` 等版本段，修复 Gemini 重复 `/v1`；支持 `tokenhub_endpoint_map` 与不支持端点声明。
- **TH-014 能力合同**：`models.capabilities`（upstream/verified/admin 三层）与 `capability_tags`；路由按请求所需特性（tools、json_schema、reasoning、图片输入、流式）过滤；有合同时未知即不支持，旧模型保持原行为。待补：后台编辑界面。
- **TH-015 目录同步**：重新导入不再重新启用模型；`syncProvider` 预览/应用新增、变更、下线，写入 `model_catalog_changes`；读取 OpenRouter 架构、参数、定价、上下文。待补：后台预览界面。
- **TH-016 OpenRouter**：保存 generation id、实际模型与供应商、`usage.cost`；`OpenRouterGenerationService` 按 generation 接口补账，`reconciled_at IS NULL` 保证不重复。待补：真实账号联调。
- **TH-019 协议合同**：`/v1/models/{model+}` 支持命名空间 slug 与编码斜杠；模型列表返回 OpenAI 形状；Responses 存储类接口明确返回 501；`previous_response_id` 固定到原渠道且不切换。待补：SDK 验证。
- **TH-020 工具与结构化输出**：`ToolCallContractValidator` 校验 tool 结果与调用 id 对应、tool_choice、json_schema；流式重建工具参数并留存。待补：逐模型行为证据（`scripts/provider-acceptance.php`）。
- **TH-021 故障切换**：多候选、按授权/能力/熔断排序，429/5xx/连接失败切换；切换前校验脱敏策略、协议与路由参数兼容；尝试记录写入 `attempts`。
- **TH-022 配置生效**：`ConfigVersion` 代际计数嵌入路由、Key、额度、脱敏缓存键，审计到的配置变更立即跨实例生效；有历史用量的模型/供应商改为 `retired`。
- **TH-023 成本口径**：十进制字符串精确计算；缓存读写、推理单独定价；`total_cost` 只累计基准币种，其他币种进入 `usage_cost_aggregates_day`。
- **TH-024 额度运营**：额度视图返回在途预留；审计化额度调整接口。待补：后台页面。
- **TH-039 凭据轮换**：密文带密钥编号、旧密钥解密、哈希密钥固定、`bin/rotate-app-key.php` 可续跑；API Key 轮换接口带宽限期。

### M3 管理、RAG、资产与运维

- **TH-025 后台试聊**：携带 `api_key_id` 时以该 Key 走正式网关（授权、额度、脱敏、RAG、记账一致，审计记录管理员）；直连诊断模式结果标记为 `direct_diagnostic`。待补：前端选择 Key。
- **TH-026 身份生命周期**：用户、部门、Key、授权变更通过审计触发配置代际失效。待补：DB 回归用例。
- **TH-027 配置校验**：`ConfigWriteValidator` 校验地址、协议、头部注入、能力别名冲突；`expected_updated_at` 乐观并发。待补：版本化回滚。
- **TH-028 排障引导**：请求诊断接口与错误目录（原因 + 处理办法）；接入与排障手册见 `docs/deployment/onboarding-and-troubleshooting.md`。
- **TH-030 RAG 生命周期**：未改动，需要 Qdrant 环境回归。
- **TH-031 多模态终态**：native 渠道不再路由（501）；后台轮询处理中任务；无执行器或超时的任务置为失败。
- **TH-032 文件与音频**：`/v1/audio/transcriptions` 支持 multipart 并转发；`/v1/audio/speech` 返回原生二进制。
- **TH-033 可观测**：模型健康区分配置、熔断与实际成功率；队列读取失败显示 null；长请求延迟分桶，修复 p95/p99 未选取分桶列的问题；运维状态增加预留、记账阶段与告警通知积压。
- **TH-034 告警**：指纹去重、发生次数、静默、自动恢复、未确认升级、Webhook 通知与重试。
- **TH-035 内容留存**：流式输出文本与工具调用进入留存；队列内容加密；统一大小上限；multipart 只留文件元数据。

### M4/M5

- **TH-036**：新增行为测试套件、无 DB 运行入口与 CI（集成任务为手动触发，待在干净数据库上转绿）。
- **TH-037**：开发 compose 镜像可通过 `REDIS_IMAGE`/`QDRANT_IMAGE` 固定；离线打包仍使用 `latest`，需按现网存储版本决定固定版本；压测未执行。
- **TH-038**：备份增加 Qdrant 快照与 RAG 存储，恢复演练校验快照与核心表；实际演练未执行。
- **TH-017 / TH-018**：提供验收脚本与模板（`docs/acceptance/platform-scope-and-evidence-matrix.md`），未持有厂商凭据，全部为“待验证”。
- **TH-040 / 041 / 042**：决策记录见 `docs/decisions/`，状态为待决策。

## 需要你决定或复验的事项

1. 默认资产共享范围由“同部门”改为“同用户”，是否符合现有部门协作习惯（可配回 department）。
2. 内网推理服务名单（`OUTBOUND_PRIVATE_ALLOWLIST`），升级前配置，否则这些渠道会被拒绝。
3. 离线发布中 Redis/Qdrant 的固定版本需与现网存储版本一致后再修改打包脚本。
4. 在 compose 环境运行 `php bin/migrate.php` 与 `php tests/run.php`，重点关注 UsageService、ModelRouter、Alert 相关 DB 测试。
5. 用真实账号运行 `scripts/provider-acceptance.php` 生成首批模型验收证据。
