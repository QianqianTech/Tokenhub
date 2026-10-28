# TokenHub 平台完善性分析与任务问题清单

分析日期：2026-10-28（America/Los_Angeles）

源码基线：`1ea2251792ce3c7b1489712994bc9a5496d9ee0b`。状态：分析与任务规划，全部任务为 `proposed`。

> **执行更新（2026-10-28）**：本清单已执行，结果见 [执行报告](2026-10-28-platform-completeness-execution-report.md)。下文“当前情况”与证据描述的是基线代码，各任务最新状态以执行报告和 JSON 中的 `status`/`execution` 字段为准。

## 结论与使用方式

TokenHub 已有较完整的模块基础，下一阶段应以平台流程是否闭环为标准。关键缺口集中在授权与实际路由的一致性、请求终态与额度释放、不同协议的真实用量、异步记账恢复、后台与正式调用链的一致性，以及多模态和运维流程的完成度。源码可以证明模块存在及部分缺陷，不能据此给出生产完成率。

本清单包含 **42 项候选任务：P0 12 项、P1 26 项、P2 4 项**，以及 **10 个产品范围问题**。候选任务包括已确认代码问题、待运行验证的风险、完整流程验收和可选范围决策，不能将它们统一解释为 42 个已验证缺陷。

每项任务包含问题、当前依据、工作内容、验收标准和依赖。结构化版本见 [任务数据 JSON](/Users/leo/mysoft/TokenHubcode/docs/development-tasks/2026-10-28-platform-completeness-tasks.json)；源码证据统一收录在文末，便于追踪。

本次重点是接入、授权、调用、计量、恢复、管理和运维能否形成完整流程。主流模型与 OpenRouter 适配纳入同一平台能力体系。普通用户门户、多租户和商业支付分别作为产品选择。

## 平台完善的判断标准

- 接入可用：厂商、模型与能力可发现、配置、验证、更新和退役。
- 授权一致：身份、部门、Key、逻辑模型、实际渠道和任务资产遵循同一规则。
- 调用完整：声明的协议与能力具有明确的成功、失败和取消终态。
- 费用可信：预算、用量、明细、聚合及上游对账可解释，重试不重复或遗漏。
- 故障可恢复：超时、断连、进程退出、队列重投和依赖故障有恢复路径。
- 管理可操作：开通、分配、试用、排障、撤权和退役可完整执行。
- 数据可治理：凭据、上下文、文件、日志、备份与留存遵守明确规则。
- 交付可验证：行为测试、真实联调、容量验收和恢复演练分别保留证据。

## 当前模块与主要缺口

以下“已有基础”指源码或仓库资产存在，不代表本轮完成运行验收。

| 领域 | 已有基础 | 需要完善 | 对应任务 |
| --- | --- | --- | --- |
| 身份、组织与授权 | 已有用户、部门、角色、Key 与模型权限。 | 授权和实际渠道绑定、任务资产访问规则需统一。 | [TH-002](#th-002)、[TH-004](#th-004)、[TH-026](#th-026) |
| 厂商与目录 | 已有预设、模型同步和价格配置，包含 OpenRouter。 | 端点映射、组合能力、同步与退役需完善。 | [TH-013](#th-013)、[TH-014](#th-014)、[TH-015](#th-015)、[TH-016](#th-016)、[TH-017](#th-017) |
| 协议与高级能力 | 已有多协议入口和基础适配器。 | 需逐项验证 Responses、工具、推理、JSON、模型 slug 和 SDK。 | [TH-009](#th-009)、[TH-019](#th-019)、[TH-020](#th-020)、[TH-032](#th-032) |
| 预算、计量与成本 | 已有额度预留、用量、聚合和价格。 | 释放、输出预算、真实 usage、记账恢复与币种需统一。 | [TH-007](#th-007)、[TH-008](#th-008)、[TH-010](#th-010)、[TH-011](#th-011)、[TH-023](#th-023)、[TH-024](#th-024) |
| 路由与可靠性 | 已有路由、可靠队列及失败重试基础。 | 候选切换、流式终态、队列原子迁移和续租需补齐。 | [TH-012](#th-012)、[TH-021](#th-021)、[TH-022](#th-022) |
| 后台与接入流程 | 已有管理配置、聊天、统计与资源页面。 | 试聊需验证正式链路，配置和排障需整条流程验收。 | [TH-025](#th-025)、[TH-027](#th-027)、[TH-028](#th-028) |
| RAG | 已有检索、向量、评测门禁和删除服务。 | 最终 payload 过滤、协议、预算和重建恢复需补齐。 | [TH-029](#th-029)、[TH-030](#th-030) |
| 多模态与资产 | 已有任务、文件、资产及 Ark 提交/刷新。 | native 执行路径、multipart 与音频输出需闭环。 | [TH-031](#th-031)、[TH-032](#th-032) |
| 凭据与数据治理 | 已有主凭据加密、脱敏、留存及 Webhook URL 校验。 | 头部凭据、出站策略、主密钥、流式留存和轮换需统一。 | [TH-005](#th-005)、[TH-006](#th-006)、[TH-035](#th-035)、[TH-039](#th-039) |
| 监控与告警 | 已有健康入口、看板和告警评估入库。 | 实测健康、准确耗时及告警处置流程需完善。 | [TH-033](#th-033)、[TH-034](#th-034) |
| 部署与恢复 | 已有在线/离线部署、worker、备份与恢复脚本。 | 需验证目标负载、可重复发布及全量恢复。 | [TH-037](#th-037)、[TH-038](#th-038) |
| 测试与验收 | 有 188 个 *Test.php 文件及验收文档。 | 需要关键行为与故障证据，静态断言不等于生产验收。 | [TH-001](#th-001)、[TH-036](#th-036) |

## 优先处理的具体问题

1. **授权必须约束实际路由。** Key 按名称授权，路由全局按名称选取；同名模型跨厂商时需要验证实际渠道是否仍在授权内。[E02](#e02)，[TH-002](#th-002)、[TH-021](#th-021)。
2. **请求失败必须正确结束额度预留。** 模型额度拒绝及深层 exit 路径可能跳过清理；输出和 RAG 预算也需纳入预留。[E09](#e09)、[E10](#e10)，[TH-007](#th-007)、[TH-008](#th-008)。
3. **流式终态和用量需要按协议处理。** 当前解析缺少跨网络分片缓冲，Responses 与 Anthropic 的 usage 读取不完整。不能把 HTTP 200 或有输出直接当作成功且计量完整。[E07](#e07)、[E08](#e08)，[TH-009](#th-009)、[TH-010](#th-010)。
4. **异步记账需要可恢复。** 明细已插入但聚合未完成时，现有查重会阻止重试补齐；跨日消费还涉及时间和价格依据。[E11](#e11)、[E13](#e13)，[TH-011](#th-011)、[TH-012](#th-012)。
5. **RAG 与后台试聊应进入完整治理链。** RAG 在出站脱敏后注入；后台聊天走独立直连路径，两者都需要与正式调用规则保持一致。[E19](#e19)、[E20](#e20)，[TH-025](#th-025)、[TH-029](#th-029)。
6. **多模态任务需要实际执行到终态。** native 路径有只入库 queued 的情况；已有 Ark 路径应保留并纳入统一状态与交付流程。[E14](#e14)、[E15](#e15)，[TH-031](#th-031)、[TH-032](#th-032)。
7. **OpenRouter 已有基础预设，重点是治理完整性。** 需约束二次路由参数、同步组合能力和保留实际执行信息，再完成用量及费用对账。[E03](#e03)、[E04](#e04)、[E12](#e12)，[TH-003](#th-003)、[TH-016](#th-016)。
8. **运维页面需要反映真实状态。** 启用状态、实测健康、队列读取失败、精确耗时应分别表达；告警要能处置，备份要能恢复完整业务。[E22](#e22)、[E23](#e23)、[E24](#e24)，[TH-033](#th-033)、[TH-034](#th-034)、[TH-038](#th-038)。

## 主流模型与 OpenRouter 的适配范围

以下是分批候选范围，不是热度排名，也不是当前已兼容清单。具体版本、地域、账号类型、服务端限制和验收凭据应在实施时按官方文档与实际账号确认。

| 批次 | 候选平台 | 重点 |
| --- | --- | --- |
| 首批候选 | OpenAI、Anthropic/Claude、Google Gemini、DeepSeek、百炼/Qwen、Moonshot/Kimi、智谱/GLM、火山方舟/豆包 | 具体模型 ID、账号地域、鉴权、端点、参数、工具/推理/JSON、流式用量与错误。 |
| 聚合平台专项 | OpenRouter | 命名空间模型 ID、目录元数据、二次路由约束、实际供应商、generation 用量及成本对账。 |
| 第二批候选 | MiniMax、xAI/Grok、Mistral、平台托管 Llama、SiliconFlow | 按需求复用能力合同和行为测试；分别识别模型架构、平台品牌与接口协议。 |
| 私有推理候选 | vLLM、Ollama 等 | 显式允许内网端点，验证目录、协议、资源限额与健康。 |

厂商、模型家族、具体模型 ID 和接口协议应分别建模。单次文本聊天成功不足以证明工具调用、视觉、JSON、推理、流式计量或文件音频均可用。每个具体模型只承诺已验收的能力组合。

OpenRouter 的路由策略和模型目录需要分别管理：前者影响实际执行供应商，后者描述模型能力与参数；请求完成后再按 generation 信息核对实际用量。依据：[Provider routing](https://openrouter.ai/docs/guides/routing/provider-selection)、[Models](https://openrouter.ai/docs/api/api-reference/models/list-all-models-and-their-properties)、[Generation metadata](https://openrouter.ai/docs/api/api-reference/generations/get-request-&-usage-metadata-for-a-generation)。

高级能力需使用协议专属合同。Responses 的输出与事件模型、Anthropic 的起始与增量用量、DeepSeek 推理与工具状态应分别验证。依据：[Responses 迁移指南](https://developers.openai.com/api/docs/guides/migrate-to-responses)、[Anthropic streaming](https://platform.claude.com/docs/en/build-with-claude/streaming)、[DeepSeek thinking mode](https://api-docs.deepseek.com/guides/thinking_mode/)。

## 产品范围问题

这些问题用于后续排期和验收定界。下面是建议起点，不表示用户已经选择；已有源码问题的分析可以独立推进。

| 编号 | 需要回答的问题 | 建议起点 | 关联任务 |
| --- | --- | --- | --- |
| Q01 | 本期服务单个企业内部，还是多个互相隔离的企业租户？ | 沿用现有内部治理定位，多租户独立评估。 | [TH-001](#th-001)、[TH-041](#th-041) |
| Q02 | 用户只通过 API 使用，还是需要自助申请 Key、看额度和聊天？ | 先完善现有管理员与 API 用户流程，自助台按需求选择。 | [TH-028](#th-028)、[TH-040](#th-040) |
| Q03 | 成本用于内部统计，还是需要充值、结算、退款和账单？ | 先保证内部计量可信，商业收费另立需求。 | [TH-023](#th-023)、[TH-024](#th-024)、[TH-042](#th-042) |
| Q04 | 首批必须交付哪些厂商、具体模型、地域和账号类型？ | 按实际账号确定 API 和模型 ID；预设名称不代表已验证。 | [TH-016](#th-016)、[TH-017](#th-017)、[TH-018](#th-018) |
| Q05 | 本期必须支持哪些模态和能力：文本、工具、JSON、视觉、embedding、音频、视频或实时？ | 优先高频能力；已展示的其他能力必须闭环或明确不可用。 | [TH-001](#th-001)、[TH-020](#th-020)、[TH-031](#th-031)、[TH-032](#th-032) |
| Q06 | 自动降级只允许同模型切渠道，还是可以切换模型？ | 建议只用已授权且能力等价候选，跨模型降级显式配置。 | [TH-002](#th-002)、[TH-003](#th-003)、[TH-021](#th-021) |
| Q07 | 额度是硬上限还是软提醒？没有可信价格或用量时如何处理？ | 明确预留及未知指标规则，事后记账不直接等于硬预算。 | [TH-008](#th-008)、[TH-023](#th-023)、[TH-024](#th-024) |
| Q08 | 哪些部门或数据可发往外部厂商和 OpenRouter，内容保留多久？ | 以允许渠道、数据处理和留存策略验收实际出站请求。 | [TH-003](#th-003)、[TH-006](#th-006)、[TH-029](#th-029)、[TH-035](#th-035) |
| Q09 | 目标峰值、并发长流、最长请求时间和最大上下文是多少？ | 取得实际负载后确定容量，不虚构 RPS、SLA 或资源规格。 | [TH-033](#th-033)、[TH-037](#th-037) |
| Q10 | 部署在哪里，谁负责运维，允许的数据丢失窗口和恢复时间是多少？ | 按环境确定 RPO/RTO 与恢复责任，再安排演练。 | [TH-034](#th-034)、[TH-037](#th-037)、[TH-038](#th-038) |

## 优先级与任务索引

- **P0：** 核心正确性或数据边界风险；先验证并收口，在对应能力对外开放时作为验收阻断项。条件性风险不等于已发生事故。
- **P1：** 主要使用和运维流程完善项，按依赖推进。
- **P2：** 可选扩展或产品范围决策，不作为当前内部治理定位的默认缺陷。

| 编号 | 优先级 | 类型 | 领域 | 任务 |
| --- | --- | --- | --- | --- |
| [TH-001](#th-001) | P1 | 范围定义 | 产品基线 | 确定平台边界与完成标准 |
| [TH-002](#th-002) | P0 | 缺陷修复 | 权限与路由 | 统一逻辑模型、渠道和 Key 授权 |
| [TH-003](#th-003) | P0 | 风险收口 | OpenRouter 治理 | 约束聚合平台的二次路由参数 |
| [TH-004](#th-004) | P0 | 权限修复 | 文件与任务 | 统一任务、媒体和下载的访问边界 |
| [TH-005](#th-005) | P0 | 安全加固 | 凭据管理 | 补齐头部凭据与生产密钥校验 |
| [TH-006](#th-006) | P0 | 风险收口 | 出站访问 | 统一厂商调用与媒体下载的出站策略 |
| [TH-007](#th-007) | P0 | 缺陷修复 | 请求生命周期 | 统一额度预留的释放和请求终态 |
| [TH-008](#th-008) | P0 | 正确性修复 | 预算与限额 | 建立可兑现的输出和费用预算 |
| [TH-009](#th-009) | P0 | 缺陷修复 | 流式网关 | 实现完整 SSE 解析与终态管理 |
| [TH-010](#th-010) | P0 | 正确性修复 | 计量 | 按协议归一真实用量 |
| [TH-011](#th-011) | P0 | 一致性修复 | 用量持久化 | 让明细、聚合、内容与事件可恢复 |
| [TH-012](#th-012) | P0 | 可靠性修复 | 队列与恢复 | 消除任务迁移丢失和重复执行窗口 |
| [TH-013](#th-013) | P1 | 兼容性修复 | 厂商适配 | 建立厂商配置模板与端点映射 |
| [TH-014](#th-014) | P1 | 数据模型完善 | 模型能力 | 把单一 capability 改为可组合能力合同 |
| [TH-015](#th-015) | P1 | 生命周期完善 | 模型目录 | 同步模型能力、变更和退役状态 |
| [TH-016](#th-016) | P1 | 能力完善 | OpenRouter 接入 | 形成 OpenRouter 的完整管理流程 |
| [TH-017](#th-017) | P1 | 接入验收 | 主流模型 | 完成首批模型平台的行为验收矩阵 |
| [TH-018](#th-018) | P2 | 可选扩展 | 模型覆盖 | 扩展第二批与私有模型 |
| [TH-019](#th-019) | P1 | 协议完善 | 外部 API | 明确协议兼容范围与模型发现合同 |
| [TH-020](#th-020) | P1 | 行为验收 | 工具与推理 | 验证工具调用、结构化输出和推理状态 |
| [TH-021](#th-021) | P1 | 可靠性完善 | 路由容灾 | 建设可观测的候选路由与故障切换 |
| [TH-022](#th-022) | P1 | 一致性修复 | 配置生命周期 | 让启停、撤权和策略更新及时生效 |
| [TH-023](#th-023) | P1 | 计价完善 | 成本管理 | 统一成本口径、缓存价格和币种 |
| [TH-024](#th-024) | P1 | 运营闭环 | 额度管理 | 完成额度查询、调整和对账流程 |
| [TH-025](#th-025) | P1 | 流程统一 | 后台试聊 | 让后台试聊验证正式调用链 |
| [TH-026](#th-026) | P1 | 回归验收 | 组织与权限 | 覆盖组织和身份的完整生命周期 |
| [TH-027](#th-027) | P1 | 配置完善 | 管理后台 | 统一服务端校验和配置发布流程 |
| [TH-028](#th-028) | P1 | 产品流程完善 | 管理员体验 | 完成接入和排障引导 |
| [TH-029](#th-029) | P0 | 安全与协议修复 | RAG 调用链 | 让 RAG 上下文经过同一协议与安全链 |
| [TH-030](#th-030) | P1 | 回归与运维完善 | RAG 生命周期 | 补齐知识导入到重建与删除的验收 |
| [TH-031](#th-031) | P1 | 能力闭环 | 多模态任务 | 消除只排队不执行的任务路径 |
| [TH-032](#th-032) | P1 | 协议完善 | 文件与音频 | 完成文件输入和音频输出合同 |
| [TH-033](#th-033) | P1 | 可观测完善 | 监控与诊断 | 让健康、耗时和故障原因反映真实情况 |
| [TH-034](#th-034) | P1 | 运维闭环 | 告警 | 完成发现、通知、处理和恢复流程 |
| [TH-035](#th-035) | P1 | 数据治理完善 | 留存与内容 | 统一流式内容留存、脱敏和删除 |
| [TH-036](#th-036) | P1 | 验收体系 | 测试与文档 | 用行为测试证明平台完成度 |
| [TH-037](#th-037) | P1 | 运行验收 | 容量与发布 | 验证长流、并发与管理面的可用性 |
| [TH-038](#th-038) | P1 | 恢复闭环 | 备份与灾难恢复 | 验证完整平台恢复能力 |
| [TH-039](#th-039) | P1 | 生命周期完善 | 凭据轮换 | 支持 Key 与主密钥的可恢复轮换 |
| [TH-040](#th-040) | P2 | 产品决策 | 用户入口 | 决定是否建设普通用户自助台 |
| [TH-041](#th-041) | P2 | 产品决策 | 组织隔离与身份 | 决定多租户与企业身份集成范围 |
| [TH-042](#th-042) | P2 | 产品决策 | 商业化 | 决定内部成本管理是否扩展为收费平台 |

## 任务详情

### TH-001

**确定平台边界与完成标准** · P1 · 范围定义 · 产品基线

**任务问题：** 本期哪些用户、接口、能力和部署场景必须形成闭环？

**当前情况：** 已有内部治理定位和模块清单，当前版本完成度需要独立验收。 依据：[E01](#e01)、[E25](#e25)。

**工作内容：**

- 建立需求、界面、API、权限、计量、运维和验收对应表。
- 区分核心交付、条件发布和暂不承诺能力。

**验收标准：**

- 每项对外能力有验收场景和证据位置。
- 预设存在、静态检查、行为测试、真实联调、生产验收分别记录。

**依赖：** 无。**状态：** 待实施；负责人待排期指定。

### TH-002

**统一逻辑模型、渠道和 Key 授权** · P0 · 缺陷修复 · 权限与路由

**任务问题：** 授权某个模型后，实际选择的厂商渠道是否始终属于授权范围？

**当前情况：** 按名称校验与全局按名称路由可能选择不同的模型记录。 依据：[E02](#e02)。

**工作内容：**

- 区分逻辑模型、上游模型 ID 和渠道，并在同一候选集合内授权与路由。
- 将用户、部门、Key、模型及渠道限制合并为可解释的有效权限。

**验收标准：**

- 同名模型分布在多个厂商时，未授权渠道始终不可选。
- 禁用、撤权、无候选和能力不符均返回明确错误，审计可追踪实际路由。

**依赖：** 无。**状态：** 待实施；负责人待排期指定。

### TH-003

**约束聚合平台的二次路由参数** · P0 · 风险收口 · OpenRouter 治理

**任务问题：** models、provider、plugins 或自动路由能否绕过平台的模型与数据策略？

**当前情况：** 顶层 model 校验后，其余载荷基本透传。 依据：[E03](#e03)。

**工作内容：**

- 定义扩展参数白名单、服务端强制策略和用户可选范围。
- 校验所有可落地模型与渠道，无法保证约束的自动路由先不开放。

**验收标准：**

- 未授权候选在发送前拒绝，不产生上游调用。
- 客户端不能放宽服务端渠道、数据处理或价格边界，并保留实际执行去向。

**依赖：** [TH-002](#th-002)。**状态：** 待实施；负责人待排期指定。

### TH-004

**统一任务、媒体和下载的访问边界** · P0 · 权限修复 · 文件与任务

**任务问题：** 同部门用户、不同 Key 和下载链接之间如何控制访问？

**当前情况：** 任务与媒体按部门放行，文件按 Key 过滤。 依据：[E17](#e17)。

**工作内容：**

- 明确私有、显式共享、管理访问及服务账号规则。
- 统一列表、详情、取消、下载、删除与令牌撤销的访问校验。

**验收标准：**

- 同部门不同用户、跨部门、不同 Key、撤权和过期链接均按选定矩阵执行。
- 删除或撤销后的文件与下载令牌行为一致，未授权操作不泄露对象信息。

**依赖：** 无。**状态：** 待实施；负责人待排期指定。

### TH-005

**补齐头部凭据与生产密钥校验** · P0 · 安全加固 · 凭据管理

**任务问题：** 头部中的认证信息是否被安全保存，生产配置缺主密钥时如何处理？

**当前情况：** 主 secret 有加密，default_headers 与 APP_KEY 回退存在缺口。 依据：[E16](#e16)。

**工作内容：**

- 将敏感头部与普通配置分离，提供加密保存、掩码展示和保留旧值语义。
- 生产环境拒绝缺失或开发默认主密钥，检查日志与配置返回内容。

**验收标准：**

- 列表、详情、导出和错误日志不暴露敏感头部，保存掩码不会覆盖真实值。
- 生产缺失有效密钥时明确启动失败，现有加密数据有迁移验证。

**依赖：** 无。**状态：** 待实施；负责人待排期指定。

### TH-006

**统一厂商调用与媒体下载的出站策略** · P0 · 风险收口 · 出站访问

**任务问题：** 厂商请求和远程资产下载是否遵守同一网络边界？

**当前情况：** Webhook 有校验，其他出站路径缺少等价约束。 依据：[E18](#e18)。

**工作内容：**

- 统一 URL、DNS、重定向、目标地址、响应大小和超时策略。
- 为私有推理服务建立显式允许规则，区分管理员配置与外部返回 URL。

**验收标准：**

- 隔离用例覆盖重定向至禁止目标、DNS 变化、大文件和超时。
- 已授权内网推理服务可用，禁止目标及越界下载在连接或读取阶段受控。

**依赖：** 无。**状态：** 待实施；负责人待排期指定。

### TH-007

**统一额度预留的释放和请求终态** · P0 · 缺陷修复 · 请求生命周期

**任务问题：** 所有拒绝、异常、断连与取消路径是否准确释放预留？

**当前情况：** 模型额度拒绝和直接 exit 可跳过完整清理。 依据：[E09](#e09)。

**工作内容：**

- 用明确的生命周期状态管理并发、token 和费用预留。
- 将深层 abort/exit 改为能由边界处理的结果或异常，并设计进程异常后的回收。

**验收标准：**

- 路由失败、模型限额、脱敏拒绝、RAG 异常、上游失败和断连均无残留预留。
- 重复终结或恢复不会重复释放、出现负计数或重复记账。

**依赖：** 无。**状态：** 待实施；负责人待排期指定。

### TH-008

**建立可兑现的输出和费用预算** · P0 · 正确性修复 · 预算与限额

**任务问题：** 输出和 RAG 上下文未纳入预留时，硬限额怎样成立？

**当前情况：** 估算未包含输出和费用，RAG 注入后未重算。 依据：[E10](#e10)。

**工作内容：**

- 按最终请求与声明的最大输出做预算，区分硬限额、软提醒及未知价格策略。
- 用原子预留、终态结算和差额释放管理并发预算。

**验收标准：**

- 并发请求、长上下文和最大输出不会突破约定的硬预算策略。
- 未知价格、未知用量及上游异常有明确处理结果，估算与实测分别展示。

**依赖：** [TH-007](#th-007)。**状态：** 待实施；负责人待排期指定。

### TH-009

**实现完整 SSE 解析与终态管理** · P0 · 缺陷修复 · 流式网关

**任务问题：** 任意网络分片、流内错误、缺失结束帧和断连能否正确处理？

**当前情况：** 逐 chunk 解析缺少缓冲与完整终态。 依据：[E07](#e07)。

**工作内容：**

- 建立按协议的增量事件解析器，处理跨片、注释、空行与终止事件。
- 区分完成、上游错误、超时、客户端取消及中途 EOF，并关联结算。

**验收标准：**

- 逐字节拆分、多个事件合片、跨行及中途 error 的夹具得到一致事件序列。
- 异常流不被记为成功；取消能力如受上游限制，记录真实结果和剩余成本风险。

**依赖：** [TH-007](#th-007)。**状态：** 待实施；负责人待排期指定。

### TH-010

**按协议归一真实用量** · P0 · 正确性修复 · 计量

**任务问题：** Chat、Responses、Claude 的输入、输出、缓存与推理用量是否可信？

**当前情况：** 字段与事件位置处理不完整，存在回退估算。 依据：[E08](#e08)。

**工作内容：**

- 按协议和厂商建立 usage 解析器并保存原始用量快照。
- 区分 measured、estimated、reconciled，明确累计值与缓存、推理的包含关系。

**验收标准：**

- 夹具覆盖 Chat、Responses、Anthropic 起始/增量事件与 OpenRouter 终帧。
- 累计值不重复相加；流式与非流式使用同一口径，估算不被标为实测。

**依赖：** [TH-009](#th-009)。**状态：** 待实施；负责人待排期指定。

### TH-011

**让明细、聚合、内容与事件可恢复** · P0 · 一致性修复 · 用量持久化

**任务问题：** 明细已写、聚合失败时能否补齐，积压跨天会不会改变历史账？

**当前情况：** 明细查重会跳过未完成后续工作，发生时间和定价缺少生产者快照。 依据：[E11](#e11)。

**工作内容：**

- 保存业务发生/结束时间、价格版本及上游引用。
- 用事务或可恢复阶段实现明细、聚合、内容和事件的幂等完成，提供重建对账。

**验收标准：**

- 在明细后、聚合中、事件写入前中断并重试，最终数据完整且不重复。
- 跨日消费或途中改价不会改变请求归属日期及已确定的价格依据。

**依赖：** [TH-010](#th-010)。**状态：** 待实施；负责人待排期指定。

### TH-012

**消除任务迁移丢失和重复执行窗口** · P0 · 可靠性修复 · 队列与恢复

**任务问题：** 进程退出、租约到期或重投时，事件能否可靠完成？

**当前情况：** 已有可靠队列基础，迁移原子性和长任务续租仍需补齐。 依据：[E13](#e13)。

**工作内容：**

- 统一 worker 入口与任务合同，原子迁移重试任务并支持租约续期。
- 建立持久化事件交接、失败队列重放与业务幂等键。

**验收标准：**

- 在 ack/push 边界杀进程、执行超过租约、重复投递后任务不丢失。
- 失败任务可诊断和重放，重复执行不导致重复计量或资产创建。

**依赖：** 无。**状态：** 待实施；负责人待排期指定。

### TH-013

**建立厂商配置模板与端点映射** · P1 · 兼容性修复 · 厂商适配

**任务问题：** 不同 base URL、鉴权、地域及账号类型怎样准确配置？

**当前情况：** 通用 URL 拼接不能覆盖 Gemini 等现有预设。 依据：[E05](#e05)。

**工作内容：**

- 建立厂商 profile、endpoint map、鉴权和地域/账号类型配置。
- 保留通用兼容配置，支持能力按真实协议声明。

**验收标准：**

- 对各预设声明支持的端点验证 URL，无重复版本段；不支持的端点明确拒绝。
- 普通 API、Coding 订阅和区域端点的适用范围及错误提示清楚。

**依赖：** 无。**状态：** 待实施；负责人待排期指定。

### TH-014

**把单一 capability 改为可组合能力合同** · P1 · 数据模型完善 · 模型能力

**任务问题：** 一个模型同时具备文本、视觉、工具和推理能力时如何描述？

**当前情况：** 单值 capability 与按名称猜测无法表达完整能力。 依据：[E04](#e04)。

**工作内容：**

- 分离输入模态、输出模态、协议、工具、JSON、推理、上下文和输出上限。
- 区分上游声明、管理员覆盖和实测结果，设计兼容迁移。

**验收标准：**

- 同一模型可同时表达多种能力，权限和路由按具体请求校验。
- 能力未知不默认视为支持，迁移后已有模型与授权可追溯。

**依赖：** [TH-013](#th-013)。**状态：** 待实施；负责人待排期指定。

### TH-015

**同步模型能力、变更和退役状态** · P1 · 生命周期完善 · 模型目录

**任务问题：** 目录刷新是否保留人工停用，并识别模型新增、变更和下线？

**当前情况：** 同步信息有限，已有模型导入会重新激活。 依据：[E04](#e04)、[E21](#e21)。

**工作内容：**

- 增量同步元数据，保留人工状态、别名和覆盖项。
- 加入变更预览、版本、退役标记与历史引用保护。

**验收标准：**

- 重新同步不会启用人工停用模型或覆盖人工策略。
- 新增、改价、能力变化和下线可审计；历史用量仍能解释。

**依赖：** [TH-014](#th-014)。**状态：** 待实施；负责人待排期指定。

### TH-016

**形成 OpenRouter 的完整管理流程** · P1 · 能力完善 · OpenRouter 接入

**任务问题：** 能否完整管理 OpenRouter 目录、路由策略、费用和故障？

**当前情况：** 地址预设已存在，缺少专门的治理与对账流程。 依据：[E03](#e03)、[E04](#e04)、[E06](#e06)、[E12](#e12)。

**工作内容：**

- 在公共管理框架中提供 OpenRouter 配置，保存模型 slug、能力和路由约束。
- 保存 generation ID、实际模型/供应商和 usage/cost，必要时查询 generation 补账。

**验收标准：**

- 不同模型家族通过普通、流式、工具调用、计量及策略拒绝用例。
- 余额、限流、上下文和上游故障可定位，同一 generation 补账不重复记账。

**依赖：** [TH-003](#th-003)、[TH-010](#th-010)、[TH-011](#th-011)、[TH-013](#th-013)、[TH-014](#th-014)。**状态：** 待实施；负责人待排期指定。

### TH-017

**完成首批模型平台的行为验收矩阵** · P1 · 接入验收 · 主流模型

**任务问题：** 哪些具体模型在选定地域和账户下真正通过了所需能力验收？

**当前情况：** 厂商预设和基础转发不足以证明完整适配。 依据：[E04](#e04)、[E05](#e05)、[E08](#e08)、[E25](#e25)。

**工作内容：**

- 以 OpenAI、Claude、Gemini、DeepSeek、Qwen、Kimi、GLM、豆包为首批候选，按实际需求确定具体 ID。
- 记录每个模型的端点、版本、能力、限额、错误及已知限制。

**验收标准：**

- 每个纳入交付的模型完成所需非流式、流式、工具、JSON、视觉或 embedding 场景。
- 无凭据或未执行项目标记待验证，不以品牌级成功替代模型级证据。

**依赖：** [TH-013](#th-013)、[TH-014](#th-014)、[TH-019](#th-019)、[TH-020](#th-020)。**状态：** 待实施；负责人待排期指定。

### TH-018

**扩展第二批与私有模型** · P2 · 可选扩展 · 模型覆盖

**任务问题：** 哪些额外厂商或私有推理服务具有实际使用需求？

**当前情况：** 通用适配基础可复用，新增范围需要明确。 依据：[E01](#e01)、[E04](#e04)、[E18](#e18)。

**工作内容：**

- 按需求评估 MiniMax、Grok、Mistral、托管 Llama、SiliconFlow、vLLM 和 Ollama。
- 将 Batch、Realtime、原生 Gemini 等独立协议按需要另列合同。

**验收标准：**

- 选定目标按同一能力和故障矩阵验收。
- 未选定平台和额外协议明确为范围外，不因品牌列表增长宣称兼容完成。

**依赖：** [TH-017](#th-017)。**状态：** 待实施；负责人待排期指定。

### TH-019

**明确协议兼容范围与模型发现合同** · P1 · 协议完善 · 外部 API

**任务问题：** 外部 SDK 能否按声明协议发现模型并正确调用？

**当前情况：** 带斜杠模型名、返回字段及 Responses 状态功能需逐项核验。 依据：[E06](#e06)、[E08](#e08)、[E15](#e15)。

**工作内容：**

- 定义 Chat、Responses、Messages 及模型发现的请求、响应与错误合同。
- 明确 Responses 会话续接、存储和检索等功能的支持范围，统一模型 slug 编码。

**验收标准：**

- 目标 SDK 验证模型列表/详情、普通和流式请求以及错误结构。
- 命名空间 ID 可用，不支持的参数或状态功能明确报错且文档一致。

**依赖：** [TH-013](#th-013)、[TH-014](#th-014)。**状态：** 待实施；负责人待排期指定。

### TH-020

**验证工具调用、结构化输出和推理状态** · P1 · 行为验收 · 工具与推理

**任务问题：** 多轮工具与推理流程能否完整续接，结构化输出是否符合合同？

**当前情况：** 基础透传不等于高级能力行为通过。 依据：[E04](#e04)、[E07](#e07)、[E08](#e08)、[E25](#e25)。

**工作内容：**

- 覆盖单/多工具、并行工具、分片参数、tool_choice 和严格 JSON。
- 按厂商处理推理字段、工具结果关联和历史状态，拒绝不支持的组合。

**验收标准：**

- 多轮工具调用 ID 和结果一一对应，拆分参数能重建完整 JSON。
- 目标模型的结构化输出、推理续接与失败场景均有行为证据。

**依赖：** [TH-014](#th-014)、[TH-019](#th-019)。**状态：** 待实施；负责人待排期指定。

### TH-021

**建设可观测的候选路由与故障切换** · P1 · 可靠性完善 · 路由容灾

**任务问题：** 一个渠道失败后，如何在授权和预算内安全切换？

**当前情况：** 当前全局 LIMIT 1 没有完整候选与故障切换机制。 依据：[E02](#e02)、[E03](#e03)、[E07](#e07)。

**工作内容：**

- 构建按权限、能力、健康和预算过滤的候选集合及熔断恢复。
- 明确重试上限、总超时、已输出后行为及跨模型降级规则。

**验收标准：**

- 429、可重试 5xx、连接失败与熔断恢复按规则执行，实际 attempt 可追踪。
- 已输出或存在副作用时不会盲目重试，切换不突破权限和费用约束。

**依赖：** [TH-002](#th-002)、[TH-003](#th-003)、[TH-009](#th-009)、[TH-014](#th-014)。**状态：** 待实施；负责人待排期指定。

### TH-022

**让启停、撤权和策略更新及时生效** · P1 · 一致性修复 · 配置生命周期

**任务问题：** 管理端保存后，哪些运行实例何时使用新配置？

**当前情况：** 路由与策略有 TTL 缓存，删除关系缺少退役流程。 依据：[E21](#e21)。

**工作内容：**

- 建立配置版本与跨实例失效机制，定义生效时间。
- 区分停用、退役与删除，保留历史请求和价格的解释能力。

**验收标准：**

- 多实例下禁用、撤权、改策略在约定时限内生效，并可观测。
- 模型退役不破坏历史用量、对账和失败任务处理。

**依赖：** [TH-002](#th-002)、[TH-015](#th-015)。**状态：** 待实施；负责人待排期指定。

### TH-023

**统一成本口径、缓存价格和币种** · P1 · 计价完善 · 成本管理

**任务问题：** 展示的成本来自估算、价目表还是实际账单，各币种如何合计？

**当前情况：** 已有价格快照，但缓存价格、实际成本及混币种口径需补齐。 依据：[E12](#e12)。

**工作内容：**

- 以定点数或十进制处理成本，区分目录估算、内部结算和上游实际成本。
- 定义缓存读写、推理、模态价格和币种换算及版本规则。

**验收标准：**

- 样本账单可逐项核对，精度与舍入规则固定，未知价格仍明确为空。
- 不同币种不直接相加；换算时保留原币、汇率来源和时间。

**依赖：** [TH-010](#th-010)、[TH-011](#th-011)。**状态：** 待实施；负责人待排期指定。

### TH-024

**完成额度查询、调整和对账流程** · P1 · 运营闭环 · 额度管理

**任务问题：** 管理员能否解释额度消耗、预留、恢复及剩余值？

**当前情况：** 额度与统计已存在，完整运营流程需要统一。 依据：[E09](#e09)、[E10](#e10)、[E11](#e11)、[E12](#e12)。

**工作内容：**

- 展示已用、预留、可用与估算差额，提供调整原因和审计。
- 明确统计周期、时区、重置和欠账处理，支持对账与修复。

**验收标准：**

- 用户、部门、Key、模型额度和请求明细可对应，差异有原因。
- 跨周期、人工调整和异常恢复有一致结果且不会覆盖历史审计。

**依赖：** [TH-007](#th-007)、[TH-008](#th-008)、[TH-011](#th-011)、[TH-023](#th-023)。**状态：** 待实施；负责人待排期指定。

### TH-025

**让后台试聊验证正式调用链** · P1 · 流程统一 · 后台试聊

**任务问题：** 后台测试结果能否代表真实 Key 的权限、计量与上下文行为？

**当前情况：** 后台聊天走独立直连路径，绕过公共治理处理。 依据：[E20](#e20)。

**工作内容：**

- 通过可审计身份进入统一调用服务，显式指定测试使用的授权与额度。
- 支持已声明的协议和能力，展示路由、用量、错误及 RAG 来源。

**验收标准：**

- 后台与外部调用在相同策略下得到一致授权、脱敏、RAG 和记账结果。
- 管理员的诊断权限有明确边界，不因测试路径静默绕过用户规则。

**依赖：** [TH-007](#th-007)、[TH-010](#th-010)、[TH-014](#th-014)、[TH-019](#th-019)、[TH-029](#th-029)。**状态：** 待实施；负责人待排期指定。

### TH-026

**覆盖组织和身份的完整生命周期** · P1 · 回归验收 · 组织与权限

**任务问题：** 用户调部门、禁用、角色变更和 Key 撤销后，旧权限是否及时消失？

**当前情况：** 已有组织与认证能力，需验证跨模块一致性。 依据：[E01](#e01)、[E02](#e02)、[E17](#e17)、[E21](#e21)。

**工作内容：**

- 定义角色及身份操作矩阵，覆盖登录、变更、禁用、撤销与审计。
- 验证同步接口、后台、外部 API 与任务资产在生命周期变化后的行为。

**验收标准：**

- 调部门、角色降权、Key 撤销和用户停用的正反向用例通过。
- 旧会话与缓存遵守撤权合同，管理操作与业务调用均可归因。

**依赖：** [TH-002](#th-002)、[TH-004](#th-004)、[TH-022](#th-022)。**状态：** 待实施；负责人待排期指定。

### TH-027

**统一服务端校验和配置发布流程** · P1 · 配置完善 · 管理后台

**任务问题：** 错误配置、并发编辑和回滚能否在后台可靠处理？

**当前情况：** 通用配置 CRUD 与缓存、路由、退役关系需要整体校验。 依据：[E05](#e05)、[E21](#e21)。

**工作内容：**

- 建立服务端语义校验、依赖检查、连接诊断与敏感字段规则。
- 提供版本、并发冲突检测、变更预览和可审计回滚。

**验收标准：**

- 无效端点、冲突别名、不兼容能力和失效引用被明确阻止或提示。
- 并发保存不会静默覆盖，回滚可恢复有效配置且不撤销历史事实。

**依赖：** [TH-013](#th-013)、[TH-022](#th-022)。**状态：** 待实施；负责人待排期指定。

### TH-028

**完成接入和排障引导** · P1 · 产品流程完善 · 管理员体验

**任务问题：** 管理员能否从零配置厂商，到让用户成功调用并定位失败？

**当前情况：** 已有多种管理资源，但整条用户旅程需要实测。 依据：[E01](#e01)、[E20](#e20)、[E22](#e22)、[E25](#e25)。

**工作内容：**

- 串联添加厂商、同步模型、分配权限、设额度、发 Key、调用验证和监控。
- 提供明确空态、错误解释、配置状态与诊断入口。

**验收标准：**

- 新管理员按文档完成选定厂商与 OpenRouter 的开通流程，无隐式数据库操作。
- 对无权限、余额不足、配置错误和限流可从请求 ID 找到原因与处理办法。

**依赖：** [TH-015](#th-015)、[TH-016](#th-016)、[TH-024](#th-024)、[TH-025](#th-025)、[TH-027](#th-027)。**状态：** 待实施；负责人待排期指定。

### TH-029

**让 RAG 上下文经过同一协议与安全链** · P0 · 安全与协议修复 · RAG 调用链

**任务问题：** 注入知识后的最终请求是否仍符合脱敏、权限、协议和预算？

**当前情况：** 注入发生在出站脱敏之后，并使用通用 system 消息。 依据：[E19](#e19)、[E10](#e10)。

**工作内容：**

- 按实际协议构建上下文，在最终 payload 上执行过滤与预算。
- 限制知识来源、引用和上下文长度，记录注入与截断策略。

**验收标准：**

- 包含敏感信息的检索片段遵守出站策略，不会通过注入绕过过滤。
- 选定 Chat、Responses、Messages 场景的上下文格式有效，超预算有明确处理。

**依赖：** [TH-007](#th-007)、[TH-008](#th-008)。**状态：** 待实施；负责人待排期指定。

### TH-030

**补齐知识导入到重建与删除的验收** · P1 · 回归与运维完善 · RAG 生命周期

**任务问题：** 知识导入、更新、删除、重建和失败恢复能否保持一致？

**当前情况：** 已有向量与评测相关服务，需要贯通生命周期。 依据：[E19](#e19)、[E24](#e24)、[E27](#e27)。

**工作内容：**

- 覆盖上传、解析、分块、embedding、索引、检索、更新和删除。
- 验证模型变更重建、评测门禁、权限过滤及失败任务恢复。

**验收标准：**

- 文档更新与删除后，数据库、向量库、文件和检索结果在合同时间内一致。
- 重建失败可恢复，权限隔离与检索质量有固定数据集验证。

**依赖：** [TH-012](#th-012)、[TH-026](#th-026)、[TH-029](#th-029)。**状态：** 待实施；负责人待排期指定。

### TH-031

**消除只排队不执行的任务路径** · P1 · 能力闭环 · 多模态任务

**任务问题：** 返回 queued/202 后，谁提交上游、刷新状态、结算和交付文件？

**当前情况：** native 分支未形成执行闭环，Ark 有独立路径。 依据：[E14](#e14)。

**工作内容：**

- 统一任务状态机，补齐提交、轮询或回调、超时、取消、重试与交付。
- 未接执行器的能力明确为不可用。

**验收标准：**

- 启用能力的任务最终进入成功、失败或取消终态，重投不重复生成或结算。
- 没有执行器时提交前拒绝，不能长期排队并把调用标为成功。

**依赖：** [TH-012](#th-012)、[TH-014](#th-014)、[TH-023](#th-023)。**状态：** 待实施；负责人待排期指定。

### TH-032

**完成文件输入和音频输出合同** · P1 · 协议完善 · 文件与音频

**任务问题：** 文件上传和语音接口是原生 SDK 兼容，还是明确的自定义格式？

**当前情况：** JSON-only 与 base64/JSON TTS 输出存在兼容边界。 依据：[E15](#e15)、[E17](#e17)、[E18](#e18)。

**工作内容：**

- 按选定范围实现 multipart、文件引用、MIME 与大小校验，或明确不支持。
- 为原生音频合同提供正确二进制输出与流式行为，并区分自定义接口。

**验收标准：**

- 目标 SDK 的上传、转写和语音生成按声明范围成功，文件权限有效。
- Content-Type、长度、错误结构和取消行为正确，文档与运行一致。

**依赖：** [TH-004](#th-004)、[TH-006](#th-006)、[TH-019](#th-019)、[TH-031](#th-031)。**状态：** 待实施；负责人待排期指定。

### TH-033

**让健康、耗时和故障原因反映真实情况** · P1 · 可观测完善 · 监控与诊断

**任务问题：** 管理员看到的健康与 p95/p99 是否足以定位实际故障？

**当前情况：** 启用状态被当作健康，队列异常可能显示零，长请求耗时分桶过粗。 依据：[E22](#e22)。

**工作内容：**

- 区分配置就绪、依赖健康、上游探测和真实请求成功率。
- 关联请求、各次尝试、首 token 时间、总延迟、队列积压、错误和计量状态。

**验收标准：**

- 监控依赖失联显示未知或故障，不伪装为零积压或健康。
- 长短请求指标与样本一致，可从请求 ID 定位实际渠道、失败阶段与记录状态。

**依赖：** [TH-009](#th-009)、[TH-010](#th-010)、[TH-011](#th-011)、[TH-021](#th-021)。**状态：** 待实施；负责人待排期指定。

### TH-034

**完成发现、通知、处理和恢复流程** · P1 · 运维闭环 · 告警

**任务问题：** 告警产生后，谁收到、如何去重、何时恢复？

**当前情况：** 已有评估入库，处置与通知流程需补齐或对接现有系统。 依据：[E23](#e23)。

**工作内容：**

- 定义聚合键、抑制、去重、认领、静默、升级及恢复状态。
- 按用户选择对接通知渠道并提供发送失败重试和审计。

**验收标准：**

- 持续同一故障不重复轰炸，恢复事件与原告警关联。
- 演练覆盖触发、送达、处理、恢复和通知失败；外部系统覆盖有证据。

**依赖：** [TH-033](#th-033)。**状态：** 待实施；负责人待排期指定。

### TH-035

**统一流式内容留存、脱敏和删除** · P1 · 数据治理完善 · 留存与内容

**任务问题：** 用户配置的内容留存是否同时覆盖流式、队列、日志和备份？

**当前情况：** 流式 output 仅记录标记与 usage，完整内容留存需要定义。 依据：[E16](#e16)、[E17](#e17)、[E27](#e27)。

**工作内容：**

- 明确按租户或当前组织策略的留存范围、内容大小上限和访问审计。
- 统一普通与流式内容处理，以及日志、队列、导出和备份的删除边界。

**验收标准：**

- 留存开关和脱敏策略在普通/流式请求中一致，禁留存场景不落敏感内容。
- 到期删除、人工删除与备份保留有清楚的可验证合同。

**依赖：** [TH-004](#th-004)、[TH-009](#th-009)、[TH-011](#th-011)。**状态：** 待实施；负责人待排期指定。

### TH-036

**用行为测试证明平台完成度** · P1 · 验收体系 · 测试与文档

**任务问题：** 怎样证明平台流程可用，而不仅是源码包含某个字段？

**当前情况：** 现有测试文件较多，部分是静态断言，本轮未运行。 依据：[E25](#e25)。

**工作内容：**

- 保留有价值静态检查，补关键协议夹具、故障注入、SDK 和完整流程测试。
- 核实现有 CI，建立可重复运行入口，维护 OpenAPI、部署文档和证据矩阵。

**验收标准：**

- 授权、流式、预留释放、幂等、跨协议和恢复用例能发现对应实际错误。
- 报告记录 commit、环境、模型、执行时间、通过/失败/未测；待测项不会标为通过。

**依赖：** [TH-001](#th-001)。**状态：** 待实施；负责人待排期指定。

### TH-037

**验证长流、并发与管理面的可用性** · P1 · 运行验收 · 容量与发布

**任务问题：** 大量长流下，管理操作、记账和任务是否仍能正常工作？

**当前情况：** 已有部署资产，资源竞争和发布可重复性尚需验证。 依据：[E26](#e26)。

**工作内容：**

- 按约定负载压测连接数、排队、内存、首 token 延迟和 worker 吞吐。
- 验证管理面资源隔离、限流、滚动更新、回滚与镜像版本固定。

**验收标准：**

- 在用户约定的峰值与持续负载下达到目标，并记录容量边界。
- 更新和故障期间请求终态与记账可追踪，回滚可执行且不会损坏数据。

**依赖：** [TH-009](#th-009)、[TH-012](#th-012)、[TH-033](#th-033)、[TH-036](#th-036)。**状态：** 待实施；负责人待排期指定。

### TH-038

**验证完整平台恢复能力** · P1 · 恢复闭环 · 备份与灾难恢复

**任务问题：** 恢复后能否实际登录、调用、检索、下载并继续记账？

**当前情况：** 现有脚本未显式覆盖所有 RAG 与向量数据。 依据：[E24](#e24)。

**工作内容：**

- 盘点数据库、向量库、资产、配置、密钥及队列的恢复关系。
- 设计一致性备份与重建方案，在隔离环境演练 RPO/RTO。

**验收标准：**

- 恢复后完成登录、模型调用、RAG、资产下载和对账用例。
- 损坏或缺失备份能被发现，记录实际恢复时间和数据丢失窗口。

**依赖：** [TH-012](#th-012)、[TH-030](#th-030)、[TH-035](#th-035)。**状态：** 待实施；负责人待排期指定。

### TH-039

**支持 Key 与主密钥的可恢复轮换** · P1 · 生命周期完善 · 凭据轮换

**任务问题：** 厂商凭据、业务 Key 和 APP_KEY 如何轮换及回退？

**当前情况：** 已有加密存储，缺少密钥版本与完整轮换合同。 依据：[E16](#e16)、[E21](#e21)。

**工作内容：**

- 为加密数据引入版本，设计逐步重加密与恢复流程。
- 定义新旧 Key 过渡、撤销生效、缓存失效与审计。

**验收标准：**

- 中途失败可继续或回退，既有凭据不会永久不可读。
- 旧 Key 在约定时间失效，日志不暴露密钥，恢复方案经过隔离验证。

**依赖：** [TH-005](#th-005)、[TH-022](#th-022)。**状态：** 待实施；负责人待排期指定。

### TH-040

**决定是否建设普通用户自助台** · P2 · 产品决策 · 用户入口

**任务问题：** API 使用者是否需要自行申请 Key、查额度和看调用记录？

**当前情况：** 现有定位未要求普通用户 Web 入口，不直接算缺陷。 依据：[E01](#e01)。

**工作内容：**

- 比较 API-only、最小自助台与完整工作台的需求和维护成本。
- 若选定，限定申请、查看、撤销和申诉权限。

**验收标准：**

- 产出明确范围决定及用户旅程。
- 若实施，普通用户只能访问被授权数据，申请与审批过程可追踪。

**依赖：** [TH-001](#th-001)、[TH-026](#th-026)。**状态：** 待实施；负责人待排期指定。

### TH-041

**决定多租户与企业身份集成范围** · P2 · 产品决策 · 组织隔离与身份

**任务问题：** 是否需要多企业隔离，以及独立的 SSO/MFA 企业身份能力？

**当前情况：** 内部组织管理不自动等于 SaaS 多租户，三项需求应分别决策。 依据：[E01](#e01)、[E17](#e17)。

**工作内容：**

- 分别判断多租户、SSO、MFA 的场景与优先级。
- 纳入范围后定义租户数据边界和身份生命周期，评估迁移。

**验收标准：**

- 每个选项都有明确决定，未选择不影响当前范围完成定义。
- 若实施，多租户和身份回归覆盖缓存、队列、文件、RAG、统计与管理员访问。

**依赖：** [TH-001](#th-001)、[TH-026](#th-026)。**状态：** 待实施；负责人待排期指定。

### TH-042

**决定内部成本管理是否扩展为收费平台** · P2 · 产品决策 · 商业化

**任务问题：** 是否真的需要充值、结算、退款与账单？

**当前情况：** 原需求为可选内部成本，商业收费属于新增产品范围。 依据：[E01](#e01)、[E11](#e11)、[E12](#e12)。

**工作内容：**

- 明确内部预算、成本分摊和对外收费之间的边界。
- 若选择收费，独立设计账本、支付状态、退款、争议与对账规则。

**验收标准：**

- 形成明确范围决定，不用支付功能缺失否定内部治理版本。
- 若实施，资金事件与业务用量分别可审计，重复通知和失败恢复不会重复扣款。

**依赖：** [TH-001](#th-001)、[TH-011](#th-011)、[TH-023](#th-023)。**状态：** 待实施；负责人待排期指定。

## 建议实施顺序

分批可以交叉准备；实际执行应遵循每项任务的依赖关系。M0 先建立验收口径，M1 收口核心风险，再扩展完整接入与运营流程。此处未估算工期。

| 批次 | 目标 | 任务 | 阶段完成条件 |
| --- | --- | --- | --- |
| M0 | 明确完成标准并准备行为验收框架。 | [TH-001](#th-001)、[TH-036](#th-036) | 范围与证据矩阵完成，现有能力按真实状态标记。 |
| M1 | 收口授权、安全、流式、额度和记账恢复。 | [TH-002](#th-002)、[TH-003](#th-003)、[TH-004](#th-004)、[TH-005](#th-005)、[TH-006](#th-006)、[TH-007](#th-007)、[TH-008](#th-008)、[TH-009](#th-009)、[TH-010](#th-010)、[TH-011](#th-011)、[TH-012](#th-012)、[TH-029](#th-029) | 对应边界与故障用例通过，额度、明细、聚合和路由可互相核对。 |
| M2 | 完成模型目录、主流厂商及 OpenRouter 治理。 | [TH-013](#th-013)、[TH-014](#th-014)、[TH-015](#th-015)、[TH-016](#th-016)、[TH-017](#th-017)、[TH-019](#th-019)、[TH-020](#th-020)、[TH-021](#th-021)、[TH-022](#th-022)、[TH-023](#th-023)、[TH-024](#th-024)、[TH-039](#th-039) | 选定模型逐项通过所需能力矩阵，保留真实联调证据。 |
| M3 | 完成管理员、RAG、任务资产和日常运维流程。 | [TH-025](#th-025)、[TH-026](#th-026)、[TH-027](#th-027)、[TH-028](#th-028)、[TH-030](#th-030)、[TH-031](#th-031)、[TH-032](#th-032)、[TH-033](#th-033)、[TH-034](#th-034)、[TH-035](#th-035) | 开通到撤权、失败处理、删除与告警恢复均可重复验证。 |
| M4 | 完成目标环境容量、发布和恢复验收。 | [TH-037](#th-037)、[TH-038](#th-038) | 在约定负载与 RPO/RTO 下取得证据，限制写入发布说明。 |
| M5 | 按产品决策扩展。 | [TH-018](#th-018)、[TH-040](#th-040)、[TH-041](#th-041)、[TH-042](#th-042) | 场景与范围明确后排期，未选内容不算当前版本缺陷。 |

发布验收应至少演练：开通厂商与模型、分配权限和额度、成功调用与工具多轮、异常与断连、重试后对账、撤权与删除、告警处理、备份恢复。每一步保留请求或任务 ID，并能追到实际执行渠道、权限依据和计量结果。

## 证据与核查边界

- 仅核查源码与公开官方文档，未运行 PHP 测试、真实 SDK 调用、付费模型请求、数据库迁移或生产验收。
- 当前 shell 未发现 PHP；未安装运行时、启动服务或读取真实密钥。
- 历史开发总结不等于当前版本通过验收；proposed 不代表已实施。

源码行号对应上面的基线 commit，后续改动可能使行号移动。证据级别中的“待验证”保持未测状态；风险影响由实际配置、启用能力和运行环境决定。

### E01

现有定位是企业内部模型服务治理：已有用户/部门、Key、限额、成本、内容留存、RAG、运维与同步接口。原需求将价格定义为可选内部成本；后续文档明确未新增普通用户 Web 入口。

**证据级别：** 源码与需求已确认。

**源码：** [docs/tokenhub-development-guide.md:7](/Users/leo/mysoft/TokenHubcode/docs/tokenhub-development-guide.md:7)；[docs/development-tasks/00-task-index.md:34](/Users/leo/mysoft/TokenHubcode/docs/development-tasks/00-task-index.md:34)；[docs/development-tasks/14-external-api-p0-p1-hardening-summary.md:7](/Users/leo/mysoft/TokenHubcode/docs/development-tasks/14-external-api-p0-p1-hardening-summary.md:7)；[app/Services/AuthService.php:14](/Users/leo/mysoft/TokenHubcode/app/Services/AuthService.php:14)。

### E02

Key 权限按模型名称校验，路由按名称/单一能力/协议全局查询 LIMIT 1；同名模型可以属于多个厂商，路由查询未绑定 Key 的 model_id 授权集合，也没有候选路由失败切换。

**证据级别：** 源码已确认；同名跨渠道影响待验证。

**源码：** [app/Services/ApiKeyService.php:401](/Users/leo/mysoft/TokenHubcode/app/Services/ApiKeyService.php:401)；[app/Services/Gateway/ModelRouter.php:53](/Users/leo/mysoft/TokenHubcode/app/Services/Gateway/ModelRouter.php:53)；[database/migrations/0001_create_tokenhub_schema.sql:87](/Users/leo/mysoft/TokenHubcode/database/migrations/0001_create_tokenhub_schema.sql:87)。

### E03

已有 OpenRouter URL 预设。OpenAIAdapter 基本原样传递请求体，只改 model；网关检查顶层 model，未对 models/provider 等扩展建立单独的授权、预算与隐私策略合并层。

**证据级别：** 源码已确认；上游行为待联调。

**源码：** [public/admin/assets/js/table.js:255](/Users/leo/mysoft/TokenHubcode/public/admin/assets/js/table.js:255)；[app/Services/Providers/OpenAIAdapter.php:16](/Users/leo/mysoft/TokenHubcode/app/Services/Providers/OpenAIAdapter.php:16)；[app/Middleware/GatewayAuthMiddleware.php:15](/Users/leo/mysoft/TokenHubcode/app/Middleware/GatewayAuthMiddleware.php:15)。

**接口依据：** [OpenRouter：API overview](https://openrouter.ai/docs/api_reference/overview)；[OpenRouter：Provider routing](https://openrouter.ai/docs/guides/routing/provider-selection)。

### E04

models.capability 是单值 ENUM，路由要求完全相等；同步靠名称猜能力，只保留 name、external_name、capability、context_length 四项模型信息，未保留完整能力合同。导入已有模型会把 status 写回 active。

**证据级别：** 源码已确认。

**源码：** [database/migrations/0001_create_tokenhub_schema.sql:87](/Users/leo/mysoft/TokenHubcode/database/migrations/0001_create_tokenhub_schema.sql:87)；[app/Services/ProviderModelSyncService.php:57](/Users/leo/mysoft/TokenHubcode/app/Services/ProviderModelSyncService.php:57)；[app/Services/ProviderModelSyncService.php:99](/Users/leo/mysoft/TokenHubcode/app/Services/ProviderModelSyncService.php:99)；[app/Services/ProviderModelSyncService.php:163](/Users/leo/mysoft/TokenHubcode/app/Services/ProviderModelSyncService.php:163)。

**接口依据：** [OpenRouter：Models](https://openrouter.ai/docs/api/api-reference/models/list-all-models-and-their-properties)。

### E05

URL 拼接仅特殊处理以 /v1 或 /api/v3 结尾的 base_url；Gemini 预设会拼成 /v1beta/openai/v1/chat/completions，与官方 /v1beta/openai/chat/completions 不符。智谱 /api/paas/v4 等其他路径也需按厂商合同核验。

**证据级别：** 源码与 Gemini 官方接口已确认；未在线调用。

**源码：** [app/Services/ProviderHttpClient.php:310](/Users/leo/mysoft/TokenHubcode/app/Services/ProviderHttpClient.php:310)；[public/admin/assets/js/table.js:246](/Users/leo/mysoft/TokenHubcode/public/admin/assets/js/table.js:246)。

**接口依据：** [Google：Gemini OpenAI compatibility](https://ai.google.dev/gemini-api/docs/openai)。

### E06

GET /v1/models/{model} 的参数正则只匹配一个路径段，控制器未做参数解码；包含斜杠的 OpenRouter slug 需要明确编码合同。模型列表主要返回 data[].id。

**证据级别：** 源码已确认；客户端编码行为待测。

**源码：** [app/Support/Router.php:88](/Users/leo/mysoft/TokenHubcode/app/Support/Router.php:88)；[app/Http/Controllers/ExternalController.php:29](/Users/leo/mysoft/TokenHubcode/app/Http/Controllers/ExternalController.php:29)；[app/Services/ExternalModelService.php:15](/Users/leo/mysoft/TokenHubcode/app/Services/ExternalModelService.php:15)。

### E07

两个基础适配器按每个网络 chunk 的换行解析 SSE，没有跨 chunk 缓冲。HTTP 200 流内 error、缺失终止事件、客户端断连没有完整终态管理。原始字节仍透传，主要风险在计量、状态和后续转换。

**证据级别：** 源码已确认；需分片及断连故障注入。

**源码：** [app/Services/Providers/OpenAIAdapter.php:63](/Users/leo/mysoft/TokenHubcode/app/Services/Providers/OpenAIAdapter.php:63)；[app/Services/Providers/AnthropicAdapter.php:62](/Users/leo/mysoft/TokenHubcode/app/Services/Providers/AnthropicAdapter.php:62)；[app/Services/ProviderHttpClient.php:243](/Users/leo/mysoft/TokenHubcode/app/Services/ProviderHttpClient.php:243)；[app/Services/GatewayService.php:363](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:363)。

**接口依据：** [OpenRouter：Streaming](https://openrouter.ai/docs/api_reference/streaming)；[Anthropic：Streaming Messages](https://platform.claude.com/docs/en/build-with-claude/streaming)。

### E08

OpenAI usage 输入读取 prompt_tokens，未读取 Responses 的 input_tokens；流中读取顶层 usage，未覆盖 Responses 嵌套终态用量。Anthropic 流未完整合并 message_start.message.usage 和累计 message_delta.usage；缓存和推理用量缺少统一表示。

**证据级别：** 源码已确认。

**源码：** [app/Services/Providers/OpenAIAdapter.php:42](/Users/leo/mysoft/TokenHubcode/app/Services/Providers/OpenAIAdapter.php:42)；[app/Services/Providers/AnthropicAdapter.php:43](/Users/leo/mysoft/TokenHubcode/app/Services/Providers/AnthropicAdapter.php:43)。

**接口依据：** [OpenAI：Responses 迁移指南](https://developers.openai.com/api/docs/guides/migrate-to-responses)；[Anthropic：Streaming Messages](https://platform.claude.com/docs/en/build-with-claude/streaming)。

### E09

通用额度预留后，模型额度拒绝只释放并发，没有退还通用 token 预留；脱敏/RAG 可通过 abort_json 直接 exit，主链路没有统一终结器。exit 不能仅靠新增 try/finally 解决。

**证据级别：** 源码已确认。

**源码：** [app/Services/GatewayService.php:41](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:41)；[app/Services/GatewayService.php:93](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:93)；[app/Support/helpers.php:46](/Users/leo/mysoft/TokenHubcode/app/Support/helpers.php:46)；[app/Services/DesensitizationService.php:51](/Users/leo/mysoft/TokenHubcode/app/Services/DesensitizationService.php:51)。

### E10

请求估算未预留输出 token 或费用；RAG 在额度预检后注入，估算未随 payload 重算。现有低估预留、事后补差机制不能证明硬预算成立。

**证据级别：** 源码已确认。

**源码：** [app/Services/Gateway/OpenAIRequestParser.php:47](/Users/leo/mysoft/TokenHubcode/app/Services/Gateway/OpenAIRequestParser.php:47)；[app/Services/QuotaService.php:28](/Users/leo/mysoft/TokenHubcode/app/Services/QuotaService.php:28)；[app/Services/GatewayService.php:93](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:93)；[app/Services/GatewayService.php:274](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:274)。

### E11

UsageService 先查重、插明细，再写内容、聚合和事件，没有形成可恢复处理单元；插明细后异常，重试会直接返回。生产者未记录业务发生时间，消费者用当前时间定价、分桶并重算成本。

**证据级别：** 源码已确认；故障后的差异待实测。

**源码：** [app/Services/UsageService.php:14](/Users/leo/mysoft/TokenHubcode/app/Services/UsageService.php:14)；[app/Services/UsageService.php:88](/Users/leo/mysoft/TokenHubcode/app/Services/UsageService.php:88)；[app/Services/Gateway/GatewayUsageRecorder.php:32](/Users/leo/mysoft/TokenHubcode/app/Services/Gateway/GatewayUsageRecorder.php:32)。

### E12

已有多种模态和按次价格及成本快照，但未使用缓存读写差异价格与上游实际成本，使用浮点计算；聚合 total_cost 未按币种分组。未知价格已经支持为空，应保留这一明确语义。

**证据级别：** 源码已确认；混币种风险有条件成立。

**源码：** [app/Services/PriceService.php:43](/Users/leo/mysoft/TokenHubcode/app/Services/PriceService.php:43)；[app/Services/UsageService.php:98](/Users/leo/mysoft/TokenHubcode/app/Services/UsageService.php:98)；[database/migrations/0001_create_tokenhub_schema.sql:103](/Users/leo/mysoft/TokenHubcode/database/migrations/0001_create_tokenhub_schema.sql:103)。

**接口依据：** [OpenRouter：Generation metadata](https://openrouter.ai/docs/api/api-reference/generations/get-request-&-usage-metadata-for-a-generation)。

### E13

已有队列 reserve/ack、重试和失败队列，但 release/requeue 先 ack 再 push，缺少原子迁移。通用租约默认 30 秒，缺少通用续租；历史 worker 入口行为不一致。

**证据级别：** 源码已确认；进程故障窗口待测。

**源码：** [app/Support/RedisClient.php:300](/Users/leo/mysoft/TokenHubcode/app/Support/RedisClient.php:300)；[app/Console/QueueWorker.php:22](/Users/leo/mysoft/TokenHubcode/app/Console/QueueWorker.php:22)；[config/queue.php:8](/Users/leo/mysoft/TokenHubcode/config/queue.php:8)；[scripts/worker.php:26](/Users/leo/mysoft/TokenHubcode/scripts/worker.php:26)。

### E14

native 多模态分支只插 queued 任务并返回 202，创建方法没有投递实际执行工作，通用 Worker 也没有对应执行类型。Ark 已有独立提交/刷新路径，不能推断所有多模态均未实现。

**证据级别：** 源码已确认；影响取决于启用路由。

**源码：** [app/Services/GatewayService.php:77](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:77)；[app/Services/MultimodalTaskService.php:24](/Users/leo/mysoft/TokenHubcode/app/Services/MultimodalTaskService.php:24)；[app/Services/WorkerService.php:19](/Users/leo/mysoft/TokenHubcode/app/Services/WorkerService.php:19)；[app/Services/GatewayService.php:177](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:177)。

### E15

网关解析 JSON，能力声明为 multipart=false。TTS 二进制响应被包装为 base64/JSON；同名端点不能证明兼容原生音频 SDK。

**证据级别：** 源码已确认。

**源码：** [app/Services/Gateway/OpenAIRequestParser.php:13](/Users/leo/mysoft/TokenHubcode/app/Services/Gateway/OpenAIRequestParser.php:13)；[app/Services/GatewayService.php:136](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:136)；[app/Services/ExternalIdentityService.php:96](/Users/leo/mysoft/TokenHubcode/app/Services/ExternalIdentityService.php:96)。

### E16

provider.secret 有加密，但 default_headers 是普通 JSON，返回清理只删除 encrypted_secret；若认证凭据放入头部，会随普通配置读写。Crypto 对缺失 APP_KEY 使用开发值回退，且没有密钥版本。未读取实际凭据配置。

**证据级别：** 源码已确认；是否存在真实明文头部凭据未知。

**源码：** [app/Services/CrudService.php:300](/Users/leo/mysoft/TokenHubcode/app/Services/CrudService.php:300)；[app/Services/SensitiveDataService.php:15](/Users/leo/mysoft/TokenHubcode/app/Services/SensitiveDataService.php:15)；[app/Support/Crypto.php:42](/Users/leo/mysoft/TokenHubcode/app/Support/Crypto.php:42)。

### E17

任务和媒体的 canAccess 对相同 department_id 直接放行，文件专用接口却按 api_key_id 过滤。私有数据、显式部门共享以及下载令牌撤销/删除后的行为需统一定义。

**证据级别：** 源码已确认；共享规则待明确。

**源码：** [app/Services/MediaAssetService.php:166](/Users/leo/mysoft/TokenHubcode/app/Services/MediaAssetService.php:166)；[app/Services/MultimodalTaskService.php:116](/Users/leo/mysoft/TokenHubcode/app/Services/MultimodalTaskService.php:116)；[app/Services/ExternalFileService.php:143](/Users/leo/mysoft/TokenHubcode/app/Services/ExternalFileService.php:143)；[app/Http/Controllers/DownloadController.php:18](/Users/leo/mysoft/TokenHubcode/app/Http/Controllers/DownloadController.php:18)。

### E18

Webhook 有目标 URL 校验服务，厂商请求和媒体下载未共用同等出站策略；远程下载直接使用 file_get_contents。应覆盖重定向、DNS、地址范围、读取大小和超时，并允许显式授权的私有推理服务。

**证据级别：** 源码差异已确认；利用条件待隔离验证。

**源码：** [app/Services/WebhookTargetValidator.php:21](/Users/leo/mysoft/TokenHubcode/app/Services/WebhookTargetValidator.php:21)；[app/Services/ProviderHttpClient.php:72](/Users/leo/mysoft/TokenHubcode/app/Services/ProviderHttpClient.php:72)；[app/Http/Controllers/DownloadController.php:106](/Users/leo/mysoft/TokenHubcode/app/Http/Controllers/DownloadController.php:106)。

### E19

网关先出站脱敏再注入 RAG，新增上下文未再次经过同一过滤；RagPromptInjector 在 messages 中注入 system role，未按协议处理不同 system 字段，也未在此实施 token 预算。

**证据级别：** 源码已确认；跨协议行为待联调。

**源码：** [app/Services/GatewayService.php:93](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:93)；[app/Services/RagRetrievalService.php:79](/Users/leo/mysoft/TokenHubcode/app/Services/RagRetrievalService.php:79)；[app/Services/RagPromptInjector.php:44](/Users/leo/mysoft/TokenHubcode/app/Services/RagPromptInjector.php:44)。

### E20

后台聊天独立调用 ProviderHttpClient，筛选仅 OpenAI 文本模型，未进入公共网关的额度、脱敏、用量与 RAG 链。后台试聊成功不能代表正式 Key 链路通过验收。

**证据级别：** 源码已确认。

**源码：** [app/Services/AdminChatSessionService.php:32](/Users/leo/mysoft/TokenHubcode/app/Services/AdminChatSessionService.php:32)；[app/Services/AdminChatSessionService.php:81](/Users/leo/mysoft/TokenHubcode/app/Services/AdminChatSessionService.php:81)；[app/Services/AdminChatSessionService.php:136](/Users/leo/mysoft/TokenHubcode/app/Services/AdminChatSessionService.php:136)。

### E21

路由、额度、脱敏策略有 TTL 缓存，编辑路径未见统一版本化失效；模型/厂商删除会物理删除价格、权限等关系，缺少退役流程。

**证据级别：** 源码已确认；实际生效延迟待测。

**源码：** [app/Services/Gateway/ModelRouter.php:11](/Users/leo/mysoft/TokenHubcode/app/Services/Gateway/ModelRouter.php:11)；[app/Services/QuotaService.php:11](/Users/leo/mysoft/TokenHubcode/app/Services/QuotaService.php:11)；[app/Services/DesensitizationService.php:11](/Users/leo/mysoft/TokenHubcode/app/Services/DesensitizationService.php:11)；[app/Services/CrudService.php:83](/Users/leo/mysoft/TokenHubcode/app/Services/CrudService.php:83)；[app/Services/CrudService.php:131](/Users/leo/mysoft/TokenHubcode/app/Services/CrudService.php:131)；[app/Services/CrudService.php:241](/Users/leo/mysoft/TokenHubcode/app/Services/CrudService.php:241)。

### E22

模型 health 根据 enabled 计算，并非上游探测；队列长度读取异常返回 0；p95/p99 来自粗分桶，超过 3 秒的区间以 3001 表示。已有数据库、Redis、Qdrant 和 worker 健康入口。

**证据级别：** 源码已确认。

**源码：** [app/Services/ExternalModelService.php:139](/Users/leo/mysoft/TokenHubcode/app/Services/ExternalModelService.php:139)；[app/Services/AdminOperationsService.php:12](/Users/leo/mysoft/TokenHubcode/app/Services/AdminOperationsService.php:12)；[app/Services/AdminOperationsService.php:36](/Users/leo/mysoft/TokenHubcode/app/Services/AdminOperationsService.php:36)；[app/Services/DashboardService.php:539](/Users/leo/mysoft/TokenHubcode/app/Services/DashboardService.php:539)。

### E23

告警已有评估入库，但每次满足条件均可创建 open 事件，后台资源只读。通知、去重、认领、静默、恢复和升级需形成处置流程。

**证据级别：** 抽样源码已确认；外部运维系统覆盖情况未知。

**源码：** [app/Services/AlertService.php:22](/Users/leo/mysoft/TokenHubcode/app/Services/AlertService.php:22)；[app/Services/AlertService.php:80](/Users/leo/mysoft/TokenHubcode/app/Services/AlertService.php:80)；[public/admin/assets/js/resources.js:458](/Users/leo/mysoft/TokenHubcode/public/admin/assets/js/resources.js:458)。

### E24

备份覆盖 MySQL、配置、Caddy、media、exports；未显式覆盖 Qdrant 快照和全量 RAG 工作目录。已有恢复脚本，仍需验证跨库/文件一致性与恢复后的业务可用性。

**证据级别：** 脚本范围已确认；未执行恢复。

**源码：** [scripts/backup-tokenhub.sh:16](/Users/leo/mysoft/TokenHubcode/scripts/backup-tokenhub.sh:16)；[scripts/restore-drill-check.sh:50](/Users/leo/mysoft/TokenHubcode/scripts/restore-drill-check.sh:50)；[docker-compose.yml:184](/Users/leo/mysoft/TokenHubcode/docker-compose.yml:184)；[docker-compose.yml:70](/Users/leo/mysoft/TokenHubcode/docker-compose.yml:70)。

### E25

仓库有 188 个 *Test.php 文件，部分是字符串或源文件合同断言。真实 SDK、SSE 故障、计量一致性和完整流程需要行为证据；本轮未运行测试，未发现仓库内 .github CI 文件。

**证据级别：** 文件盘点与抽样已确认，不代表测试通过。

**源码：** [bin/run-tests.php:9](/Users/leo/mysoft/TokenHubcode/bin/run-tests.php:9)；[tests/Frontend/AdminProviderUrlDefaultsFrontendTest.php:10](/Users/leo/mysoft/TokenHubcode/tests/Frontend/AdminProviderUrlDefaultsFrontendTest.php:10)；[tests/Services/UsageWritePathTest.php:15](/Users/leo/mysoft/TokenHubcode/tests/Services/UsageWritePathTest.php:15)；[tests/Services/ProviderAdapterContractTest.php:10](/Users/leo/mysoft/TokenHubcode/tests/Services/ProviderAdapterContractTest.php:10)；[docs/acceptance/final-acceptance-checklist.md:1](/Users/leo/mysoft/TokenHubcode/docs/acceptance/final-acceptance-checklist.md:1)。

### E26

已有在线/离线 Compose、Ubuntu 和 worker 扩容资产；PHP-FPM 生产示例 max_children=40，需要验证长流与管理请求的资源竞争。部分依赖使用 latest，应纳入可重复发布清单。

**证据级别：** 部署配置已确认；未启动或压测。

**源码：** [deploy/ubuntu/php-fpm-tokenhub.conf:8](/Users/leo/mysoft/TokenHubcode/deploy/ubuntu/php-fpm-tokenhub.conf:8)；[docker-compose.yml:168](/Users/leo/mysoft/TokenHubcode/docker-compose.yml:168)；[docker-compose.yml:185](/Users/leo/mysoft/TokenHubcode/docker-compose.yml:185)；[docker-compose.offline.yml:1](/Users/leo/mysoft/TokenHubcode/docker-compose.offline.yml:1)。

### E27

流式调用留存时传给 recorder 的 output 只有 stream=true 和 usage，不是完整生成内容，需明确留存合同。RAG 已有评测门禁、向量合同和删除服务，应在此基础上完善回归与恢复。

**证据级别：** 源码已确认。

**源码：** [app/Services/GatewayService.php:387](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:387)；[app/Services/GatewayService.php:514](/Users/leo/mysoft/TokenHubcode/app/Services/GatewayService.php:514)；[app/Services/RagRetrievalEvaluationService.php:264](/Users/leo/mysoft/TokenHubcode/app/Services/RagRetrievalEvaluationService.php:264)；[app/Services/RagVectorDeletionService.php:1](/Users/leo/mysoft/TokenHubcode/app/Services/RagVectorDeletionService.php:1)。

## 官方接口资料

资料用于解释接口合同与拟定验收项，不代表 TokenHub 已通过对应平台的在线验证。

- [OpenAI：Responses 迁移指南](https://developers.openai.com/api/docs/guides/migrate-to-responses)：对象、工具、结构化输出与流事件的协议差异。
- [OpenRouter：API overview](https://openrouter.ai/docs/api_reference/overview)：OpenAI 风格请求与扩展参数。
- [OpenRouter：Provider routing](https://openrouter.ai/docs/guides/routing/provider-selection)：供应商选择、回退及数据处理约束。
- [OpenRouter：Models](https://openrouter.ai/docs/api/api-reference/models/list-all-models-and-their-properties)：模型能力、上下文、参数与价格元数据。
- [OpenRouter：Streaming](https://openrouter.ai/docs/api_reference/streaming)：流内错误与终态用量。
- [OpenRouter：Generation metadata](https://openrouter.ai/docs/api/api-reference/generations/get-request-&-usage-metadata-for-a-generation)：具体请求的实际供应商、原生用量及成本。
- [Google：Gemini OpenAI compatibility](https://ai.google.dev/gemini-api/docs/openai)：Gemini 兼容接口的地址与端点。
- [Anthropic：Streaming Messages](https://platform.claude.com/docs/en/build-with-claude/streaming)：起始事件、累计用量、工具增量与流内错误。
- [DeepSeek：Thinking mode](https://api-docs.deepseek.com/guides/thinking_mode/)：推理和工具调用过程的状态与参数合同。

