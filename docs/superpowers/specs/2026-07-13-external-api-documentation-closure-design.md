# 智能体管理 API 与对外接入文档设计

## 目标

让受授权智能体通过对外 API 完成用户、API Key 和知识库 RAG 的管理，并提供与实现一致的中文接入文档和可复制调用示例。

## 范围

1. 新增 `/v1/management/*` 管理 API，覆盖普通用户的创建、查询、更新、停用，API Key 的创建、查询、模型权限更新和销毁，以及 RAG 知识库和数据集的创建、查询、更新、停用。
2. 使用现有 API Key 认证链，但仅允许显式授予管理 scope 的 Key 访问管理 API。普通模型调用 Key 继续只能调用已有 `/v1/*` 业务接口。
3. 管理 scope 细分为 `users:read`、`users:write`、`api_keys:read`、`api_keys:write`、`rag:read`、`rag:write`；写 scope 自动包含对应读能力。
4. 新增完整中文智能体接入文档，包含管理 Key 的创建边界、curl、Python 和 Node.js 调用示例、错误处理、幂等与密钥轮换建议。
5. 同步更新 OpenAPI，并增加路由、授权、跨部门隔离、文档覆盖与示例契约测试。

## 非目标

- 不允许普通 API Key 提升为管理权限。
- 不通过 API 管理管理员或超级管理员账号。
- 不改变模型网关、同步、Webhook、配额与部署配置的既有行为。

## 设计

新增 `api_key_management_scopes` 表，以 API Key 和 scope 组成唯一键。管理中间件先复用 `ApiKeyService::authenticate()` 完成状态、到期、IP、用户、部门校验，再验证请求所需 scope。管理控制器只调用受限服务：用户操作拒绝 `admin` 与 `super_admin` 目标；API Key 操作始终限制在认证 Key 所属部门；RAG 操作也按认证 Key 部门隔离。所有写操作写审计日志并支持既有 `Idempotency-Key` 约定。

管理 API 与模型调用 API 使用不同路径和权限，避免凭一个普通调用 Key 创建账号或签发更多 Key。首个管理 Key 必须由现有管理员后台创建并显式选择管理 scopes；文档将该人工引导步骤作为安全边界，而不是提供无凭据自举接口。

`docs/agent-management-api.md` 成为智能体管理的完整中文参考；原有对外调用文档保留模型、同步和消费型 RAG 接口，并链接新文档。OpenAPI 对每个新操作提供请求体、成功和标准错误响应。

## 验证

1. 新增测试先验证无 scope、缺少写 scope、跨部门资源访问和管理员账号目标均被拒绝。
2. 验证每组 scope 能完成对应最小管理流程，且新 API Key 的明文只在创建响应中出现一次。
3. 验证 OpenAPI 和 `docs/agent-management-api.md` 包含全部管理操作及 curl、Python、Node.js 示例。
4. 在 Docker PHP 容器运行新增测试和完整 `php bin/run-tests.php`。
