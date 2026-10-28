# TokenHub

TokenHub 是企业内部的大模型服务治理平台，提供统一的 OpenAI/Anthropic 兼容网关、API Key 与额度、成本统计、知识库（RAG）、智能体管理 API、MCP 接入和运维大屏。TokenHub 不内置 WAF，需要时在其前面部署专业 WAF 或云 WAF。

技术栈：PHP 8.2+（FPM）、Caddy、MySQL 8.4+/9.x、Redis、Qdrant、Python RAG worker。

## 安装

部署方式的选择见 [docs/deployment/README.md](docs/deployment/README.md)。

**Docker 部署（Linux / Windows / macOS）**

```sh
deploy/docker/install.sh --build --site tokenhub.example.com      # 从源码构建镜像并安装
# Windows: powershell -ExecutionPolicy Bypass -File deploy\docker\install.ps1 -Build -Site tokenhub.example.com
```

**源码安装（Ubuntu / Debian / RHEL 系）**

```sh
sudo deploy/linux/install.sh --site tokenhub.example.com
```

两种方式都会完成以下工作：生成随机密钥；执行数据库迁移；创建第一个超级管理员；启动 worker、调度器和知识库服务；最后运行自检并打印访问地址和初始密码。

`--site` 的取值：

| 取值 | 效果 |
| --- | --- |
| 域名 | 自动 HTTPS |
| `https://IP` | 使用内部 CA 签发的证书，通过 HTTPS 访问 |
| `IP` | 纯 HTTP |

## 开发环境

```sh
cp .env.example .env && sed -i.bak 's/^APP_ENV=.*/APP_ENV=local/' .env
docker compose up -d --build
docker compose exec -T php php bin/install.php run --admin-password='change-this-password'
docker compose exec -T php php bin/run-tests.php
```

- 访问地址：`http://localhost:8080/admin/`。
- 其他命令：
  - `php bin/doctor.php`：自检
  - `php bin/migrate.php --status`：查看迁移状态
  - `php bin/queue-worker.php --once`：单次运行队列 worker

## 文档

- 部署方式与版本基线：`docs/deployment/README.md`
- Docker 构建、离线包与镜像仓库：`docs/deployment/docker.md`；客户侧说明：`deploy/docker/README.md`
- 源码安装：`docs/deployment/linux-source.md`
- 备份与恢复：`docs/deployment/backup-restore.md`
- 使用与管理指南：`docs/tokenhub-usage-management-guide.md`
- 对外 API：`docs/external-api-complete.md`；OpenAPI 规范：`public/openapi.json`（线上为 `GET /v1/openapi.json`）
- 智能体管理 API：`docs/agent-management-api.md`
- MCP 接入（Claude Code / Cursor / Claude Desktop 等）：`docs/mcp.md`
- 验收清单：`docs/acceptance/final-acceptance-checklist.md`
