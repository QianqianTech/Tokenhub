# 知识库向量：配置与运维（2026-11-04）

本文说明向量配置里与检索质量、模型能力相关的几项设置，以及对应的运维命令。

## 1. 向量阈值

向量检索会过滤掉相似度低于阈值的结果。阈值和嵌入模型的分数分布绑定：bge 一类模型相关结果常在 0.5 以上，OpenAI text-embedding-3 一类常在 0.3–0.5，图文跨模态（CLIP 类）更低。

取值顺序：

1. 知识库检索配置里的“向量相似度下限”（`retrieval_profile.min_vector_score`），填了就以它为准；
2. 向量配置里的“向量最低分”（`config_json.min_vector_score`）；
3. 默认：文本 0.35；使用多模态向量接口的图片 / 多模态配置 0.20。

**用评测集校准**（每个数据集建议至少 30 条启用的评测用例）：

```
php bin/rag-index.php calibrate --dataset=ds_x                 # 只给建议
php bin/rag-index.php calibrate --dataset=ds_x --apply         # 写入该数据集文本向量配置
php bin/rag-index.php calibrate --dataset=ds_x --target-recall=0.9
```

输出包含相关结果和不相关结果的分数分位数（p10 / p50 / p90）、建议阈值、建议阈值下保留的相关结果比例和过滤掉的不相关结果比例。建议阈值保证 `target-recall`（默认 95%）的相关结果不被过滤。`--apply` 写在向量配置上，使用同一配置的所有数据集共用。

**rerank 把关**：知识库配置了重排模型并且填了“重排得分下限”时，向量 / 关键词阈值只按 `rerank_prefilter_factor`（默认 0.5）宽松预筛，最终由重排分数决定；重排调用失败时自动回到正常阈值。没填重排得分下限时保持原来的顺序（先阈值、后重排）。

## 2. 模型输入长度（max_input_tokens）

在向量配置里填写模型的最大输入长度（例如 bge-large-zh 填 512）后：

- 分块时单块上限不超过该值的 90% 再减去标题上下文的余量；
- 仍然超长的分块（表格、长段落）按句子拆成多个向量（`_p2`、`_p3` …），都指向同一个分块，每段都带「文档 > 章节」上下文；最多 20 段。

不填时行为不变。

## 3. 图片向量（多模态嵌入模型）

图片 / 多模态向量配置的“图片向量接口”（`config_json.embedding_api`）：

| 取值 | 接口 | 典型模型 |
| --- | --- | --- |
| `text`（默认） | 不送图片，用图片的描述、OCR 文字做文本向量 | 任意文本嵌入模型 |
| `openai_mixed` | `POST /v1/embeddings`，`input` 为 `{"text"}` / `{"image"}` 混合数组 | Jina jina-clip-v2 及兼容服务 |
| `ark_multimodal` | `POST {base_url}/embeddings/multimodal`（base_url 一般为 `…/api/v3`） | 火山方舟 doubao-embedding-vision |
| `dashscope_multimodal` | `POST /api/v1/services/embeddings/multimodal-embedding/multimodal-embedding` | 阿里云百炼多模态向量模型 |

使用多模态接口时：

- 入库：图片配置发送图片本身（按“图片最大像素”缩小，默认约 100 万像素，JPEG/PNG data URI）；多模态配置同时发送图片和描述文字；
- 检索：文本问题也会检索图片向量（以文搜图）；对话里带图片时，用最近一条用户消息里的图片检索（以图搜图），也可以在请求的 `rag.query_image` 里传图片 URL 或 data URI；
- 可选 `config_json` 键：`endpoint`（覆盖接口地址）、`request_params`（每次请求附加的参数，百炼放在 `parameters` 里）、`document_params` / `query_params`（只在入库 / 查询时附加，例如 Jina 的 `task`）。

注意：

- 维度必须和模型输出一致，先用“测试向量模型”确认；
- 已有向量的配置不能直接改“图片向量接口”，需新建配置后用 `rag-index.php reindex --config=…` 切换（与改模型相同）；
- 图片以 data URI 发送，单张上限约 8 MB（缩小后）。

## 4. 向量副本的紧凑存储

`rag_vector_records` 为复用未变化的向量和 MySQL 回退检索保存每个向量的副本。迁移 `2026_11_04_000001` 之后新向量以 float32（base64）存在 `vector_f32` 列，1024 维约 5.5 KB（原 JSON 约 20 KB）。旧数据可在升级后转换：

```
php bin/rag-index.php compact-vectors [--batch=500]
```

转换前后两种格式都能正常读取，转换可以分多次执行。

## 5. 多轮查询改写

对话中的追问（“那个怎么报？”“它支持吗？”）检索前先改写成完整问题（“出差住宿费怎么报销？”），只影响检索用的问题，发给回答模型的消息不变。

开启方式（任选其一，知识库配置优先）：

- 后台“知识库”编辑页：“多轮查询改写模型”选一个对话模型（建议用便宜、快速的小模型），“查询改写时机”选“自动”或“每次”；
- 环境变量：`RAG_QUERY_REWRITE_MODEL_ID=<模型 id>`、`RAG_QUERY_REWRITE_MODE=auto|always`。

行为：

- 自动模式只在有对话历史、并且问题较短或含“这、那、它、上面、刚才”等指代时改写；第一句问题和已经完整的问题不改写；
- 请求里带了 `rag.query` 时直接使用，不改写；
- 改写调用超时默认 3 秒、不重试（`retrieval_profile.query_rewrite.timeout_ms` 可调），结果缓存 10 分钟；用量记入该模型（端点 `rag:query-rewrite`）；
- 模型不可用、超时或返回的不是一个问题时，退回为“上一个用户问题 + 当前问题”一起检索；
- 网关响应的 `rag.rewritten_query` 返回实际检索用的问题（未改写为 `null`）。
