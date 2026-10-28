# 双架构离线 Docker 发布包设计

## 目标

将 TokenHub 交付为可在无网络 Docker 主机上恢复的离线发布包，同时支持 `linux/amd64` 和 `linux/arm64`。所有应用代码和运行依赖必须位于镜像中；目标主机不依赖源码目录、构建工具或外部镜像仓库。

离线包还携带当前业务数据、运行时存储和加密后的 `.env`。导入脚本选择本机架构对应的镜像，校验包完整性，恢复数据后通过 Compose 以 `--no-build` 启动。

## 非目标

- 不替换现有开发用 `docker-compose.yml` 的源码挂载工作流。
- 不把明文密钥写入镜像层、Git 或校验清单。
- 不修改业务 API、数据模型或运行端口策略。

## 发布架构

### 应用镜像

为每个目标平台构建以下不可变标签的镜像：

- `tokenhub-php`：复制 PHP 应用代码、Composer 生产依赖、配置模板和启动脚本。
- `tokenhub-caddy`：复制 Caddy 配置和 `public/` 静态资源；不再挂载宿主机 `public/`。
- `tokenhub-rag-worker`：复制 Python RAG worker 代码及其依赖。
- `tokenhub-worker-autoscaler`：复制 autoscaler 脚本和所需运行时，保留为可选 Compose profile。

PHP、Caddy 和 RAG worker 仅把可变内容挂载到命名卷：`storage`、Caddy 状态以及数据库/缓存/向量库数据。发布 Compose 中不得存在 `./:/srv/app`、`./public:/srv/app/public` 或其他源码绑定挂载，也不得有 `build:` 项。

基础服务镜像 MySQL、Redis、Qdrant 同样按架构导出，并以构建时解析到的确切镜像引用记录在清单中，避免 `latest` 在导入后发生漂移。

### 离线包结构

```text
TokenHub-offline-YYYYMMDD-HHMMSS/
  images/amd64/*.tar
  images/arm64/*.tar
  data/mysql.sql
  data/redis-data.tar.gz
  data/qdrant-snapshots/
  data/storage.tar.gz
  secrets/.env.enc
  compose.offline.yml
  import-and-start.ps1
  import-and-start.sh
  manifest.json
  SHA256SUMS
  README.md
```

镜像归档分别保存，令常见 Docker Engine 可使用 `docker load`，避免依赖对单一多架构 OCI 归档的支持。导入脚本按 `uname -m` 或 Windows Docker 架构选择目录；不匹配时立即失败并说明可用目录。

## 数据、机密与恢复

- MySQL 使用 `mysqldump --single-transaction --routines --triggers` 导出，并在目标 MySQL 容器健康后导入，确保跨架构可恢复。
- Redis 在持久化快照完成后归档 `/data`，恢复至新的 Redis 命名卷。
- Qdrant 逐集合创建并下载官方快照；目标 Qdrant 健康后逐个上传恢复，避免直接复制跨架构底层存储文件。
- 应用 `storage`、Caddy 数据和配置片段分别归档并恢复到发布 Compose 使用的命名卷。
- `.env` 不进入镜像，也不写入 `manifest.json`。打包时使用操作者提供的口令加密；导入脚本交互式读取口令，只在发布目录生成权限受限的运行时 `.env`。

恢复开始前脚本必须要求明确确认，因为它会创建或覆盖目标项目的数据卷。源端导出将在短暂冻结写入后进行；若队列无法排空，脚本会失败而非制造未声明的一致性窗口。

## 自动化接口

新增一个打包命令，接受版本、目标输出目录和机密加密口令，不接受任意 Dockerfile 或 Compose 路径。它按顺序执行：预检 Docker/Buildx、构建两架构镜像、导出基础镜像、创建数据快照、生成加密机密、生成清单和 SHA256。

新增 PowerShell 与 POSIX 导入脚本。二者均只使用发布包内的 Compose 和镜像，执行：校验、架构选择、`docker load`、机密解密、卷恢复、`docker compose --env-file ... -f compose.offline.yml up -d --no-build`，随后轮询 `/health`。

## 错误处理

- Docker Desktop/daemon、Buildx、磁盘空间、必需容器不健康或目标架构不可用时，预检失败且不生成“成功”包。
- 任一镜像或数据文件哈希不匹配时，导入停止，不加载后续镜像。
- 数据导出、Qdrant 快照、加密、恢复或健康检查失败时，脚本返回非零状态并保留诊断日志；不删除既有目标卷。
- `.env` 解密失败时不启动任何服务。

## 验证

实现需覆盖以下层次：

1. 结构化测试检查发布 Dockerfile 包含必要代码、生产依赖和静态资源。
2. Compose 合成检查确保离线 Compose 无 `build:` 与源码绑定挂载，且服务镜像都有稳定引用。
3. 打包脚本 dry-run 检查输出布局、架构选择、机密排除和清单生成。
4. 当前架构上的实际发布演练：在新 Compose 项目名与新命名卷中执行导入、恢复、`/health` 检查及 MySQL/Redis/Qdrant 可用性检查。

ARM64 镜像在构建端由 Buildx 生成并检查其平台元数据；若本机无法运行 ARM64 容器，不将本机构建结果伪称为 ARM 运行验证。
