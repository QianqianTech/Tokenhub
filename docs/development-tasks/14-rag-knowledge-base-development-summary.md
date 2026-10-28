# 14 RAG 知识库一二三期开发总结

## 1. 总结

TokenHub RAG 项目已经按三期目标完成代码落地，并追加完成后台 Web 操作闭环。

整体目标是把知识库从单纯的文档存储，扩展为可上传、可预览、可审核、可向量化、可检索注入、可反馈纠偏、可过期删除、可监控成功率的生产型 RAG 子系统。

当前实现保持以下边界：

- 外部普通用户继续只走 API Key，不新增普通用户 Web 登录。
- 后台管理能力走 Admin 会话和 CSRF。
- Qdrant 作为独立主向量库，保留测试/小规模降级路径。
- 图片批量导入主路径使用 `ZIP + manifest.xlsx/csv`，不以 Excel 内嵌图片作为主路径。
- AI 图片理解、图片向量、多模态向量均通过后台/数据库配置选择模型，不硬编码某一家模型。

## 2. 一期：RAG 基础闭环

一期目标是建立完整 RAG 数据底座和外部 API 调用链路。

### 2.1 数据库与资源

已新增并接入 RAG 相关表：

- `rag_knowledge_bases`
- `rag_vector_datasets`
- `rag_dataset_bindings`
- `rag_vector_configs`
- `rag_import_templates`
- `rag_uploads`
- `rag_upload_preview_items`
- `rag_documents`
- `rag_chunks`
- `rag_image_assets`
- `rag_embedding_jobs`
- `rag_vector_records`
- `rag_retrieval_logs`
- `rag_answer_feedback`
- `rag_correction_tasks`
- `rag_retention_policies`

后台通用资源已映射这些表，支持知识库、数据集、配置、任务、反馈、纠偏、过期策略等基础 CRUD/查看。

### 2.2 权限与数据集绑定

实现 `RagPermissionService`：

- API Key 可绑定知识库和向量数据集。
- 支持 `read`、`write`、`admin` 权限等级。
- 请求无 `dataset_ids` 时解析默认数据集。
- 支持通过 `dataset_names` 解析数据集。
- 重名数据集返回 `RAG_DATASET_NAME_CONFLICT`。
- 知识库和数据集任一侧无权限都会拒绝访问。

### 2.3 模板下载

实现 `RagTemplateService`：

- 文本模板。
- 问答模板。
- 图片模板。
- 图片包 manifest 模板。

模板支持：

- 必填列标识。
- 示例行。
- 说明 sheet。
- 样式和冻结表头。
- 下拉项。

外部 API：

- `GET /v1/rag/import-templates`
- `GET /v1/rag/import-templates/{template_id}/download`

### 2.4 上传、预览、确认

实现 `RagUploadService`：

- 外部 API 上传文件。
- 记录上传人、部门、API Key、设备、文件 hash、目标数据集。
- 上传后先解析到 `rag_upload_preview_items`。
- 默认等待确认，不直接向量化。
- 预览分页默认每页 20 条。
- 支持文本、图片、错误行分类查看。
- 错误行阻断确认向量化。

外部 API：

- `POST /v1/rag/knowledge-bases/{kb_id}/uploads`
- `GET /v1/rag/uploads/{upload_id}`
- `GET /v1/rag/uploads/{upload_id}/preview`
- `POST /v1/rag/uploads/{upload_id}/confirm-vectorization`

### 2.5 Excel / 文本 / 问答 / 图片解析

实现 `RagExcelImportService`：

- 文本模板解析为文本预览条目。
- 问答模板解析为 `question`、`answer`、`similar_questions`。
- 图片模板解析用途、描述、详细说明、标签等字段。
- 行级校验写入 `validation_status` 和 `validation_errors`。
- 缺必填列返回 `RAG_EXCEL_MISSING_COLUMN`。
- 单行错误只影响该行。

### 2.6 图片资产处理

实现 `RagImageProcessingService`：

- 保存图片原图。
- 生成缩略图。
- 提取安全元数据。
- 清理危险 EXIF。
- 写入 `rag_image_assets`。
- 无多模态模型时保留 warning 降级。

### 2.7 切片与向量化

实现：

- `RagChunkingService`
- `RagEmbeddingJobService`

确认后流程：

1. 预览条目转为 `rag_documents`。
2. 文档生成 `rag_chunks`。
3. 创建向量化任务。
4. 写入 `rag_vector_records`。
5. 发布到向量存储。

进度阶段覆盖：

- 解析。
- 预览。
- 等待确认。
- 切片。
- 文本向量。
- 图片处理。
- 图片向量。
- 索引发布。

### 2.8 向量存储

实现：

- `RagVectorStoreInterface`
- `QdrantVectorStore`
- `MysqlFallbackVectorStore`
- `RagVectorConfigService`

Qdrant 已加入部署编排：

- `qdrant/qdrant`
- `tokenhub-qdrant`
- `qdrant_data`
- 默认端口映射 `63330:6333`
- PHP 和 queue worker 均配置 `QDRANT_URL`

### 2.9 检索与网关注入

实现：

- `RagRetrievalService`
- `RagPromptInjector`
- `GatewayService` RAG 注入逻辑

能力：

- 支持 `dataset_ids`。
- 支持 `dataset_names`。
- 支持默认数据集。
- 支持 `top_k`。
- 支持 `required`。
- `required=false` 时检索失败降级普通模型调用。
- `required=true` 时返回 `RAG_RETRIEVAL_FAILED`。
- 命中来源写入 `rag_retrieval_logs`。

### 2.10 来源引用、反馈和过期删除

实现：

- RAG 响应返回 `rag.retrieval_id`。
- RAG 响应返回 `rag.sources`。
- `GET /v1/rag/retrievals/{retrieval_id}`。
- `POST /v1/rag/feedback`。
- `RagFeedbackService`。
- `RagCorrectionService`。
- `RagRetentionPolicyService`。
- `RagVectorDeletionService`。

支持：

- 好评。
- 差评。
- 过期。
- 来源错误。
- 缺上下文。
- 错误/过期反馈自动生成纠偏任务。
- 单 chunk 禁用。
- 整文档软删除。
- 数据集禁用。
- 删除向量后不再召回。

## 3. 二期：图片批量包导入与标注闭环

二期目标是把批量图片知识库从 Excel 内嵌图片方案升级为稳定的图片包导入方案。

### 3.1 ZIP 图片包导入

主路径为：

```text
manifest.xlsx/csv + images/
```

约束：

- 根目录必须有唯一 `manifest.xlsx` 或 `manifest.csv`。
- 图片放在 `images/` 或 manifest 声明的相对路径中。
- 禁止绝对路径。
- 禁止 `..` 路径穿越。
- 禁止隐藏脚本文件。
- 禁止非图片伪装文件。
- 支持 `jpg`、`jpeg`、`png`、`webp`。
- 默认 ZIP 上限 100MB，通过系统设置管理。

实现：

- `RagPackageImportService`
- `processing.package_mode=manifest_zip`
- `template=image_package`

新增错误码：

- `RAG_PACKAGE_INVALID`
- `RAG_PACKAGE_MANIFEST_MISSING`
- `RAG_PACKAGE_MANIFEST_CONFLICT`
- `RAG_PACKAGE_PATH_INVALID`
- `RAG_PACKAGE_IMAGE_MISSING`
- `RAG_IMAGE_UNSUPPORTED_FORMAT`
- `RAG_IMAGE_ANNOTATION_REQUIRED`

### 3.2 图片标注模型

manifest 图片行字段：

- 必填：`image`、`purpose`、`description`
- 推荐：`title`、`detailed_description`
- 可选：`objects`、`tags`、`category`、`source_uri`、`language`、`enabled`

系统补充字段：

- `ocr_text`
- `ai_short_description`
- `ai_detailed_description`
- `ai_objects`
- `ai_tags`
- `quality_warnings`
- `annotation_status`

标注原则：

- 人工字段是权威内容。
- AI 只补全缺失内容。
- AI 不覆盖 manifest 中已有人工标注。
- 向量文本由标题、用途、描述、详细说明、对象、标签、OCR、AI 补全组合生成。

### 3.3 审核与确认流程

上传审核开关继续生效：

- 审核关闭：上传后进入等待确认。
- 审核开启：上传后进入 `waiting_review`。
- 审核通过：允许确认向量化。
- 审核拒绝：禁止向量化。

后台接口：

- `POST /admin-api/rag/uploads/{upload_id}/review`
- `GET /admin-api/rag/uploads/{upload_id}/preview`
- `POST /admin-api/rag/uploads/{upload_id}/confirm-vectorization`

## 4. 三期：AI 图片理解、视觉向量、检索策略和成功率监控

三期目标是把图片链路从 warning 降级升级为生产可用能力。

### 4.1 AI 图片标注与 OCR

实现 `RagAiImageAnnotationService`。

后台设置项：

- `rag_ai_image_processing.enabled`
- `rag_ai_image_processing.vision_model_id`
- `rag_ai_image_processing.prompt_profile`
- `rag_ai_image_processing.max_retries`
- `rag_ai_image_processing.timeout_ms`

模型选择逻辑：

- 从后台配置读取视觉模型。
- 复用现有 provider/model 配置。
- 仅选择支持 `vision_understanding` 的模型。
- 支持 OpenAI/Anthropic 风格的图片理解请求结构。

标准输出：

- `ocr_text`
- `ai_short_description`
- `ai_detailed_description`
- `ai_objects`
- `ai_tags`
- `annotation_status`
- `quality_warnings`

错误码：

- `RAG_AI_ANNOTATION_FAILED`
- `RAG_MODEL_NOT_CONFIGURED`

### 4.2 图片向量和多模态向量

实现 `RagEmbeddingProviderService`。

图片 chunk 支持三类向量：

- `text`：人工标注 + OCR + AI 描述。
- `image`：原图视觉向量。
- `multimodal`：图片 + 文本融合向量。

`RagVectorConfigService` 增强：

- `imageConfig()`
- `multimodalConfig()`
- 按数据集配置选择 text/image/multimodal collection。

Qdrant collection 按 modality 分离：

- `*_text`
- `*_image`
- `*_multimodal`

错误码：

- `RAG_IMAGE_EMBEDDING_FAILED`
- `RAG_MULTIMODAL_EMBEDDING_FAILED`

降级策略：

- 文本向量必须尽量成功。
- 图片向量失败不阻断文本向量。
- 多模态向量失败可单独记录并重试。

### 4.3 检索策略增强

`RagRetrievalService` 按请求模型能力选择召回策略：

- 文本模型：搜索 text collection。
- 多模态模型：搜索 text + multimodal collection。
- 图片相关请求：优先 image/multimodal collection。

source 返回增强：

- `modality`
- `image_id`
- `source_uri`
- `thumbnail_url` / asset 引用
- `human_annotation`
- `ai_annotation`

安全边界：

- 不向外部用户暴露本地 storage path。
- 多模态模型可引用图片资产来源，但仍通过受控来源字段返回。

### 4.4 成功率监控

实现 `RagVectorizationMetricsService`。

统计指标：

- 上传总数。
- 图片总数。
- 标注成功率。
- OCR 成功率。
- 文本向量成功率。
- 图片向量成功率。
- 多模态向量成功率。
- 总体向量化成功率。
- Top 失败原因。

后台设置项：

- `rag_vectorization_monitoring.success_rate_threshold`
- `rag_vectorization_monitoring.window_hours`
- `rag_vectorization_monitoring.consecutive_failure_threshold`
- `rag_vectorization_monitoring.qdrant_health_check_enabled`

告警：

- 成功率低于 95% 返回 `RAG_VECTOR_SUCCESS_RATE_LOW`。
- 失败原因聚合展示。
- 最近失败任务可用于排查和重试。

## 5. Web 后台闭环补充

三期后追加完成 RAG Web 操作闭环，使管理员可以不离开后台完成主要 RAG 工作流。

### 5.1 新增后台页面

新增 RAG 业务页面：

- `rag-dashboard`
- `rag-template-center`
- `rag-upload-workbench`
- `rag-upload-detail`
- `rag-image-preview`
- `rag-vectorization-metrics`

保留高级数据表：

- 知识库。
- 向量数据集。
- 绑定关系。
- 向量配置。
- 上传记录。
- 预览项。
- 文档。
- chunk。
- 图片资产。
- 向量任务。
- 向量记录。
- 检索日志。
- 反馈。
- 纠偏任务。
- 过期策略。

### 5.2 Web 操作链路

后台可完成：

```text
模板下载
  -> 选择知识库 / 数据集 / API Key
  -> 上传 Excel / CSV / TXT / ZIP
  -> 生成预览
  -> 文本 / 图片 / 错误行分页查看
  -> 审核通过或拒绝
  -> 确认批量向量化
  -> 查看进度条
  -> 查看向量化成功率和失败原因
```

### 5.3 Admin API 包装

新增后台包装接口：

- `GET /admin-api/rag/import-templates`
- `GET /admin-api/rag/import-templates/{template_id}/download`
- `POST /admin-api/rag/knowledge-bases/{kb_id}/uploads`
- `GET /admin-api/rag/uploads/{upload_id}`
- `GET /admin-api/rag/uploads/{upload_id}/preview`
- `POST /admin-api/rag/uploads/{upload_id}/review`
- `POST /admin-api/rag/uploads/{upload_id}/confirm-vectorization`
- `POST /admin-api/rag/image-assets/{image_id}/reprocess-ai`
- `GET /admin-api/rag/vectorization-metrics`

设计说明：

- 后台上传由管理员选择归属 API Key。
- 权限和数据集写入仍复用现有 RAG 权限链路。
- 不改变外部普通用户 API Key-only 模式。

## 6. 关键文件

### 6.1 后端服务

- `app/Services/RagPermissionService.php`
- `app/Services/RagTemplateService.php`
- `app/Services/RagUploadService.php`
- `app/Services/RagExcelImportService.php`
- `app/Services/RagPackageImportService.php`
- `app/Services/RagImageProcessingService.php`
- `app/Services/RagAiImageAnnotationService.php`
- `app/Services/RagChunkingService.php`
- `app/Services/RagEmbeddingJobService.php`
- `app/Services/RagEmbeddingProviderService.php`
- `app/Services/RagVectorConfigService.php`
- `app/Services/QdrantVectorStore.php`
- `app/Services/MysqlFallbackVectorStore.php`
- `app/Services/RagRetrievalService.php`
- `app/Services/RagPromptInjector.php`
- `app/Services/RagFeedbackService.php`
- `app/Services/RagCorrectionService.php`
- `app/Services/RagRetentionPolicyService.php`
- `app/Services/RagVectorDeletionService.php`
- `app/Services/RagVectorizationMetricsService.php`

### 6.2 控制器和路由

- `app/Http/Controllers/RagController.php`
- `app/Bootstrap/Kernel.php`

### 6.3 前端

- `public/admin/assets/js/resources.js`
- `public/admin/assets/js/table.js`
- `public/admin/assets/css/app.css`

### 6.4 文档和 OpenAPI

- `docs/development-tasks/14-rag-knowledge-base-design.md`
- `docs/external-api-sync.md`
- `docs/external-api-complete.md`
- `public/openapi.json`
- `examples/external-api/`

### 6.5 测试

- `tests/Console/RagSchemaTest.php`
- `tests/Console/RagTemplateDownloadTest.php`
- `tests/Services/RagAiImageAnnotationServiceTest.php`
- `tests/Services/RagImagePackageImportServiceTest.php`
- `tests/Services/RagImagePackageUploadServiceTest.php`
- `tests/Services/RagImageVectorizationServiceTest.php`
- `tests/Services/RagRetrievalImageSourcesTest.php`
- `tests/Services/RagUploadReviewServiceTest.php`
- `tests/Services/RagVectorizationMetricsServiceTest.php`
- `tests/Support/RagApiSurfaceContractTest.php`
- `tests/Support/RagUploadReviewContractTest.php`
- `tests/Frontend/AdminRagWorkflowFrontendTest.php`
- `tests/Frontend/AdminSettingsCenterFrontendTest.php`
- `tests/Frontend/AdminFrontendSecurityTest.php`

## 7. 外部 API 汇总

### 7.1 模板

- `GET /v1/rag/import-templates`
- `GET /v1/rag/import-templates/{template_id}/download`

### 7.2 上传与预览

- `POST /v1/rag/knowledge-bases/{kb_id}/uploads`
- `GET /v1/rag/uploads/{upload_id}`
- `GET /v1/rag/uploads/{upload_id}/preview`
- `POST /v1/rag/uploads/{upload_id}/confirm-vectorization`

### 7.3 检索和反馈

- `GET /v1/rag/retrievals/{retrieval_id}`
- `POST /v1/rag/feedback`

### 7.4 网关注入参数

模型请求中可传：

```json
{
  "rag": {
    "enabled": true,
    "dataset_ids": ["ds_xxx"],
    "dataset_names": ["产品知识库"],
    "top_k": 5,
    "required": false,
    "query_modality": "text"
  }
}
```

## 8. 生产配置和上线注意事项

代码已完成不等于生产效果自动达标。上线前必须完成真实配置和验收。

### 8.1 必配项

- Qdrant 服务健康。
- 向量 collection 可创建。
- 文本 embedding 模型已配置。
- 图片 embedding 模型按需配置。
- 多模态 embedding 模型按需配置。
- `rag_ai_image_processing.vision_model_id` 指向可用视觉理解模型。
- API Key 已绑定目标知识库和数据集。
- 数据集已有默认 collection 配置。

### 8.2 建议 smoke 流程

```text
创建知识库和数据集
  -> 绑定 API Key
  -> 下载 image_package 模板
  -> 准备 manifest.csv + images/ ZIP
  -> 后台上传
  -> 预览图片、人工标注、AI 标注、OCR、warning
  -> 审核通过
  -> 确认向量化
  -> 查看向量任务成功率
  -> RAG 调用验证 rag.sources
  -> 删除或禁用文档
  -> 再检索确认不召回
```

### 8.3 成功率目标说明

95% 不是单次上传的硬承诺，而是通过以下机制保障：

- 行级校验。
- 非阻断 warning。
- 任务状态记录。
- 分 modality 失败记录。
- 失败原因聚合。
- 可重试任务。
- 监控窗口成功率。
- 低于阈值告警。

## 9. 验证结果

已执行验证：

```bash
node --check public/admin/assets/js/table.js
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php bin/run-tests.php
```

最新完整测试结果：

```text
101 test file(s) passed.
```

迁移结果：

```text
Applied 0 migration(s).
```

## 10. 当前状态

当前 RAG 项目状态：

- 一期基础 RAG 闭环：已完成。
- 二期图片批量包和标注闭环：已完成。
- 三期 AI 图片理解、视觉向量、检索策略、成功率监控：已完成。
- Web 后台操作闭环：已完成。
- 外部 API 文档和示例：已补齐。

上线前剩余工作主要是生产配置和真实数据验收，不是代码开发缺口。
