# TokenHub 智能体管理 API

本文档用于让受授权的智能体管理普通用户、普通 API Key 与部门内 RAG 知识库。接口在本次变更中实现；生产使用前须由管理员后台签发管理 Key。

## 1. 安全模型

所有请求使用 `Authorization: Bearer <management-api-key>`。管理 Key 是普通 API Key 上显式附加的权限集合；没有 scope 的模型调用 Key 访问 `/v1/management/*` 会收到 `403 MANAGEMENT_SCOPE_REQUIRED`。

| 范围 | 允许操作 |
| --- | --- |
| `users:read` / `users:write` | 查询 / 创建、更新、停用普通用户 |
| `api_keys:read` / `api_keys:write` | 查询 / 创建、更新模型权限、销毁 API Key |
| `rag:read` / `rag:write` | 查询 / 创建、更新、删除知识库与数据集 |

`*:write` 自动包含同资源 `*:read`。管理 Key 只能操作自身 `department_id` 内的资源，不能创建、修改或停用管理员账号。管理 Key 创建下级 Key 时，只能委派自己已持有的 scope；系统会记录父子委派关系，父 Key 被禁用或删除后，所有后代 Key 的管理 scope 会立即失效。首个管理 Key 必须由管理员后台创建；不要把它放入浏览器、前端代码或日志。

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
| POST | `/v1/management/users` | `users:write` | 注册普通用户 |
| PATCH | `/v1/management/users/{id}` | `users:write` | 修改用户名、密码或状态 |
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
| GET | `/v1/management/api-keys` | `api_keys:read` | 分页列出本部门 Key（不含明文） |
| POST | `/v1/management/api-keys` | `api_keys:write` | 为本部门普通用户签发 Key |
| PUT | `/v1/management/api-keys/{id}/models` | `api_keys:write` | 替换可调用的活动模型 ID 列表 |
| DELETE | `/v1/management/api-keys/{id}` | `api_keys:write` | 软删除并立即失效认证缓存 |

```bash
curl -sS -X POST "$TOKENHUB_BASE_URL/v1/management/api-keys" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY" \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: 3ad2cecf-482f-4b44-a89a-172e01c96024' \
  --data '{"user_id":123,"name":"agent-workload","model_ids":[101,102],"allowed_ip_cidrs":["10.0.0.0/8"]}'

# 返回 {"data":{"id":456,"key":"tkh_...","display":"tkh_****abcd"}}；只保存 key 一次。
curl -sS -X DELETE "$TOKENHUB_BASE_URL/v1/management/api-keys/456" \
  -H "Authorization: Bearer $TOKENHUB_MANAGEMENT_KEY"
```

## 4. RAG 知识库与数据集

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

成功响应使用 `{"data": ...}`；列表附带 `pagination.limit`、`pagination.offset`、`pagination.total`。常见错误：`401 API_KEY_INVALID`（Key 无效）、`403 MANAGEMENT_SCOPE_REQUIRED`（缺 scope）、`403 MANAGEMENT_SCOPE_DELEGATION_DENIED`（试图委派未持有 scope）、`404 MANAGEMENT_RESOURCE_NOT_FOUND`（跨部门或不存在资源）、`422 MANAGEMENT_INVALID_PAYLOAD`（参数不合法）、`429 RATE_LIMITED`（按 `Retry-After` 重试）。删除是软删除/停用；不要依赖物理删除。
