# TH-018 决策记录：第二批与私有模型

状态：**待决策**（2026-10-28 起草）

## 问题

哪些额外厂商或私有推理服务有实际使用需求？

## 选项

候选：MiniMax、xAI/Grok、Mistral、平台托管 Llama、SiliconFlow、vLLM、Ollama；Batch、Realtime、原生 Gemini 协议另列合同。

## 建议

按真实需求逐个纳入，使用 ProviderEndpointResolver 的端点映射与 tokenhub_allow_private_network 接入私有推理，复用 scripts/provider-acceptance.php 验收。

## 若实施的验收要求

未选定的平台与协议明确为范围外。

## 决定

- 决定：（待填写）
- 决策人 / 日期：（待填写）
