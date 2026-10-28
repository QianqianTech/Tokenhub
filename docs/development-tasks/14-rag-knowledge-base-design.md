# TokenHub 14 RAG 知识库与向量检索方案

生成日期：2026-07-09  
适用范围：独立 RAG 数据库、向量配置、知识库上传导入、文本/问答/图片向量处理、外部 API 上传、自动向量化、模型网关增强。

## 1. 目标

TokenHub 增加一套独立知识库 RAG 能力，让外部软件继续通过 API Key 使用 TokenHub，而不需要普通用户登录当前页面。

核心目标：

- 支持独立 RAG 元数据数据库和独立向量存储，避免污染现有模型网关主库表。
- 支持管理员后台创建知识库、配置向量模型、配置切片策略、查看导入和向量化状态。
- 支持外部 API 上传文档、Excel、文本、问答对、图片，并记录上传用户、API Key、部门、来源设备、原始文件、处理状态。
- 支持 Excel 模板下载，模板必须包含样式、示例、必填列标识、下拉选项、填写说明和错误提示，降低用户上传错误率。
- 上传后先进入解析和预览阶段，用户或外部系统确认内容无误后，再批量向量化。
- 上传预览必须按文本、图片分类展示；内容较多时分页展示，每页默认 20 条。
- 上传、解析、预览确认、向量化、索引发布必须显示进度条和阶段状态。
- 支持向量数据集名称；不同 API Key 可以绑定不同向量数据集，并只能调用被授权的数据集。
- 用户确认后自动进入切片、抽取、向量化、索引发布流程。
- 支持文本模型、多模态模型、图片模型的不同向量处理策略。
- 支持图片向量深度处理：图片原始向量、OCR 文本、图片描述、用途、详细说明、标签、可选多模态 caption。
- 支持 RAG 调用接入现有 `/v1/chat/completions`、`/v1/responses`、`/v1/messages` 模型网关。
- 向量化完成后的可检索成功率目标不低于 95%。

## 2. 非目标

第一阶段不做以下内容：

- 不让普通用户登录 TokenHub 页面管理知识库。普通用户仍然只通过 API Key 上传、同步和调用。
- 不把 RAG 写成单一模型厂商能力。Embedding、图片向量、多模态向量都必须可配置。
- 不强制替换现有模型调用协议。外部应用仍可使用 OpenAI/Anthropic 兼容接口。
- 不在同步响应中等待大型文件全部向量化完成。上传接口只负责入库和创建异步任务。
- 不把 `.env`、Docker Compose、Caddy 作为本方案的直接变更内容。环境配置在实施阶段单独确认。

## 3. 总体架构

推荐架构：

```text
External Client
  |
  | API Key
  v
TokenHub /v1/rag/uploads
  |
  v
RAG Metadata DB
  |
  v
RAG Worker Queue
  |
  +--> file parse / Excel parse / OCR / image caption
  +--> preview text rows / image rows
  +--> wait for confirm
  +--> text chunk / QA chunk / image asset chunk
  +--> embedding request through TokenHub model gateway or provider client
  +--> vector upsert
  +--> index publish
  |
  v
Vector Store

External Client
  |
  | /v1/chat/completions + knowledge_base_ids
  v
GatewayService
  |
  +--> RagRetrievalService
  +--> PromptContextInjector
  +--> existing ProviderHttpClient
```

关键原则：

- RAG 元数据与现有业务库隔离，降低数据量、索引、迁移对现有网关的影响。
- 向量存储可插拔，第一推荐 Qdrant，保留 Milvus、pgvector、Elasticsearch dense_vector 扩展位。
- 原始文件、解析结果、切片、向量索引、向量化任务分开存储，便于重试、审计、删除和重建索引。
- 所有上传和检索都绑定 API Key、用户、部门和知识库权限。
- 对外调用优先使用向量数据集 `dataset_id` 或 `dataset_name`，知识库是内容管理容器，数据集是可调用检索索引。

## 4. 独立 RAG 数据库

建议新增独立数据库：

```text
tokenhub_rag
```

如果部署暂时不能创建独立库，可先使用同一个 MySQL 实例中的独立表前缀：

```text
rag_*
```

但方案上仍按独立 RAG 数据库设计，方便后续迁移。

### 4.1 数据库职责

RAG 数据库只保存：

- 知识库元数据。
- 上传文件元数据。
- 文档解析状态。
- 文本切片、问答切片、图片切片的结构化内容。
- 向量配置快照。
- 向量化任务状态和错误。
- 外部 API 上传记录。
- 检索命中记录和引用来源。

RAG 数据库不直接保存：

- 厂商 API Key 明文。
- 用户 API Key 明文。
- 大型原始文件二进制内容。
- 大型向量数组，除非使用 MySQL 临时 fallback。

原始文件建议继续放对象/文件存储，数据库只保存加密后的 `storage_path`、hash、大小、MIME。

## 5. 表结构设计

### 5.1 `rag_knowledge_bases`

知识库主表。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `kb_id` | VARCHAR(64) UNIQUE | 外部稳定 ID，例如 `kb_xxx` |
| `name` | VARCHAR(120) | 知识库名称 |
| `description` | TEXT NULL | 描述 |
| `owner_user_id` | BIGINT | 创建人 |
| `department_id` | BIGINT NULL | 所属部门 |
| `visibility` | ENUM | `private`, `department`, `shared` |
| `status` | ENUM | `active`, `disabled`, `reindexing`, `error` |
| `default_text_vector_config_id` | BIGINT NULL | 默认文本向量配置 |
| `default_image_vector_config_id` | BIGINT NULL | 默认图片向量配置 |
| `default_multimodal_vector_config_id` | BIGINT NULL | 默认多模态向量配置 |
| `chunking_profile` | JSON | 默认切片策略 |
| `retrieval_profile` | JSON | 默认检索策略 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |
| `deleted_at` | DATETIME NULL | 软删除 |

### 5.2 `rag_knowledge_base_bindings`

API Key、用户、部门与知识库的授权关系。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `kb_id` | BIGINT | 知识库 ID |
| `subject_type` | ENUM | `api_key`, `user`, `department` |
| `subject_id` | BIGINT | 绑定对象 ID |
| `permission` | ENUM | `read`, `write`, `admin` |
| `is_default` | TINYINT | 是否默认用于 RAG |
| `created_at` | DATETIME | 创建时间 |

规则：

- 外部 API 上传需要 `write`。
- 外部 RAG 检索需要 `read`。
- 默认知识库只对绑定对象生效，不跨部门继承。

### 5.3 `rag_vector_datasets`

向量数据集表。数据集是外部 API 调用时选择的检索单位，名称必须对用户可理解。

知识库和数据集的关系：

- 知识库负责内容管理、上传、解析、预览和权限管理。
- 数据集负责“哪些 chunk 用什么向量配置索引到哪个 collection”。
- 一个知识库可以有多个数据集，例如“客服 FAQ 文本数据集”“产品图片多模态数据集”“售后资料混合数据集”。
- 一个数据集可以只包含文本，也可以包含文本、问答、图片和多模态向量。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `dataset_id` | VARCHAR(64) UNIQUE | 外部稳定 ID，例如 `ds_xxx` |
| `kb_id` | BIGINT | 所属知识库 ID |
| `name` | VARCHAR(120) | 数据集名称，对外可展示 |
| `description` | TEXT NULL | 数据集说明 |
| `dataset_type` | ENUM | `text`, `image`, `multimodal`, `mixed` |
| `text_vector_config_id` | BIGINT NULL | 文本向量配置 |
| `image_vector_config_id` | BIGINT NULL | 图片向量配置 |
| `multimodal_vector_config_id` | BIGINT NULL | 多模态向量配置 |
| `collection_prefix` | VARCHAR(120) | 向量集合前缀 |
| `status` | ENUM | `draft`, `active`, `reindexing`, `disabled`, `error` |
| `chunk_count` | BIGINT | 已纳入 chunk 数 |
| `vector_count` | BIGINT | 已索引向量数 |
| `last_indexed_at` | DATETIME NULL | 最近索引完成时间 |
| `retrieval_profile` | JSON | 检索参数，如 top_k、阈值、rerank |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |
| `deleted_at` | DATETIME NULL | 软删除 |

数据集命名规则：

- 后台显示名称允许中文，例如“客服问答知识库-正式数据集”。
- API 推荐使用稳定 `dataset_id`。
- 允许按 `dataset_name` 查询，但名称重名时必须返回冲突错误，让调用方改用 `dataset_id`。

### 5.4 `rag_dataset_bindings`

API Key、用户、部门与向量数据集的授权关系。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `dataset_id` | BIGINT | 向量数据集 ID |
| `subject_type` | ENUM | `api_key`, `user`, `department` |
| `subject_id` | BIGINT | 绑定对象 ID |
| `permission` | ENUM | `read`, `write`, `admin` |
| `is_default` | TINYINT | 是否默认用于 RAG |
| `priority` | INT | 多个默认数据集时的排序 |
| `created_at` | DATETIME | 创建时间 |

规则：

- 外部模型调用优先校验 `rag_dataset_bindings`。
- API Key 只能检索自己绑定的数据集。
- 一个 API Key 可以绑定多个数据集。
- 如果请求没有传 `dataset_ids`，使用该 API Key 的默认数据集。
- 数据集权限不能扩大知识库权限；数据集和知识库任一侧无权限都必须拒绝。

### 5.5 `rag_vector_configs`

向量配置表。每次向量化任务必须保存配置快照，避免模型配置变更后无法解释旧索引。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `config_id` | VARCHAR(64) UNIQUE | 外部稳定 ID |
| `name` | VARCHAR(120) | 配置名称 |
| `modality` | ENUM | `text`, `image`, `multimodal` |
| `provider_id` | BIGINT NULL | 复用 TokenHub 厂商 |
| `model_id` | BIGINT NULL | 复用 TokenHub 模型 |
| `model_name` | VARCHAR(160) | 实际 embedding 模型名 |
| `dimension` | INT | 向量维度 |
| `distance_metric` | ENUM | `cosine`, `dot`, `euclidean` |
| `vector_store` | ENUM | `qdrant`, `milvus`, `pgvector`, `mysql_fallback` |
| `collection_name` | VARCHAR(160) | 向量集合名 |
| `batch_size` | INT | 批量向量化大小 |
| `max_input_tokens` | INT NULL | 文本最大输入 token |
| `image_max_pixels` | INT NULL | 图片处理最大像素 |
| `normalize_vector` | TINYINT | 是否归一化 |
| `enabled` | TINYINT | 是否启用 |
| `config_json` | JSON | 额外配置 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

### 5.6 `rag_import_templates`

导入模板表，用于 Excel 模板下载、版本管理和样式说明。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `template_id` | VARCHAR(64) UNIQUE | 外部模板 ID |
| `name` | VARCHAR(120) | 模板名称 |
| `template_type` | ENUM | `text`, `qa`, `image`, `mixed` |
| `version` | VARCHAR(40) | 模板版本 |
| `filename` | VARCHAR(260) | 下载文件名 |
| `storage_path` | VARCHAR(800) | 模板文件路径 |
| `required_columns` | JSON | 必填列 |
| `optional_columns` | JSON | 可选列 |
| `style_profile` | JSON | 表头颜色、列宽、示例行、下拉项 |
| `enabled` | TINYINT | 是否启用 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

模板版本要求：

- 模板下载接口必须返回当前启用版本。
- 上传 Excel 时必须识别模板版本；老版本模板允许兼容，但要提示建议升级。
- 模板样式不是装饰能力，而是数据质量控制能力，必须随模板一起测试。

### 5.7 `rag_uploads`

外部上传记录表。用于记录上传人、API Key、部门、来源和处理链路。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `upload_id` | VARCHAR(64) UNIQUE | 外部上传 ID |
| `kb_id` | BIGINT | 知识库 ID |
| `owner_user_id` | BIGINT | API Key 归属用户 |
| `department_id` | BIGINT NULL | API Key 归属部门 |
| `api_key_id` | BIGINT NULL | 上传使用的 API Key |
| `device_id` | VARCHAR(120) NULL | 外部设备 ID |
| `source_type` | ENUM | `api`, `admin`, `sync`, `batch_import` |
| `content_type` | ENUM | `document`, `excel`, `text`, `qa`, `image`, `zip` |
| `original_filename` | VARCHAR(260) NULL | 原始文件名 |
| `mime_type` | VARCHAR(120) NULL | MIME |
| `size_bytes` | BIGINT | 文件大小 |
| `sha256` | CHAR(64) | 内容 hash |
| `storage_path` | VARCHAR(800) NULL | 原始文件路径 |
| `metadata` | JSON | 上传附加信息 |
| `preview_status` | ENUM | `pending`, `building`, `ready`, `confirmed`, `rejected`, `failed` |
| `vectorize_status` | ENUM | `not_started`, `queued`, `processing`, `indexed`, `partial_failed`, `failed` |
| `status` | ENUM | `uploaded`, `previewing`, `waiting_confirm`, `vectorizing`, `indexed`, `partial_failed`, `failed`, `deleted` |
| `confirm_required` | TINYINT | 是否必须确认后向量化 |
| `confirmed_by_user_id` | BIGINT NULL | 后台确认人 |
| `confirmed_by_api_key_id` | BIGINT NULL | API 确认方 |
| `confirmed_at` | DATETIME NULL | 确认时间 |
| `total_items` | INT | 解析出的总条目 |
| `text_items` | INT | 文本条目数 |
| `image_items` | INT | 图片条目数 |
| `processed_items` | INT | 已处理条目数 |
| `progress_percent` | DECIMAL(5,2) | 当前进度百分比 |
| `current_stage` | VARCHAR(80) NULL | 当前阶段 |
| `error_code` | VARCHAR(80) NULL | 错误码 |
| `error_message` | VARCHAR(500) NULL | 错误摘要 |
| `created_at` | DATETIME | 上传时间 |
| `updated_at` | DATETIME | 更新时间 |

### 5.8 `rag_upload_preview_items`

上传预览条目表。上传解析完成后先写入预览，用户确认后才生成正式 chunk 和向量任务。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `preview_item_id` | VARCHAR(80) UNIQUE | 外部预览条目 ID |
| `upload_id` | BIGINT | 上传 ID |
| `kb_id` | BIGINT | 知识库 ID |
| `dataset_id` | BIGINT NULL | 目标数据集 ID |
| `item_type` | ENUM | `text`, `qa`, `image`, `table_row`, `error` |
| `title` | VARCHAR(260) NULL | 标题 |
| `content` | MEDIUMTEXT NULL | 文本内容 |
| `question` | TEXT NULL | 问题 |
| `answer` | MEDIUMTEXT NULL | 答案 |
| `image_asset_id` | BIGINT NULL | 已解析图片 ID |
| `purpose` | VARCHAR(260) NULL | 图片作用 |
| `description` | TEXT NULL | 图片描述 |
| `detailed_description` | MEDIUMTEXT NULL | 图片详细说明 |
| `row_number` | INT NULL | Excel 行号 |
| `sheet_name` | VARCHAR(120) NULL | Excel sheet |
| `validation_status` | ENUM | `valid`, `warning`, `error` |
| `validation_errors` | JSON | 行级错误 |
| `metadata` | JSON | 分类、标签、来源等 |
| `created_at` | DATETIME | 创建时间 |

预览规则：

- 预览接口必须按 `item_type=text` 和 `item_type=image` 分类展示。
- 默认每页 20 条，最大每页 100 条。
- `validation_status=error` 的条目不能进入向量化。
- 用户可以在确认前删除错误条目、禁用条目或重新上传。
- 确认时系统只处理 `valid` 和允许导入的 `warning` 条目。

### 5.9 `rag_documents`

上传后的逻辑文档表。

一个 Excel 文件可以拆成多个文档；一个 ZIP 可以拆出多个文档；一张图片也可以是一个文档。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `document_id` | VARCHAR(64) UNIQUE | 外部文档 ID |
| `kb_id` | BIGINT | 知识库 ID |
| `upload_id` | BIGINT | 上传记录 ID |
| `title` | VARCHAR(260) | 标题 |
| `doc_type` | ENUM | `plain_text`, `qa`, `markdown`, `pdf`, `word`, `excel`, `image`, `html` |
| `language` | VARCHAR(20) NULL | 语言 |
| `source_uri` | VARCHAR(800) NULL | 来源 URI |
| `version` | INT | 文档版本 |
| `parse_status` | ENUM | `pending`, `parsed`, `partial_failed`, `failed` |
| `index_status` | ENUM | `pending`, `indexing`, `indexed`, `partial_failed`, `failed` |
| `metadata` | JSON | 业务元数据 |
| `effective_at` | DATETIME NULL | 生效时间 |
| `expires_at` | DATETIME NULL | 过期时间 |
| `superseded_by_document_id` | BIGINT NULL | 被哪个新版本替代 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |
| `deleted_at` | DATETIME NULL | 软删除 |

### 5.10 `rag_chunks`

统一切片表。文本、问答和图片都映射为 chunk。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `chunk_id` | VARCHAR(80) UNIQUE | 外部 chunk ID |
| `kb_id` | BIGINT | 知识库 ID |
| `document_id` | BIGINT | 文档 ID |
| `chunk_type` | ENUM | `text`, `qa`, `image`, `image_text`, `table_row`, `table_summary` |
| `ordinal` | INT | 顺序 |
| `title` | VARCHAR(260) NULL | 切片标题 |
| `content` | MEDIUMTEXT | 用于文本检索的主体内容 |
| `question` | TEXT NULL | 问答问题 |
| `answer` | MEDIUMTEXT NULL | 问答答案 |
| `image_asset_id` | BIGINT NULL | 图片资产 ID |
| `token_count` | INT NULL | token 数 |
| `metadata` | JSON | 页码、sheet、行号、图片说明等 |
| `content_hash` | CHAR(64) | 切片 hash |
| `enabled` | TINYINT | 是否可检索 |
| `lifecycle_status` | ENUM | `active`, `expired`, `corrected`, `superseded`, `deleted` |
| `effective_at` | DATETIME NULL | 生效时间 |
| `expires_at` | DATETIME NULL | 过期时间 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

### 5.11 `rag_image_assets`

图片资产表。图片不只作为文件保存，还要保存图片语义。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `image_id` | VARCHAR(64) UNIQUE | 外部图片 ID |
| `kb_id` | BIGINT | 知识库 ID |
| `upload_id` | BIGINT | 上传记录 ID |
| `document_id` | BIGINT NULL | 所属文档 |
| `original_filename` | VARCHAR(260) NULL | 原始文件名 |
| `mime_type` | VARCHAR(120) | MIME |
| `width` | INT NULL | 宽 |
| `height` | INT NULL | 高 |
| `size_bytes` | BIGINT | 大小 |
| `sha256` | CHAR(64) | hash |
| `storage_path` | VARCHAR(800) | 文件路径 |
| `purpose` | VARCHAR(260) NULL | 图片作用 |
| `short_description` | TEXT NULL | 图片描述 |
| `detailed_description` | MEDIUMTEXT NULL | 详细说明 |
| `ocr_text` | MEDIUMTEXT NULL | OCR 文本 |
| `labels` | JSON | 标签、对象、场景 |
| `safety_flags` | JSON | 安全检测结果 |
| `metadata` | JSON | EXIF、来源、业务字段 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

图片字段含义：

- `purpose`：图片在知识库中的业务作用，例如“产品安装示意图”“报销单截图”“设备故障照片”。
- `short_description`：一句话描述，用于快速召回和结果展示。
- `detailed_description`：面向 RAG 的详细说明，可包含构图、关键对象、文字、步骤、注意事项。
- `ocr_text`：图片内可识别文字，用于文本向量和关键词检索。
- `labels`：结构化标签，例如对象、颜色、品牌、地点、文档类型。

### 5.12 `rag_embedding_jobs`

向量化任务表。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `job_id` | VARCHAR(64) UNIQUE | 外部任务 ID |
| `kb_id` | BIGINT | 知识库 ID |
| `upload_id` | BIGINT NULL | 上传 ID |
| `document_id` | BIGINT NULL | 文档 ID |
| `chunk_id` | BIGINT NULL | 切片 ID |
| `job_type` | ENUM | `parse`, `ocr`, `caption`, `text_embedding`, `image_embedding`, `multimodal_embedding`, `index_publish` |
| `status` | ENUM | `queued`, `running`, `succeeded`, `retrying`, `failed`, `cancelled` |
| `attempts` | INT | 已尝试次数 |
| `max_attempts` | INT | 最大尝试次数 |
| `next_run_at` | DATETIME NULL | 下次运行时间 |
| `locked_at` | DATETIME NULL | worker 锁定时间 |
| `error_code` | VARCHAR(80) NULL | 错误码 |
| `error_message` | VARCHAR(500) NULL | 错误摘要 |
| `metrics` | JSON | 耗时、token、图片大小、向量条数 |
| `total_items` | INT | 任务总条目 |
| `processed_items` | INT | 已处理条目 |
| `progress_percent` | DECIMAL(5,2) | 任务进度 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

### 5.13 `rag_vector_records`

向量索引记录映射表。实际向量存储在 Qdrant/Milvus 等向量库。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `vector_id` | VARCHAR(120) UNIQUE | 向量库 point ID |
| `kb_id` | BIGINT | 知识库 ID |
| `dataset_id` | BIGINT | 向量数据集 ID |
| `chunk_id` | BIGINT | 切片 ID |
| `image_id` | BIGINT NULL | 图片 ID |
| `vector_config_id` | BIGINT | 向量配置 ID |
| `modality` | ENUM | `text`, `image`, `multimodal` |
| `dimension` | INT | 向量维度 |
| `content_hash` | CHAR(64) | 内容 hash |
| `index_status` | ENUM | `indexed`, `stale`, `deleted`, `failed` |
| `indexed_at` | DATETIME NULL | 索引时间 |
| `deleted_at` | DATETIME NULL | 向量删除时间 |
| `created_at` | DATETIME | 创建时间 |

### 5.14 `rag_retrieval_logs`

检索日志表，用于调优召回率和成功率。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `request_id` | VARCHAR(64) | 请求 ID |
| `api_key_id` | BIGINT NULL | API Key |
| `user_id` | BIGINT NULL | 用户 |
| `department_id` | BIGINT NULL | 部门 |
| `kb_ids` | JSON | 检索知识库 |
| `dataset_ids` | JSON | 检索向量数据集 |
| `query` | TEXT | 查询文本或摘要 |
| `query_modality` | ENUM | `text`, `image`, `multimodal` |
| `top_k` | INT | 返回数量 |
| `hit_count` | INT | 命中数量 |
| `max_score` | DECIMAL(10,6) NULL | 最高分 |
| `latency_ms` | INT | 检索耗时 |
| `fallback_used` | TINYINT | 是否使用 fallback |
| `created_at` | DATETIME | 创建时间 |

### 5.15 `rag_answer_feedback`

RAG 调用后的反馈表，用来记录回答是否正确、是否命中旧数据、是否需要纠偏。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `feedback_id` | VARCHAR(64) UNIQUE | 外部反馈 ID |
| `request_id` | VARCHAR(64) | 模型请求 ID |
| `retrieval_log_id` | BIGINT NULL | 检索日志 ID |
| `api_key_id` | BIGINT NULL | API Key |
| `user_id` | BIGINT NULL | 用户 |
| `department_id` | BIGINT NULL | 部门 |
| `rating` | ENUM | `good`, `bad`, `outdated`, `wrong_source`, `missing_context` |
| `comment` | TEXT NULL | 用户反馈说明 |
| `source_chunk_ids` | JSON | 用户指出有问题的 chunk |
| `suggested_answer` | MEDIUMTEXT NULL | 建议答案 |
| `status` | ENUM | `open`, `reviewing`, `resolved`, `rejected` |
| `created_at` | DATETIME | 创建时间 |
| `resolved_at` | DATETIME NULL | 处理时间 |

### 5.16 `rag_correction_tasks`

纠偏任务表。反馈、管理员手动标记、过期策略都可以生成纠偏任务。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `correction_id` | VARCHAR(64) UNIQUE | 外部纠偏 ID |
| `kb_id` | BIGINT | 知识库 ID |
| `dataset_id` | BIGINT NULL | 数据集 ID |
| `source_type` | ENUM | `feedback`, `admin`, `expiry`, `api`, `sync` |
| `action` | ENUM | `update_content`, `disable_chunk`, `replace_document`, `delete_document`, `reindex_dataset` |
| `target_document_id` | BIGINT NULL | 目标文档 |
| `target_chunk_id` | BIGINT NULL | 目标 chunk |
| `replacement_upload_id` | BIGINT NULL | 替换上传 ID |
| `status` | ENUM | `pending`, `approved`, `running`, `succeeded`, `failed`, `cancelled` |
| `reason` | TEXT NULL | 纠偏原因 |
| `created_by_user_id` | BIGINT NULL | 创建人 |
| `created_at` | DATETIME | 创建时间 |
| `completed_at` | DATETIME NULL | 完成时间 |

### 5.17 `rag_retention_policies`

RAG 数据生命周期策略表。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT PK | 自增 ID |
| `policy_id` | VARCHAR(64) UNIQUE | 外部策略 ID |
| `kb_id` | BIGINT NULL | 绑定知识库 |
| `dataset_id` | BIGINT NULL | 绑定数据集 |
| `name` | VARCHAR(120) | 策略名称 |
| `expires_after_days` | INT NULL | 上传后多少天过期 |
| `inactive_after_days` | INT NULL | 多久未命中后标记冷数据 |
| `action_on_expiry` | ENUM | `mark_expired`, `disable_retrieval`, `delete_vectors`, `delete_document` |
| `require_approval` | TINYINT | 是否需要管理员确认 |
| `enabled` | TINYINT | 是否启用 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |

## 6. 向量存储选型

### 6.1 推荐：Qdrant

推荐第一阶段使用 Qdrant：

- 部署简单，适合 Docker 和独立服务。
- 支持多 collection。
- 支持 payload 过滤，可按 `kb_id`、`department_id`、`document_id`、`modality` 过滤。
- 支持 cosine、dot、euclidean。
- 支持 HNSW 索引。

集合建议：

```text
tokenhub_rag_text_v1
tokenhub_rag_image_v1
tokenhub_rag_multimodal_v1
```

每个 point 的 payload：

```json
{
  "kb_id": "kb_xxx",
  "document_id": "doc_xxx",
  "chunk_id": "chk_xxx",
  "image_id": "img_xxx",
  "owner_user_id": 12,
  "department_id": 1,
  "modality": "image",
  "chunk_type": "image",
  "content_hash": "sha256...",
  "enabled": true,
  "created_at": "2026-07-09T00:00:00Z"
}
```

### 6.2 备选：Milvus

适合数据量较大、后续要独立向量集群的部署。缺点是运维成本高于 Qdrant。

### 6.3 备选：pgvector

适合已经有 PostgreSQL 的团队。当前 TokenHub 主体是 MySQL，因此不建议第一阶段引入 PostgreSQL 作为强依赖。

### 6.4 MySQL fallback

只作为开发测试或小规模兜底：

- 可保存 JSON 向量。
- 可做低性能余弦相似度计算。
- 不作为生产推荐方案。

## 7. 向量配置明细

### 7.1 文本向量配置

适用内容：

- 普通文档文本。
- Markdown。
- PDF/Word 解析后的文字。
- Excel 文本列。
- 问答对。
- 图片 OCR 文本。
- 图片描述和详细说明。

建议参数：

```json
{
  "modality": "text",
  "distance_metric": "cosine",
  "chunk_size_tokens": 600,
  "chunk_overlap_tokens": 80,
  "batch_size": 64,
  "top_k": 8,
  "score_threshold": 0.35,
  "rerank": true
}
```

处理原则：

- 长文档按标题、段落、表格边界优先切片。
- 问答对不拆开问题和答案，问题、答案、同义问题共同生成检索文本。
- 图片 OCR 文本与图片描述可各自生成文本向量，也可合并生成一个 `image_text` chunk。

### 7.2 图片向量配置

适用内容：

- PNG、JPG、JPEG、WEBP。
- Excel 中引用的图片。
- Word/PDF 中抽取的图片。
- API 单独上传的图片。

建议参数：

```json
{
  "modality": "image",
  "distance_metric": "cosine",
  "image_max_pixels": 4194304,
  "thumbnail_size": 512,
  "batch_size": 16,
  "top_k": 12,
  "score_threshold": 0.28,
  "store_thumbnail": true
}
```

处理流程：

1. 校验图片格式、大小、像素、hash。
2. 清理危险 EXIF 或仅提取安全元数据。
3. 生成缩略图，保留原图。
4. OCR 识别图片文字。
5. 调用多模态模型生成 `short_description`。
6. 调用多模态模型生成 `detailed_description`。
7. 抽取标签、对象、场景、用途。
8. 生成图片原始向量。
9. 使用 OCR、描述、详细说明生成文本向量。
10. 可选生成多模态融合向量。
11. 写入向量库和 `rag_vector_records`。

### 7.3 多模态向量配置

适用内容：

- 用户查询里带图片。
- 图片和文字一起查询。
- 需要“用图片找图片”“用文字找图片”“用图片找说明”的场景。

建议参数：

```json
{
  "modality": "multimodal",
  "distance_metric": "cosine",
  "query_modes": ["text_to_image", "image_to_image", "image_text_to_image", "text_to_text"],
  "top_k": 12,
  "score_threshold": 0.25,
  "fusion": {
    "text_weight": 0.55,
    "image_weight": 0.45
  }
}
```

## 8. 模型优化策略

### 8.1 文本模型调用 RAG

文本模型只接收文本上下文。检索结果需要被转换为可读引用块。

推荐注入格式：

```text
以下是知识库检索结果。回答时优先依据这些内容，并在必要时引用来源编号。

[K1] 标题：...
来源：document_id=doc_xxx, chunk_id=chk_xxx
内容：...

[K2] 标题：...
来源：...
内容：...
```

优化点：

- 对图片命中的结果，注入图片的 OCR、描述、详细说明，而不是把图片二进制传给文本模型。
- 对问答命中，优先注入 `question + answer`。
- 对表格命中，注入表格摘要和相关行。
- 上下文按模型 context length 动态裁剪。

### 8.2 多模态模型调用 RAG

多模态模型可以接收文本和图片。

优化点：

- 如果命中图片 chunk，且下游模型支持图片输入，可把图片 download URL 或内部可访问文件转为图片 content block。
- 同时注入图片说明，避免模型只看图造成上下文不稳定。
- 如果下游模型不支持图片，降级为文本描述。
- 对图片过多的结果做去重和 top-N 限制，避免请求过大。

### 8.3 图片生成模型调用 RAG

图片生成模型不能直接“回答知识库”，但可以用 RAG 优化 prompt。

适用场景：

- 根据品牌规范生成图。
- 根据产品资料生成图。
- 根据视觉参考生成图。

处理方式：

- 检索文本规范、图片描述、参考图标签。
- 构建增强后的 image prompt。
- 如果模型支持参考图，传入命中的参考图片。
- 如果模型不支持参考图，只传入结构化 prompt。

### 8.4 图片理解模型调用 RAG

用户上传图片提问时：

1. 对用户查询图片生成 query image embedding。
2. OCR 用户图片。
3. 生成用户图片短描述。
4. 同时做图片向量检索和文本向量检索。
5. 把命中图片、图片说明、相关文档文本注入多模态模型。

## 9. 上传导入能力

### 9.1 Excel 模板下载

新增接口：

```http
GET /v1/rag/import-templates
Authorization: Bearer tkh_xxx
```

响应：

```json
{
  "ok": true,
  "data": {
    "templates": [
      {
        "template_id": "tpl_text_v1",
        "name": "文本知识导入模板",
        "template_type": "text",
        "version": "1.0",
        "download_url": "/v1/rag/import-templates/tpl_text_v1/download"
      },
      {
        "template_id": "tpl_qa_v1",
        "name": "一问一答导入模板",
        "template_type": "qa",
        "version": "1.0",
        "download_url": "/v1/rag/import-templates/tpl_qa_v1/download"
      },
      {
        "template_id": "tpl_image_v1",
        "name": "图片知识导入模板",
        "template_type": "image",
        "version": "1.0",
        "download_url": "/v1/rag/import-templates/tpl_image_v1/download"
      }
    ]
  },
  "request_id": "req_xxx"
}
```

下载接口：

```http
GET /v1/rag/import-templates/{template_id}/download
```

模板文件要求：

- 第一行是固定表头，冻结首行。
- 必填列表头使用红色背景或红色标识。
- 可选列表头使用浅灰背景。
- 第二行给出示例数据。
- 第三行起由用户填写。
- `enabled`、`category`、`priority` 等字段使用下拉选项。
- 图片模板必须包含“图片填写方式”说明：内嵌图片、文件名、URL、base64。
- 说明 sheet 必须写明字段含义、示例、错误场景。
- 模板中不得要求用户填写内部 ID，例如 `user_id`、`api_key_id`。

### 9.2 外部 API 上传文档

新增接口：

```http
POST /v1/rag/knowledge-bases/{kb_id}/uploads
Authorization: Bearer tkh_xxx
Content-Type: application/json
```

JSON base64 请求：

```json
{
  "filename": "product-manual.xlsx",
  "content_type": "excel",
  "mime_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "content_base64": "base64...",
  "device_id": "client-device-001",
  "metadata": {
    "source": "crm",
    "business_id": "manual-2026-001"
  },
  "processing": {
    "auto_vectorize": false,
    "confirm_required": true,
    "template": "qa",
    "language": "zh-CN",
    "target_dataset_id": "ds_customer_service_qa"
  }
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "upload_id": "upl_xxx",
    "kb_id": "kb_xxx",
    "dataset_id": "ds_customer_service_qa",
    "status": "waiting_confirm",
    "preview_status": "ready",
    "vectorize_status": "not_started",
    "owner_user_id": 12,
    "department_id": 1,
    "api_key_id": 10,
    "summary": {
      "total_items": 128,
      "text_items": 120,
      "image_items": 8,
      "error_items": 0
    },
    "jobs": [
      {"job_id": "job_parse_xxx", "job_type": "parse", "status": "succeeded"}
    ]
  },
  "request_id": "req_xxx"
}
```

接口要求：

- 必须通过 `ApiKeyAuthMiddleware`。
- 必须检查 API Key 对知识库有 `write` 权限。
- 如果指定 `target_dataset_id`，必须检查 API Key 对数据集有 `write` 或 `admin` 权限。
- 必须记录 `owner_user_id`、`department_id`、`api_key_id`。
- 默认上传成功只代表解析预览完成或进入解析队列，不代表向量化完成。
- 默认 `confirm_required=true`，确认后才开始批量向量化。
- 管理员可为可信系统配置 `auto_vectorize=true`，但仍必须保留预览记录。
- 可通过状态接口查询处理进度。

### 9.3 上传预览分页

新增接口：

```http
GET /v1/rag/uploads/{upload_id}/preview?type=text&page=1&page_size=20
```

查询参数：

| 参数 | 说明 |
| --- | --- |
| `type` | `text`, `image`, `qa`, `error`, `all` |
| `page` | 页码，默认 1 |
| `page_size` | 每页数量，默认 20，最大 100 |
| `validation_status` | `valid`, `warning`, `error` |

文本预览响应：

```json
{
  "ok": true,
  "data": {
    "upload_id": "upl_xxx",
    "type": "text",
    "page": 1,
    "page_size": 20,
    "total": 120,
    "items": [
      {
        "preview_item_id": "pvi_xxx",
        "item_type": "qa",
        "title": "退款规则",
        "question": "什么情况下可以退款？",
        "answer": "订单未发货时可以申请退款。",
        "validation_status": "valid",
        "row_number": 2,
        "sheet_name": "QA"
      }
    ]
  },
  "request_id": "req_xxx"
}
```

图片预览响应：

```json
{
  "ok": true,
  "data": {
    "upload_id": "upl_xxx",
    "type": "image",
    "page": 1,
    "page_size": 20,
    "total": 8,
    "items": [
      {
        "preview_item_id": "pvi_img_xxx",
        "item_type": "image",
        "title": "设备 E03 故障图",
        "purpose": "故障识别",
        "description": "设备屏幕显示 E03。",
        "detailed_description": "红色指示灯亮起，屏幕显示 E03，可能表示温度传感器异常。",
        "thumbnail_url": "/v1/rag/images/img_xxx/thumbnail-url",
        "validation_status": "valid",
        "row_number": 2
      }
    ]
  },
  "request_id": "req_xxx"
}
```

预览展示要求：

- 后台页面必须分为“文本”和“图片”两个分类视图。
- 问答条目归入文本分类，但用 `qa` 标签标识。
- 每页默认 20 条，并显示总数、当前页、上一页、下一页。
- 错误条目单独可筛选，避免用户在大量内容中找不到问题。
- 图片预览必须显示缩略图、用途、描述、详细说明和校验状态。

### 9.4 确认批量向量化

用户确认预览无误后调用：

```http
POST /v1/rag/uploads/{upload_id}/confirm-vectorization
Authorization: Bearer tkh_xxx
Content-Type: application/json
```

请求：

```json
{
  "dataset_id": "ds_customer_service_qa",
  "include_warning_items": true,
  "exclude_preview_item_ids": ["pvi_bad_row_xxx"]
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "upload_id": "upl_xxx",
    "dataset_id": "ds_customer_service_qa",
    "status": "vectorizing",
    "vectorize_status": "queued",
    "total_items": 127,
    "jobs": [
      {"job_id": "job_embed_1", "job_type": "text_embedding", "status": "queued"},
      {"job_id": "job_image_1", "job_type": "image_embedding", "status": "queued"}
    ]
  },
  "request_id": "req_xxx"
}
```

确认规则：

- 只有 `preview_status=ready` 或 `preview_status=confirmed` 的上传可以确认。
- `validation_status=error` 的条目默认不可确认。
- 确认后写入 `confirmed_at`、`confirmed_by_user_id` 或 `confirmed_by_api_key_id`。
- 确认动作必须幂等，重复确认返回当前向量化状态。
- 确认后才生成正式 `rag_documents`、`rag_chunks` 和 embedding jobs。

### 9.5 上传状态和进度查询

```http
GET /v1/rag/uploads/{upload_id}
```

响应：

```json
{
  "ok": true,
  "data": {
    "upload_id": "upl_xxx",
    "status": "vectorizing",
    "preview_status": "confirmed",
    "vectorize_status": "processing",
    "current_stage": "image_embedding",
    "progress_percent": 67.5,
    "documents": 3,
    "chunks": 128,
    "vectors": 256,
    "failed_chunks": 0,
    "success_rate": 100.0,
    "created_at": "2026-07-09 12:00:00",
    "updated_at": "2026-07-09 12:01:35"
  },
  "request_id": "req_xxx"
}
```

进度条展示要求：

- 上传解析阶段显示：上传完成、解析中、预览生成中、等待确认。
- 向量化阶段显示：切片中、文本向量中、图片向量中、多模态向量中、索引发布中、完成。
- 进度百分比按条目数加权计算，不能只按 job 数计算。
- 如果图片处理耗时长，图片向量进度必须单独显示。
- 失败时展示失败阶段、失败数量、可重试入口。

### 9.6 批量导入 API

```http
POST /v1/rag/knowledge-bases/{kb_id}/imports
```

适合外部系统提交多个已解析条目：

```json
{
  "items": [
    {
      "type": "qa",
      "title": "退款规则",
      "question": "什么情况下可以退款？",
      "answer": "订单未发货时可以申请退款。",
      "metadata": {"category": "售后"}
    },
    {
      "type": "text",
      "title": "安装步骤",
      "content": "第一步..."
    }
  ],
  "target_dataset_id": "ds_customer_service_qa",
  "auto_vectorize": false,
  "confirm_required": true
}
```

## 10. Excel 导入

Excel 是重点导入格式，支持 `.xlsx`、`.xls`、`.csv`。

### 10.1 Excel 模板类型

支持三类模板：

| 模板 | 适用场景 | 必填列 |
| --- | --- | --- |
| `text` | 文档段落、制度、说明书 | `title`, `content` |
| `qa` | 一问一答、FAQ、客服知识 | `question`, `answer` |
| `image` | 图片资料、产品图、截图说明 | `image`, `purpose`, `description` |

### 10.2 Excel 模板样式规范

所有下载模板必须带样式，不允许只提供空白表头。

统一样式：

| 区域 | 样式 | 目的 |
| --- | --- | --- |
| 第 1 行表头 | 冻结、加粗、白字深色底 | 固定字段结构 |
| 必填列 | 红色或橙色表头，字段名后加 `*` | 明确必须填写 |
| 可选列 | 浅灰色表头 | 降低误填压力 |
| 示例行 | 浅蓝底 | 给用户直接参照 |
| 说明列 | 浅黄底 | 显示填写规则 |
| 错误提示列 | 预留但默认空 | 导入报告回填 |
| 下拉列 | 数据验证下拉 | 减少拼写错误 |

模板必须包含一个 `说明` sheet：

- 说明每一列的含义。
- 说明必填和可选字段。
- 说明图片列的四种填写方式。
- 说明单元格长度限制。
- 说明哪些列会进入向量检索。
- 说明上传后会先预览，确认后才批量向量化。

模板必须包含一个 `示例` sheet 或在主 sheet 第二行放示例：

- 文本模板提供 2 条不同分类示例。
- 问答模板提供标准问题、相似问法、答案示例。
- 图片模板提供图片 URL、图片文件名、用途、描述、详细说明示例。

模板字段保护：

- 表头和说明 sheet 建议锁定，避免用户改列名。
- 用户填写区不锁定。
- 不强制启用 Excel 密码保护，避免不同软件兼容问题。

模板下载后的用户流程：

1. 用户下载模板。
2. 按模板填写内容。
3. 上传 Excel。
4. 系统解析并展示预览。
5. 用户按文本/图片分类分页检查。
6. 用户确认后批量向量化。

### 10.3 文本模板

列定义：

| 列名 | 必填 | 说明 |
| --- | --- | --- |
| `title` | 是 | 标题 |
| `content` | 是 | 正文 |
| `category` | 否 | 分类 |
| `tags` | 否 | 逗号分隔标签 |
| `source_uri` | 否 | 来源 |
| `language` | 否 | 语言 |
| `enabled` | 否 | 是否启用 |

导入规则：

- 每行生成一个 `rag_documents` 或一个文档下的 `text` chunk，取决于导入配置。
- `content` 超过切片阈值时继续按段落切片。
- `category`、`tags` 写入 `metadata`。
- 上传后先进入文本预览列表，默认每页 20 条。
- 预览中显示标题、分类、标签、正文前 300 字、校验状态。

### 10.4 一问一答模板

列定义：

| 列名 | 必填 | 说明 |
| --- | --- | --- |
| `question` | 是 | 标准问题 |
| `answer` | 是 | 标准答案 |
| `similar_questions` | 否 | 相似问法，换行或 `|` 分隔 |
| `category` | 否 | 分类 |
| `tags` | 否 | 标签 |
| `source_uri` | 否 | 来源 |
| `priority` | 否 | 优先级 |
| `enabled` | 否 | 是否启用 |

向量化规则：

- 主向量文本：

```text
问题：{question}
相似问法：{similar_questions}
答案：{answer}
分类：{category}
标签：{tags}
```

- 检索展示时优先展示 `question` 和 `answer`。
- 相似问题不单独作为答案，但参与召回。
- 同一问题重复导入时按 `question + answer + category` hash 去重。
- 上传后先进入文本预览列表，带 `qa` 类型标签。
- 预览中必须同时显示问题、答案、相似问法和行级校验状态。

### 10.5 图片模板

列定义：

| 列名 | 必填 | 说明 |
| --- | --- | --- |
| `image` | 是 | 图片 URL、文件名、Excel 内嵌图片引用或 base64 |
| `title` | 否 | 图片标题 |
| `purpose` | 是 | 图片作用 |
| `description` | 是 | 图片描述 |
| `detailed_description` | 否 | 详细说明 |
| `tags` | 否 | 标签 |
| `category` | 否 | 分类 |
| `source_uri` | 否 | 来源 |
| `enabled` | 否 | 是否启用 |

图片导入规则：

- `image` 可以是：
  - Excel 内嵌图片。
  - ZIP 包内的相对文件名。
  - 外部 URL。
  - base64 字符串。
- `purpose`、`description`、`detailed_description` 都进入图片语义文本。
- 如果 `detailed_description` 为空，系统可调用多模态模型补全。
- OCR 结果追加到 `ocr_text`。
- 图片生成至少两个向量：
  - 图片原始向量：用于图片找图片。
  - 图片语义文本向量：用于文本找图片。
- 如果配置了多模态融合模型，再生成第三个多模态向量。
- 上传后先进入图片预览列表，默认每页 20 张。
- 预览中必须显示缩略图、图片作用、图片描述、详细说明、标签、校验状态。
- 用户确认前可以剔除识别错误或描述不完整的图片条目。

### 10.6 Excel 错误处理

常见错误码：

| 错误码 | 场景 |
| --- | --- |
| `RAG_EXCEL_EMPTY` | Excel 没有有效数据 |
| `RAG_EXCEL_MISSING_COLUMN` | 缺少必填列 |
| `RAG_EXCEL_INVALID_IMAGE` | 图片列无法解析 |
| `RAG_EXCEL_ROW_TOO_LARGE` | 单行内容过大 |
| `RAG_EXCEL_PARTIAL_FAILED` | 部分行失败 |

导入要求：

- 单行失败不影响整表导入，除非失败率超过配置阈值。
- 每行错误写入导入报告。
- 上传状态可以是 `partial_failed`，并返回成功行数、失败行数。

## 11. 文本、问答、图片的统一 chunk 设计

### 11.1 普通文本

chunk 内容：

```json
{
  "chunk_type": "text",
  "title": "标题",
  "content": "正文切片",
  "metadata": {
    "section": "2.1",
    "page": 3,
    "tags": ["售后", "退款"]
  }
}
```

### 11.2 一问一答

chunk 内容：

```json
{
  "chunk_type": "qa",
  "question": "什么情况下可以退款？",
  "answer": "订单未发货时可以申请退款。",
  "content": "问题：什么情况下可以退款？\n答案：订单未发货时可以申请退款。",
  "metadata": {
    "similar_questions": ["订单没发货能退吗", "未发货退款规则"],
    "category": "售后"
  }
}
```

### 11.3 图片

chunk 内容：

```json
{
  "chunk_type": "image",
  "title": "设备故障照片",
  "content": "图片作用：故障识别\n图片描述：设备面板显示 E03 错误。\n详细说明：红色指示灯亮起，屏幕显示 E03，通常表示温度传感器异常。\nOCR：E03 TEMP SENSOR",
  "image_asset_id": 1001,
  "metadata": {
    "purpose": "故障识别",
    "labels": ["设备", "E03", "传感器"],
    "width": 1280,
    "height": 720
  }
}
```

## 12. 自动向量化流水线

### 12.1 状态流转

```text
uploaded
  -> previewing
  -> waiting_confirm
  -> vectorizing
  -> indexed
```

失败分支：

```text
previewing -> partial_failed
previewing -> failed
vectorizing -> retrying -> vectorizing
vectorizing -> partial_failed
vectorizing -> failed
```

### 12.2 文档导入流程

1. 上传接口校验 API Key、知识库写权限和目标数据集权限。
2. 保存原始文件。
3. 写入 `rag_uploads`。
4. 创建 `parse` job。
5. Worker 解析文件。
6. 解析结果写入 `rag_upload_preview_items`。
7. 更新 `preview_status=ready`、`status=waiting_confirm`。
8. 用户或外部系统分页查看文本和图片预览。
9. 用户确认批量向量化。
10. 写入 `rag_documents`。
11. 生成 `rag_chunks`。
12. 按 chunk 类型创建 embedding jobs。
13. 调用 embedding 模型。
14. 写入向量库。
15. 写入 `rag_vector_records`。
16. 更新 upload/document/dataset/index 状态。

### 12.3 预览确认流程

预览确认是强制产品流程，除非管理员对可信 API Key 显式开启 `auto_vectorize=true`。

```text
parse result
  -> preview_items
  -> text tab / image tab
  -> page size 20
  -> user review
  -> confirm-vectorization
  -> chunks and vectors
```

预览确认要求：

- 文本和图片必须分开展示。
- 文本下继续区分普通文本和问答。
- 图片必须展示缩略图、用途、描述、详细说明。
- 所有错误条目必须可筛选。
- `error` 条目不能进入确认向量化。
- 用户确认时可以排除部分条目。
- 确认后预览内容不可直接修改；如需修改，应重新上传或创建新版本。

### 12.4 图片处理流程

1. 解码图片或下载图片。
2. 校验 MIME、大小、像素。
3. 计算 sha256 去重。
4. 保存原图和缩略图。
5. 写入 `rag_image_assets`。
6. OCR。
7. 多模态 caption。
8. 生成用途、描述、详细说明、标签。
9. 生成图片 chunk。
10. 生成图片向量。
11. 生成文本向量。
12. 可选生成多模态向量。
13. 发布索引。

### 12.5 进度条计算

进度条按阶段和条目数加权：

| 阶段 | 权重 | 说明 |
| --- | --- | --- |
| 上传保存 | 5% | 文件接收和落盘 |
| 文件解析 | 15% | Excel/PDF/Word/图片解析 |
| 预览生成 | 10% | 生成预览条目和校验 |
| 等待确认 | 不自动增长 | 用户检查阶段 |
| 切片生成 | 10% | 生成正式 chunk |
| 文本向量 | 20% | 文本、问答、图片语义文本 |
| 图片处理 | 15% | OCR、caption、标签 |
| 图片向量 | 15% | 图片原始向量、多模态向量 |
| 索引发布 | 10% | 向量 upsert 和数据集计数 |

规则：

- 如果上传内容没有图片，图片处理和图片向量权重分配给文本向量和索引发布。
- 如果上传内容只有图片，文本向量仍保留图片描述/OCR 的语义文本向量进度。
- 进度不得在 `waiting_confirm` 阶段自动到 100%，必须停在“等待确认”。
- 确认后重新进入向量化阶段进度。

### 12.6 重试策略

| 任务 | 最大重试 | 退避 |
| --- | --- | --- |
| 文件解析 | 3 | 30s, 2m, 10m |
| OCR | 3 | 30s, 2m, 10m |
| caption | 3 | 30s, 2m, 10m |
| embedding | 5 | 10s, 30s, 2m, 5m, 15m |
| vector upsert | 5 | 10s, 30s, 2m, 5m, 15m |

可重试错误：

- 上游超时。
- 429 限流。
- 5xx。
- 向量库短暂不可用。
- Worker 锁超时。

不可重试错误：

- 文件格式不支持。
- 文件损坏。
- 必填列缺失。
- 图片无法解码。
- API Key 无权限。

## 13. 95% 以上成功率保障

### 13.1 成功率定义

本方案的“调用成功率 95% 以上”拆成三个指标：

1. 上传受理成功率：有效请求返回 `queued` 的比例。
2. 向量化完成成功率：有效 chunk 在 SLA 内完成向量索引的比例。
3. RAG 检索调用成功率：带 RAG 的模型请求没有因为 RAG 子系统失败而整体失败的比例。

建议生产验收阈值：

| 指标 | 目标 |
| --- | --- |
| 上传受理成功率 | >= 99% |
| 有效 chunk 向量化成功率 | >= 95% |
| RAG 检索子流程成功率 | >= 95% |
| RAG 失败导致模型调用失败比例 | <= 1% |

### 13.2 成功率保障机制

必须实现：

- 异步 job，不在上传请求内做长耗时向量化。
- job 幂等，按 `content_hash + vector_config_id` 去重。
- embedding 批处理，降低上游调用次数。
- embedding 失败可重试。
- 向量库 upsert 可重试。
- 向量库写入成功后再更新 `rag_vector_records`。
- Worker 锁超时自动释放。
- 失败 chunk 不阻塞同批其他 chunk。
- RAG 检索失败时模型调用可降级为非 RAG 调用，并记录 `fallback_used=true`。
- 低分召回时可使用关键词 fallback。
- 大文件按分片任务处理，避免单任务失败导致整文件失败。

### 13.3 降级策略

检索阶段：

- 向量库不可用：使用 MySQL 关键词/BM25 fallback。
- 图片向量不可用：使用图片 OCR 和描述文本检索。
- 多模态向量不可用：退回文本向量和图片描述。
- rerank 失败：使用原始向量分数排序。

生成阶段：

- RAG 检索失败但用户未要求强制 RAG：继续普通模型调用。
- 用户设置 `rag.required=true`：RAG 失败时返回 `RAG_RETRIEVAL_FAILED`。
- 命中为空：继续调用模型，但注入“未检索到可用知识库内容”的系统上下文，避免假引用。

### 13.4 可观测性

新增指标：

```text
rag_upload_total
rag_upload_failed_total
rag_parse_job_total
rag_embedding_job_total
rag_embedding_job_failed_total
rag_vector_upsert_total
rag_vector_upsert_failed_total
rag_retrieval_total
rag_retrieval_failed_total
rag_retrieval_fallback_total
rag_indexed_chunk_total
rag_failed_chunk_total
```

后台展示：

- 今日上传量。
- 待处理任务数。
- 向量化成功率。
- 图片处理成功率。
- Excel 行级失败率。
- 平均向量化耗时。
- 向量库健康状态。
- 各 embedding 模型错误率。

## 14. RAG 调用接口

### 14.1 显式 RAG 调用

外部继续调用现有模型接口，只增加 `rag` 参数。

```http
POST /v1/chat/completions
```

```json
{
  "model": "deepseek-v4-flash",
  "messages": [
    {"role": "user", "content": "根据知识库说明退款流程"}
  ],
  "rag": {
    "enabled": true,
    "dataset_ids": ["ds_customer_service_qa"],
    "top_k": 8,
    "include_sources": true,
    "required": false
  }
}
```

### 14.2 默认知识库调用

管理员可把 API Key 绑定默认向量数据集。外部请求可以只传：

```json
{
  "model": "deepseek-v4-flash",
  "messages": [
    {"role": "user", "content": "退款流程是什么？"}
  ],
  "rag": {
    "enabled": true
  }
}
```

规则：

- 如果 `dataset_ids` 为空，使用 API Key 默认向量数据集。
- 如果 `dataset_ids` 非空，必须逐个校验 API Key 对数据集有 `read` 权限。
- 如果只传 `knowledge_base_ids`，系统可解析该知识库下该 API Key 可读的默认数据集；多个数据集冲突时返回错误，要求调用方改用 `dataset_ids`。
- 如果没有默认数据集，RAG 不生效。
- 如果 `rag.required=true` 且没有可用数据集，返回错误。
- 数据集名称 `dataset_names` 可用于人工友好的调用，但生产系统推荐使用稳定 `dataset_ids`。

### 14.3 按数据集名称调用

```json
{
  "model": "deepseek-v4-flash",
  "messages": [
    {"role": "user", "content": "退款流程是什么？"}
  ],
  "rag": {
    "enabled": true,
    "dataset_names": ["客服问答知识库-正式数据集"],
    "top_k": 8
  }
}
```

名称解析规则：

- 名称只在 API Key 可读的数据集范围内解析。
- 名称唯一时解析为对应 `dataset_id`。
- 名称重复时返回 `RAG_DATASET_NAME_CONFLICT`。
- 名称不存在时返回 `RAG_DATASET_NOT_FOUND`。

### 14.4 图片 RAG 查询

OpenAI 兼容多模态请求：

```json
{
  "model": "vision-model",
  "messages": [
    {
      "role": "user",
      "content": [
        {"type": "text", "text": "这张图对应哪个故障处理方案？"},
        {"type": "image_url", "image_url": {"url": "data:image/png;base64,..."}}
      ]
    }
  ],
  "rag": {
    "enabled": true,
    "dataset_ids": ["ds_device_images"],
    "query_modality": "multimodal",
    "include_image_hits": true
  }
}
```

处理：

- 对用户图片生成 query image embedding。
- OCR 用户图片。
- 文本 query 和图片 query 同时检索。
- 命中图片时，如果下游模型支持图片，则传入参考图片；否则传入图片描述。

## 15. 返回来源引用

当 `include_sources=true` 时，模型响应外层增加 RAG 元数据。

OpenAI chat 响应可扩展：

```json
{
  "id": "chatcmpl_xxx",
  "object": "chat.completion",
  "choices": [],
  "usage": {},
  "rag": {
    "retrieval_id": "rtr_xxx",
    "knowledge_base_ids": ["kb_customer_service"],
    "dataset_ids": ["ds_customer_service_qa"],
    "sources": [
      {
        "source_id": "src_1",
        "dataset_id": "ds_customer_service_qa",
        "dataset_name": "客服问答知识库-正式数据集",
        "document_id": "doc_xxx",
        "chunk_id": "chk_xxx",
        "title": "退款规则",
        "score": 0.82,
        "content_preview": "订单未发货时可以申请退款..."
      }
    ]
  }
}
```

如果要完全兼容上游格式，可通过响应 header 返回：

```http
X-TokenHub-Rag-Retrieval-Id: rtr_xxx
```

并提供：

```http
GET /v1/rag/retrievals/{retrieval_id}
```

## 16. RAG 后处理与纠偏闭环

RAG 不是“检索一次就结束”。每次通过 RAG 后，都必须留下可追踪的证据链，便于发现旧知识、错知识和低质量召回。

### 16.1 RAG 调用后必须记录什么

每次启用 RAG 的模型调用，至少记录：

- 请求 ID。
- 使用的 API Key、用户、部门。
- 使用的数据集 ID 和知识库 ID。
- 用户查询摘要。
- 命中的 document/chunk/image。
- 每个命中的 score、排序、召回方式。
- 是否使用 fallback。
- 注入模型的上下文摘要。
- 模型响应是否返回来源引用。

这些信息写入 `rag_retrieval_logs`，用于后续排查：

- 为什么回答引用了某条旧数据。
- 为什么没有命中正确数据。
- 哪个数据集召回质量差。
- 哪些 chunk 经常被命中但反馈为错误。

### 16.2 用户反馈接口

新增接口：

```http
POST /v1/rag/feedback
Authorization: Bearer tkh_xxx
Content-Type: application/json
```

请求：

```json
{
  "request_id": "req_xxx",
  "retrieval_id": "rtr_xxx",
  "rating": "outdated",
  "source_chunk_ids": ["chk_old_policy"],
  "comment": "这条退款规则已经在 2026-07-01 作废。",
  "suggested_answer": "新规则是订单发货前均可退款，发货后按售后流程处理。"
}
```

响应：

```json
{
  "ok": true,
  "data": {
    "feedback_id": "fb_xxx",
    "status": "open",
    "correction_task_id": "corr_xxx"
  },
  "request_id": "req_xxx"
}
```

反馈类型：

| `rating` | 含义 | 后续动作 |
| --- | --- | --- |
| `good` | 回答正确 | 用于质量统计 |
| `bad` | 回答错误 | 生成纠偏任务 |
| `outdated` | 命中过期数据 | 标记旧 chunk，等待下架或替换 |
| `wrong_source` | 来源不正确 | 检查切片和向量质量 |
| `missing_context` | 没召回关键知识 | 触发补充知识或重建索引 |

### 16.3 管理员纠偏流程

后台需要提供“纠偏中心”：

```text
纠偏中心
  - 待处理反馈
  - 过期数据
  - 错误命中
  - 待重建索引
  - 删除/下架记录
```

管理员处理方式：

- 修改原文并创建新版本。
- 上传替换文档。
- 禁用某个 chunk。
- 删除某个文档。
- 调整数据集检索阈值或 rerank 策略。
- 重建整个数据集索引。

纠偏原则：

- 不直接覆盖旧 chunk 内容，优先创建新版本。
- 旧 chunk 标记为 `corrected` 或 `superseded`。
- 旧向量必须标记 `stale`，并从向量库删除或禁用。
- 新内容确认后重新向量化。
- 纠偏操作必须写审计日志。

### 16.4 数据过期策略

数据过期后有四种处理方式：

| 策略 | 行为 | 适用场景 |
| --- | --- | --- |
| `mark_expired` | 只标记过期，检索时降低权重或提示 | 需要人工复核 |
| `disable_retrieval` | 禁止再被检索，但保留数据 | 政策、价格、流程过期 |
| `delete_vectors` | 删除向量点，保留原文和审计 | 不希望再被召回 |
| `delete_document` | 软删除文档和 chunk，并删除向量 | 用户要求删除或数据合规清理 |

推荐默认策略：

- 到期先 `mark_expired`。
- 如果 7 天内未确认延期或替换，自动 `disable_retrieval`。
- 管理员确认后执行 `delete_vectors` 或 `delete_document`。

### 16.5 删除与下架流程

删除不能只删 MySQL 元数据，还必须处理向量库。

单个 chunk 删除：

```text
disable chunk
  -> rag_chunks.lifecycle_status=deleted
  -> rag_vector_records.index_status=deleted
  -> vector store delete point
  -> retrieval cache invalidate
```

文档删除：

```text
soft delete document
  -> disable all chunks
  -> delete all vector points
  -> update dataset vector_count
  -> write audit log
```

数据集删除：

```text
disable dataset
  -> reject new retrieval
  -> delete or disable collection points
  -> keep upload/document audit records
```

注意：

- 默认使用软删除，保留审计。
- 硬删除只用于合规删除或明确要求清除原始内容。
- 删除向量必须可重试，避免 MySQL 已删除但向量库仍残留。
- 删除完成后要抽样检索验证，确认旧内容不会再被召回。

### 16.6 替换和版本纠偏

如果数据不是要删除，而是被新版本替代：

1. 上传新文件或新 Excel。
2. 生成预览。
3. 管理员确认。
4. 新版本向量化。
5. 将旧文档 `superseded_by_document_id` 指向新文档。
6. 旧 chunk 标记 `superseded`。
7. 删除或禁用旧向量。
8. 数据集计数和检索缓存刷新。

检索时默认只召回：

```text
lifecycle_status=active
enabled=true
expires_at is null or expires_at > now()
```

如果管理员开启“包含过期数据调试模式”，只能在后台检索页使用，不能对外部 API 默认启用。

### 16.7 缓存和召回纠偏

如果系统做了检索缓存，纠偏或删除后必须失效：

- `retrieval:{dataset_id}:{query_hash}`。
- `chunk:{chunk_id}`。
- `dataset:{dataset_id}:stats`。
- 相关 API Key 的默认数据集缓存。

缓存失效必须在以下动作后触发：

- chunk 禁用。
- 文档删除。
- 数据集禁用。
- 新版本替换。
- 向量配置变更。
- rerank 或 score threshold 调整。

## 17. 权限与安全

必须满足：

- 上传 API 只使用 API Key。
- 上传记录必须写入 `owner_user_id`、`department_id`、`api_key_id`。
- API Key 只能写入绑定的知识库。
- API Key 只能检索绑定的知识库。
- API Key 只能写入和检索绑定的向量数据集。
- 知识库权限和数据集权限必须同时满足，任一侧无权限都拒绝。
- 管理员后台可按权限查看知识库、上传记录、失败任务。
- 原始文件下载需要短期签名 URL。
- 删除知识库必须软删除元数据并删除或禁用向量点。
- 日志不记录 API Key 明文、文件完整内容、图片 base64。
- 图片 EXIF 默认不完整暴露，避免泄露地理位置。

## 18. 后台管理页面

建议新增 Admin 菜单：

```text
知识库
  - 知识库列表
  - 向量数据集
  - API Key 数据集授权
  - 文档上传
  - 模板下载
  - Excel 导入
  - 上传预览
  - 图片库
  - 向量配置
  - 处理任务
  - 检索日志
  - 纠偏中心
  - 数据过期策略
```

页面能力：

- 创建知识库。
- 创建向量数据集，设置数据集名称、类型、向量配置和 collection。
- 绑定 API Key、用户、部门到指定数据集。
- 配置文本/图片/多模态向量模型。
- 下载文本、问答、图片 Excel 模板。
- 上传文档和 Excel。
- 上传后先查看解析预览，按文本和图片分类分页展示。
- 内容较多时每页 20 条，支持按校验状态筛选。
- 确认预览无误后点击“批量向量化”。
- 展示上传、解析、预览、向量化、索引发布进度条。
- 查看 chunk 列表。
- 查看图片 OCR、描述、详细说明。
- 手动重试失败任务。
- 手动重建索引。
- 查看向量化成功率和失败原因。
- 查看 RAG 调用后的来源命中、反馈和纠偏任务。
- 配置数据过期策略。
- 对过期、错误、替换的数据执行下架、删除向量、重建索引。

## 19. 错误码

| 错误码 | HTTP | 说明 |
| --- | --- | --- |
| `RAG_KB_NOT_FOUND` | 404 | 知识库不存在 |
| `RAG_KB_FORBIDDEN` | 403 | 无知识库权限 |
| `RAG_DATASET_NOT_FOUND` | 404 | 向量数据集不存在 |
| `RAG_DATASET_FORBIDDEN` | 403 | 无数据集权限 |
| `RAG_DATASET_NAME_CONFLICT` | 409 | 数据集名称重复，必须使用 dataset_id |
| `RAG_TEMPLATE_NOT_FOUND` | 404 | 导入模板不存在 |
| `RAG_UPLOAD_TOO_LARGE` | 413 | 上传过大 |
| `RAG_UNSUPPORTED_FILE_TYPE` | 400 | 文件类型不支持 |
| `RAG_INVALID_PAYLOAD` | 400 | 请求格式错误 |
| `RAG_EXCEL_MISSING_COLUMN` | 400 | Excel 缺少必填列 |
| `RAG_IMAGE_INVALID` | 400 | 图片无效 |
| `RAG_PREVIEW_NOT_READY` | 409 | 上传预览尚未生成完成 |
| `RAG_CONFIRM_REQUIRED` | 409 | 需要先确认预览再向量化 |
| `RAG_PREVIEW_HAS_ERRORS` | 422 | 预览中存在不可向量化错误条目 |
| `RAG_FEEDBACK_INVALID` | 400 | RAG 反馈参数无效 |
| `RAG_CORRECTION_NOT_FOUND` | 404 | 纠偏任务不存在 |
| `RAG_DELETE_IN_PROGRESS` | 409 | 删除或下架任务正在执行 |
| `RAG_EXPIRED_CONTENT_DISABLED` | 410 | 内容已过期且禁止检索 |
| `RAG_VECTOR_CONFIG_INVALID` | 400 | 向量配置无效 |
| `RAG_EMBEDDING_FAILED` | 502 | 向量模型调用失败 |
| `RAG_VECTOR_STORE_FAILED` | 502 | 向量库存取失败 |
| `RAG_RETRIEVAL_FAILED` | 502 | RAG 检索失败 |
| `RAG_REQUIRED_NO_CONTEXT` | 424 | 强制 RAG 但无可用上下文 |

## 20. 实施阶段拆分

### 阶段 1：基础库和上传导入

- 新增 RAG 数据库迁移。
- 新增知识库表、向量数据集表、数据集绑定表、上传表、预览表、文档表、chunk 表。
- 新增 Excel 模板表和模板下载接口。
- 新增外部上传 API。
- 支持 text、qa JSON 批量导入。
- 支持上传状态查询。

### 阶段 2：Excel 导入

- 支持 `.xlsx`、`.xls`、`.csv`。
- 支持 text、qa、image 三类模板。
- 模板必须带样式、示例、说明 sheet、必填列标识、下拉选项。
- 支持行级错误报告。
- 支持 Excel 内嵌图片和 ZIP 图片相对路径。
- 支持上传后预览，不直接向量化。
- 支持文本和图片分类分页预览，每页默认 20 条。

### 阶段 3：文本向量

- 新增文本向量配置。
- 支持预览确认后批量向量化。
- 新增 embedding job。
- 接入 `/v1/embeddings` 或 ProviderHttpClient。
- 写入向量库。
- 支持文本检索。

### 阶段 4：图片向量

- 新增图片资产表。
- 支持图片上传。
- 支持 OCR。
- 支持图片描述、用途、详细说明。
- 支持图片原始向量和图片语义文本向量。
- 支持文字找图、图找图。

### 阶段 5：模型网关 RAG 接入

- 在 `GatewayService` 解析请求后加入 `RagRetrievalService`。
- 支持 `rag.enabled`、`dataset_ids`、`dataset_names`、`knowledge_base_ids`、`top_k`、`required`。
- 支持不同 API Key 绑定不同默认数据集。
- 支持 OpenAI chat、responses、Anthropic messages。
- 支持文本模型、多模态模型、图片模型的不同上下文注入。

### 阶段 6：成功率和运维

- 增加 job 重试、死信、手动重试。
- 增加向量库健康检查。
- 增加成功率指标。
- 增加检索日志。
- 增加后台任务面板。

### 阶段 7：RAG 后处理、纠偏和删除

- 增加 RAG 反馈接口。
- 增加 `rag_answer_feedback`、`rag_correction_tasks`、`rag_retention_policies`。
- 支持管理员纠偏中心。
- 支持 chunk 禁用、文档替换、文档删除、向量删除。
- 支持过期数据自动标记和下架。
- 删除或纠偏后清理检索缓存。
- 删除后抽样检索验证旧内容不会再被召回。

## 21. 验收标准

功能验收：

- 管理员能创建知识库并绑定 API Key。
- 外部 API 能上传文本、问答、Excel、图片。
- 上传记录能准确记录上传人、部门、API Key。
- 管理员能创建向量数据集并绑定不同 API Key。
- 不同 API Key 只能调用自己绑定的数据集。
- Excel 模板可下载，包含样式、示例、说明、必填标识和下拉选项。
- 上传后先展示预览，不会默认直接向量化。
- 预览按文本和图片分类展示，内容较多时每页 20 条。
- 用户确认预览后才创建批量向量化任务。
- 上传和向量化过程显示阶段状态和进度条。
- Excel text 模板能导入为文本 chunk。
- Excel qa 模板能导入为问答 chunk。
- Excel image 模板能导入图片、用途、描述、详细说明。
- 图片能生成 OCR 文本、短描述、详细说明、图片向量、文本向量。
- RAG 调用能从知识库召回上下文并注入模型请求。
- RAG 响应能返回来源引用或 retrieval_id。
- RAG 调用后能提交 `good`、`bad`、`outdated`、`wrong_source`、`missing_context` 反馈。
- 管理员能把错误反馈转成纠偏任务。
- 管理员能禁用单个 chunk，并确认该 chunk 不再被召回。
- 管理员能用新文档替换旧文档，旧文档状态变为 `superseded`。
- 过期数据能自动标记，并按策略下架或删除向量。
- 删除文档后，MySQL 元数据、向量库 point 和检索缓存状态一致。

成功率验收：

- 1000 条有效文本 chunk 中，至少 950 条在 SLA 内完成向量索引。
- 1000 条有效问答 chunk 中，至少 950 条在 SLA 内完成向量索引。
- 1000 张有效图片中，至少 950 张完成图片向量或可降级的图片语义文本向量。
- 向量库短暂不可用时，任务自动重试，不丢失上传记录。
- RAG 检索失败时，非强制 RAG 请求不应导致模型调用失败。

安全验收：

- API Key 明文不进入日志。
- 图片 base64 不进入日志。
- 原始文件路径不直接暴露给外部。
- API Key 不能访问未绑定知识库。
- API Key 不能访问未绑定向量数据集。
- 删除知识库后，向量点不可再被检索。
- 数据过期、纠偏、删除必须写审计日志。
- 过期或删除后的数据不能被外部 API 默认召回。

性能验收：

- 小文件上传接口 95 分位响应小于 1 秒。
- 上传接口不等待向量化完成。
- 单个 10 MB Excel 文件能进入异步处理。
- 检索 95 分位耗时小于 500 ms，不含模型生成时间。

## 22. 与当前 TokenHub 的接入点

当前项目已有模型网关：

- `POST /v1/chat/completions`
- `POST /v1/responses`
- `POST /v1/embeddings`
- `POST /v1/messages`

建议新增服务：

- `RagKnowledgeBaseService`
- `RagUploadService`
- `RagExcelImportService`
- `RagImageProcessingService`
- `RagChunkingService`
- `RagEmbeddingService`
- `RagVectorStoreInterface`
- `QdrantVectorStore`
- `RagRetrievalService`
- `RagPromptInjector`
- `RagUsageRecorder`
- `RagFeedbackService`
- `RagCorrectionService`
- `RagRetentionPolicyService`
- `RagVectorDeletionService`

建议新增控制器：

- `RagController`
- `RagUploadController`
- `RagAdminController`
- `RagFeedbackController`
- `RagCorrectionController`

建议在网关链路中的位置：

```text
GatewayService::handle
  -> parse request
  -> model route and quota preflight
  -> desensitization
  -> RagRetrievalService
  -> RagPromptInjector
  -> ProviderHttpClient
  -> usage record
```

注意：

- RAG 上传、状态查询、知识库列表使用普通 API Key 鉴权即可。
- 模型调用仍使用 `GatewayAuthMiddleware`，继续校验模型权限。
- RAG 权限必须独立于模型权限校验，避免“能调模型”自动等于“能读所有知识库”。
