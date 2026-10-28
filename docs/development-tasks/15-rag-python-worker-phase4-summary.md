# 15 RAG Python Worker Phase 4 Summary

PHP 继续做主系统：认证、后台页面、外部 API、上传解析入口和业务权限仍由 TokenHub PHP 负责，Python RAG Worker 只消费已写入数据库的 `rag_embedding_jobs`。

Python RAG Worker 的职责是读取真实数据库任务，处理 `rag_chunks`、`rag_image_assets`，并写回 `rag_vector_records`。向量写入优先走 Qdrant；开发和冒烟场景允许 deterministic fallback，避免把页面做成 demo 数据。

部署入口已经接入 `docker-compose.yml` 的 `rag-worker` 服务，构建上下文为 `python-rag-worker`。该服务依赖 MySQL、Redis、Qdrant，并挂载 `./storage` 读取上传图片和生成资产。

边界保持不变：不新增普通用户登录。普通用户仍通过 API Key 访问外部 RAG 上传、检索和反馈接口，后台页面只面向管理员展示数据库中的真实状态。
