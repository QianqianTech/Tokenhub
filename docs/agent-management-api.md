# TokenHub 智能体管理 API

本文档用于让受授权的智能体管理普通用户、普通 API Key 与部门内 RAG 知识库。接口在本次变更中实现；生产使用前须由管理员后台签发管理 Key。

## 1. 安全模型

所有请求使用 `Authorization: Bearer <management-api-key>`。管理 Key 是普通 API Key 上显式附加的权限集合；没有 scope 的模型调用 Key 访问 `/v1/management/*` 会收到 `403 MANAGEMENT_SCOPE_REQUIRED`。

| 范围 | 允许操作 |
| --- | --- |
| `users:read` / `users:write` | 查询 / 创建、更新、停用普通用户 |
| `api_keys:read` / `api_keys:write` | 查询 / 创建、更新模型权限、销毁 API Key |
| `rag:read` / `rag:write` | 查询 / 创建、更新、删除知识库与数据集 |
| `usage:read` | 查询本部门（可细化到用户或 Key）的用量汇总与趋势 |

任何持有至少一个管理 scope 的 Key 都可以调用 `GET /v1/management/department` 查看自己所在部门、资源数量与自身 scope。管理员在后台“API Key”的新建/编辑表单中勾选“智能体管理权限”签发或调整管理 Key；收窄某个 Key 的 scope 时，它委派出去的下级 Key 会同步去掉父 Key 不再持有的 scope。

`*:write` 自动包含同资源 `*:read`。管理 Key 只能操作自身 `department_id` 内的资源，不能创建、修改或停用管理员账号。

**修改其他 Key 的边界**：对持有管理 scope 的 Key 执行修改（PATCH、替换模型、轮换、删除）时，只有委派链上的祖先 Key 可以操作，否则返回 `403 MANAGEMENT_TARGET_FORBIDDEN`。因此下级 Key 不能停用、轮换或删除它的父 Key，也不能动另一棵委派树里的管理 Key；没有管理 scope 的普通 Key 由本部门任何 `api_keys:write` Key 管理（PATCH、替换模型、删除）。**轮换只允许作用于自己和自己委派出去的下级 Key**：轮换会把新明文交给调用方，并带上原 Key 的知识库绑定和额度，所以不能轮换别人的普通 Key（需要时删除后重新签发）。Key 不能通过 PATCH 修改自己。

同样的边界也适用于用户：如果某个用户名下有不在调用方委派子树内的管理 Key（包括调用方自己的 Key 和它的祖先），`PATCH/DELETE /v1/management/users/{id}` 返回 `403 MANAGEMENT_TARGET_FORBIDDEN`，因为停用用户或改密码会让该用户的所有 Key 失效。

委派链上任何一级 Key 被停用、删除、过期，或其所属用户、部门被停用，所有后代 Key 的管理 scope 立即失效。签发 Key 或替换模型时，`model_ids` 只能是调用方自己被授权的活动模型（否则 `403 MANAGEMENT_MODEL_DELEGATION_DENIED`）。管理 Key 创建下级 Key 时，只能委派自己已持有的 scope；系统会记录父子委派关系，父 Key 被禁用或删除后，所有后代 Key 的管理 scope 会立即失效。首个管理 Key 必须由管理员后台创建；不要把它放入浏览器、前端代码或日志。

写请求可带 `Idempotency-Key: <uuid>`，在网络重试时复用同一个值。创建 API Key 的响应中 `key` 只返回一次，必须立即写入密钥库。

```bash
export TOKENHUB_BASE_URL='https://tokenhub.example.com'
export TOKENHUB_MANAGEMENT_KEY='tkh_...'
curl -sS "$TOKENHUB_BASE_URL/v1/management/users" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY"
```

## 2. 用户管理

| 方法 | 路径 | Scope | 说明 |
| --- | --- | --- | --- |
| GET | `/v1/management/users?limit=50&offset=0` | `users:read` | 分页列出本部门普通用户 |
| GET | `/v1/management/users/{id}` | `users:read` | 查询单个普通用户 |
| POST | `/v1/management/users` | `users:write` | 注册普通用户 |
| PATCH | `/v1/management/users/{id}` | `users:write` | 修改用户名、密码或状态；改为 `disabled`/`departed` 或改密码时，该用户的 Key 认证缓存立即失效 |
| DELETE | `/v1/management/users/{id}` | `users:write` | 停用用户并使其 Key 缓存失效 |

```bash
curl -sS -X POST "$TOKENHUB_BASE_URL/v1/management/users" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: 8d9315ea-3bf6-4f82-b9d6-8e506d8d8f9b' \
  --data '{"username":"agent-user-001","password":"change-this-password","status":"active"}'
```

## 3. API Key 生命周期

| 方法 | 路径 | Scope | 说明 |
| --- | --- | --- | --- |
| GET | `/v1/management/api-keys?user_id=&status=` | `api_keys:read` | 分页列出本部门 Key（不含明文），可按用户和状态过滤 |
| GET | `/v1/management/api-keys/{id}` | `api_keys:read` | Key 详情：授权模型、管理 scope，`manageable`（能否 PATCH/替换模型/删除）与 `rotatable`（能否轮换） |
| GET | `/v1/management/api-keys/{id}/quota` | `api_keys:read` | 该 Key 适用的额度策略与模型额度（与 `GET /v1/quota` 同结构） |
| POST | `/v1/management/api-keys` | `api_keys:write` | 为本部门普通用户签发 Key |
| PATCH | `/v1/management/api-keys/{id}` | `api_keys:write` | 修改 `name`、`status`（`active`/`disabled`）、`expires_at`（未来时间或 `null`）、`allowed_ip_cidrs`（IPv4 地址或 CIDR）；已过期的 Key（包括轮换后到期的旧 Key）不能再修改；只能重新启用由智能体停用的 Key |
| POST | `/v1/management/api-keys/{id}:rotate` | `api_keys:write` | 轮换自己或下级管理 Key；旧 Key 在 `grace_minutes`（默认 60，最多 10080）内继续可用 |
| PUT | `/v1/management/api-keys/{id}/models` | `api_keys:write` | 替换可调用的活动模型 ID 列表 |
| DELETE | `/v1/management/api-keys/{id}` | `api_keys:write` | 软删除并立即失效认证缓存 |

**停用来源**：系统记录每个 Key 是被管理员（后台编辑或批量停用）还是被智能体（`PATCH status=disabled`）停用的，详情接口以 `disabled_by`（`admin` / `management`）返回。智能体只能重新启用自己这一侧停用的 Key；管理员停用的 Key，以及启用此记录之前就已停用的 Key，返回 `409 MANAGEMENT_KEY_DISABLED_BY_ADMIN`，需管理员在后台启用。

创建 Key 只接受 `user_id`、`name`、`expires_at`、`allowed_ip_cidrs`、`model_ids`、`management_scopes`；`model_ids` 必须是活动模型，其他字段返回 `422 MANAGEMENT_INVALID_PAYLOAD`。轮换会把模型授权、模型额度、RAG 绑定、管理 scope 以及在委派树中的位置一并迁移到新 Key，原来挂在旧 Key 下的子 Key 改挂到新 Key。

```bash
# 停用、设置过期与 IP 白名单
curl -sS -X PATCH "$TOKENHUB_BASE_URL/v1/management/api-keys/456" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" -H 'Content-Type: application/json' \
  --data '{"expires_at":"2027-01-01T00:00:00Z","allowed_ip_cidrs":["10.0.0.0/8"]}'

# 轮换（返回新 Key 明文一次；旧 Key 30 分钟后失效）
curl -sS -X POST "$TOKENHUB_BASE_URL/v1/management/api-keys/456:rotate" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" -H 'Content-Type: application/json' \
  --data '{"grace_minutes":30}'
```

```bash
curl -sS -X POST "$TOKENHUB_BASE_URL/v1/management/api-keys" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: 3ad2cecf-482f-4b44-a89a-172e01c96024' \
  --data '{"user_id":123,"name":"agent-workload","model_ids":[101,102],"allowed_ip_cidrs":["10.0.0.0/8"]}'

# 返回 {"data":{"id":456,"key":"tkh_...","display":"tkh_****abcd"}}；只保存 key 一次。
curl -sS -X DELETE "$TOKENHUB_BASE_URL/v1/management/api-keys/456" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY"
```

## 4. 部门与用量

| 方法 | 路径 | Scope | 说明 |
| --- | --- | --- | --- |
| GET | `/v1/management/department` | 任一管理 scope | 所在部门、活动用户数与活动 Key 数、当前 Key 的 scope |
| GET | `/v1/management/usage/summary` | `usage:read` | 部门用量汇总、按模型与按能力拆分 |
| GET | `/v1/management/usage/trends` | `usage:read` | 部门用量趋势（`bucket=day\|hour`） |

用量接口的参数与 `/v1/usage/*` 相同（`from`、`to`、`model_id`、`provider_id`、`capability`、`bucket`、`limit`），另可加 `user_id` 或 `api_key_id` 缩小到本部门某个用户或 Key；跨部门 ID 返回 `404 MANAGEMENT_RESOURCE_NOT_FOUND`。

```bash
curl -sS "$TOKENHUB_BASE_URL/v1/management/usage/summary?from=2026-10-01&user_id=123" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY"
```

## 4.1 RAG 知识库与数据集

| 方法 | 路径 | Scope |
| --- | --- | --- |
| GET / POST | `/v1/management/rag/knowledge-bases` | `rag:read` / `rag:write` |
| PATCH / DELETE | `/v1/management/rag/knowledge-bases/{id}` | `rag:write` |
| GET / POST | `/v1/management/rag/datasets` | `rag:read` / `rag:write` |
| PATCH / DELETE | `/v1/management/rag/datasets/{id}` | `rag:write` |

创建知识库需要 `name`，可选 `description`、`visibility`（`private`、`department`、`shared`）、`owner_user_id`、`chunking_profile` 和 `retrieval_profile`。未传 `owner_user_id` 时默认归属当前管理 Key 对应用户；显式指定时该用户必须处于同一部门且为活动状态。数据集需要 `kb_id`（知识库的数值 ID）与 `name`，可选 `dataset_type`、`collection_prefix` 与 `retrieval_profile`。

```bash
curl -sS -X POST "$TOKENHUB_BASE_URL/v1/management/rag/knowledge-bases" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" -H 'Content-Type: application/json' \
  --data '{"name":"产品知识库","description":"供客服智能体检索","visibility":"department"}'

curl -sS -X POST "$TOKENHUB_BASE_URL/v1/management/rag/datasets" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" -H 'Content-Type: application/json' \
  --data '{"kb_id":88,"name":"产品手册","dataset_type":"text"}'
```

### 上传、预览与向量化

知识库或数据集由管理 API 创建后，创建者管理 Key 会自动获得该资源的 RAG 管理绑定。资料导入复用稳定的 `/v1/rag/*` 生命周期接口：先上传、检查预览，再确认向量化；上传返回的 `upload_id` 是字符串，不是数据库数值 ID。需要人工审核的部署会在确认时返回 `409 RAG_UPLOAD_REVIEW_REQUIRED`，必须由管理员审核后重试确认。

| 方法 | 路径 | 作用 |
| --- | --- | --- |
| POST | `/v1/rag/knowledge-bases/{kb_id}/uploads` | 上传 Base64 编码的资料并构建预览 |
| GET | `/v1/rag/uploads/{upload_id}` | 查询解析、切分和向量化状态 |
| GET | `/v1/rag/uploads/{upload_id}/preview` | 分页查看待入库内容和校验错误 |
| POST | `/v1/rag/uploads/{upload_id}/confirm-vectorization` | 选择数据集并确认入库/向量化 |

```bash
# KB_EXTERNAL_ID 是创建知识库响应中的 data.kb_id，例如 kb_abcd...
# DATASET_EXTERNAL_ID 是创建数据集响应中的 data.dataset_id，例如 ds_abcd...
UPLOAD_ID=$(curl -fsS -X POST "$TOKENHUB_BASE_URL/v1/rag/knowledge-bases/$KB_EXTERNAL_ID/uploads" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: 1a1d421e-0bb8-4d31-bae5-2d142c1fc77c' \
  --data "{\"filename\":\"product.csv\",\"content_base64\":\"$(base64 -w 0 product.csv)\",\"processing\":{\"template\":\"text\",\"target_dataset_id\":\"$DATASET_EXTERNAL_ID\"}}" | jq -r '.data.upload_id')

curl -fsS "$TOKENHUB_BASE_URL/v1/rag/uploads/$UPLOAD_ID/preview?page=1&page_size=20" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY"

curl -fsS -X POST "$TOKENHUB_BASE_URL/v1/rag/uploads/$UPLOAD_ID/confirm-vectorization" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: e9ace4dc-4256-41a0-b54f-711b2010f0bd' \
  --data "{\"dataset_id\":\"$DATASET_EXTERNAL_ID\"}"

curl -fsS "$TOKENHUB_BASE_URL/v1/rag/uploads/$UPLOAD_ID" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY"
```

## 5. Python 与 Node.js 示例

```python
import os, requests
base = os.environ['TOKENHUB_BASE_URL']
headers = {'Authorization': f"Bearer {os.environ['TOKENHUB_MANAGEMENT_KEY']}", 'Content-Type': 'application/json'}
user = requests.post(f'{base}/v1/management/users', headers=headers, json={'username': 'bot-user', 'password': 'change-me'}, timeout=30).json()['data']
key = requests.post(f'{base}/v1/management/api-keys', headers=headers, json={'user_id': user['id'], 'name': 'bot-key'}, timeout=30).json()['data']
print('store this once:', key['key'])
```

```js
const headers = {Authorization: `Bearer ${process.env.TOKENHUB_MANAGEMENT_KEY}`, 'Content-Type': 'application/json'};
const response = await fetch(`${process.env.TOKENHUB_BASE_URL}/v1/management/rag/knowledge-bases`, {
  method: 'POST', headers, body: JSON.stringify({name: 'Agent KB', visibility: 'department'})
});
if (!response.ok) throw new Error(await response.text());
console.log((await response.json()).data);
```

## 6. 端到端调用流程

以下示例用同一个管理 Key 完成最常见的自动化生命周期。把示例中的 ID 替换为上一步响应中的 `data.id`；所有创建和修改请求建议带上不同的 `Idempotency-Key`。

```bash
# 1) 注册普通用户
USER_ID=$(curl -fsS -X POST "$TOKENHUB_BASE_URL/v1/management/users" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: 2ca83eb9-a3f3-4f79-99a8-6364f25ed400' \
  --data '{"username":"rag-bot-001","password":"replace-with-a-strong-secret"}' | jq -r '.data.id')

# 2) 创建工作负载 API Key；明文 key 仅在这里返回一次
KEY_RESPONSE=$(curl -fsS -X POST "$TOKENHUB_BASE_URL/v1/management/api-keys" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: 6fe7bc3b-1be0-4f5e-820c-697f3eff2082' \
  --data "{\"user_id\":$USER_ID,\"name\":\"rag-bot-prod\",\"model_ids\":[101,102],\"allowed_ip_cidrs\":[\"10.0.0.0/8\"]}")
WORKLOAD_KEY_ID=$(printf '%s' "$KEY_RESPONSE" | jq -r '.data.id')
WORKLOAD_KEY=$(printf '%s' "$KEY_RESPONSE" | jq -r '.data.key')

# 3) 原子替换模型白名单；列表不能为空，且每个 ID 必须是活动模型
curl -fsS -X PUT "$TOKENHUB_BASE_URL/v1/management/api-keys/$WORKLOAD_KEY_ID/models" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: f3d5e76e-7c53-4e4d-9564-29bbaf609d45' \
  --data '{"model_ids":[101,102]}'

# 4) 创建部门知识库与数据集
KB_ID=$(curl -fsS -X POST "$TOKENHUB_BASE_URL/v1/management/rag/knowledge-bases" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: 9e086a3f-64e4-4fa3-a25f-3598ed4c9dd0' \
  --data '{"name":"产品知识库","visibility":"department"}' | jq -r '.data.id')
curl -fsS -X POST "$TOKENHUB_BASE_URL/v1/management/rag/datasets" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: 50fef69d-42fa-4d6f-a72c-c4895c7661c3' \
  --data "{\"kb_id\":$KB_ID,\"name\":\"产品手册\",\"dataset_type\":\"text\"}"

# 5) 销毁泄漏或不再使用的工作负载 Key；其认证缓存立即失效
curl -fsS -X DELETE "$TOKENHUB_BASE_URL/v1/management/api-keys/$WORKLOAD_KEY_ID" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Idempotency-Key: 2e02a621-65f5-4a8b-ab55-9c4203fbf26a'
```

`WORKLOAD_KEY` 应在创建后立即写入密钥管理服务；不要把它输出到 CI 日志。删除 API Key 是软删除，不能通过该接口恢复。

## 7. 响应与错误处理

成功响应使用 `{"data": ...}`；列表附带 `pagination.limit`、`pagination.offset`、`pagination.total`。常见错误：`401 API_KEY_INVALID`（Key 无效）、`403 MANAGEMENT_SCOPE_REQUIRED`（缺 scope）、`403 MANAGEMENT_SCOPE_DELEGATION_DENIED`（试图委派未持有 scope）、`404 MANAGEMENT_RESOURCE_NOT_FOUND`（跨部门或不存在资源）、`403 MANAGEMENT_TARGET_FORBIDDEN`（试图修改父 Key 或其他委派树的管理 Key）、`403 MANAGEMENT_MODEL_DELEGATION_DENIED`（授予自己没有的模型）、`409 MANAGEMENT_KEY_DISABLED_BY_ADMIN`（该 Key 由管理员停用，只能由管理员重新启用）、`409 MANAGEMENT_KEY_DELETED` / `409 MANAGEMENT_KEY_EXPIRED` / `409 MANAGEMENT_KEY_NOT_ACTIVE`（已删除或已过期的 Key 不能修改、非活动 Key 不能轮换）、`422 MANAGEMENT_INVALID_PAYLOAD`（参数不合法）、`429 RATE_LIMITED`（按 `Retry-After` 重试）。删除是软删除/停用；不要依赖物理删除。
