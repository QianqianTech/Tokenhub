# TokenHub Docker 升级指南

已有实例请在**原安装目录**升级。脚本复用部署的 `.env`、Compose 项目和数据卷，保留账号、API Key、知识库和站点配置。升级会重启应用容器，数据库迁移期间可能短暂中断服务。

## 1. 选对升级方式

在原安装目录的 `deploy/docker/.env` 中查看 `TOKENHUB_IMAGE_PREFIX` 和 `TOKENHUB_IMAGE_TAG`。如果使用独立 Docker 运维包，这两个值在运维包的 `.env` 中。不要分享整个 `.env`，其中包含密钥和密码。

| 当前安装方式 | 升级参数 | 目标版本示例 |
| --- | --- | --- |
| 从 Docker Hub 或其他镜像仓库安装，`.env` 中已有镜像前缀 | `--tag` / `-Tag`，从原仓库拉取新标签 | `1.0.2` |
| 从 GitHub 下载源码并本地构建，镜像前缀为空，原目录有 Git 仓库 | `--ref` / `-Ref`，获取正式 GitHub 标签后重新构建 | `v1.0.2` |
| 使用离线镜像包，镜像前缀为空 | 先导入新离线镜像，再运行部署包自己的升级脚本 | 见[离线发布与导入](offline-docker-release.md) |
| 直接在 Linux 主机安装 PHP、MySQL 等组件 | 使用 Linux 原生升级流程 | 见 [Linux 源码安装](linux-source.md) |

`1.0.2` 的 Docker Hub 镜像适用于 Docker 服务端为 `linux/arm64` 的实例，仓库前缀为 `docker.io/leofan2022/`。可用 `docker version --format '{{.Server.Os}}/{{.Server.Arch}}'` 查看。AMD64 用户可以在已有的源码构建实例上使用 `--ref` / `-Ref`。从其他仓库或安装方式迁移需要单独处理；升级入口不会替换镜像仓库。镜像模式使用本机已有的 Compose 文件；如果目标版本的发布说明要求更新部署文件，先按该版本的说明操作。

## 2. 下载、预检和升级

先看目标版本的 [GitHub 发布说明](https://github.com/QianqianTech/Tokenhub/releases)和[变更记录](../../CHANGELOG.md)，确认目标标签、架构和迁移要求。以下以 `1.0.2` 为例，等对应标签和镜像发布后再使用；升级到其他版本时，替换下载地址和命令中的版本号。如果原目录已经包含根目录的升级入口，也可以直接使用。提前确认 Docker 正在运行、Compose 为 2.20 或以上、磁盘能容纳备份和新镜像，并安排维护窗口。源码升级还需要 Git；先保存本地源码改动。

生产实例建议在备份前暂停业务写入、等待现有任务处理完毕。保留 Docker 服务运行，同一实例一次只执行一个升级。

### Linux、macOS 或 WSL 2

在**原安装目录以外**下载升级脚本，避免把下载文件当成源码修改。将 `/opt/tokenhub` 改为你的原安装路径：

```bash
curl --fail --show-error --location --retry 3 \
  https://raw.githubusercontent.com/QianqianTech/Tokenhub/v1.0.2/upgrade.sh \
  --output tokenhub-upgrade.sh

# Docker Hub 安装：先看计划，再执行升级
bash tokenhub-upgrade.sh --dir /opt/tokenhub --tag 1.0.2 --check
bash tokenhub-upgrade.sh --dir /opt/tokenhub --tag 1.0.2

# 源码构建安装：使用以下两条代替上面的镜像升级命令
bash tokenhub-upgrade.sh --dir /opt/tokenhub --ref v1.0.2 --check
bash tokenhub-upgrade.sh --dir /opt/tokenhub --ref v1.0.2
```

### Windows PowerShell

启动使用 Linux 容器的 Docker Desktop。在原安装目录以外下载脚本，将 `C:\TokenHub` 改为实际路径：

```powershell
Invoke-WebRequest -UseBasicParsing `
  -Uri 'https://raw.githubusercontent.com/QianqianTech/Tokenhub/v1.0.2/upgrade.ps1' `
  -OutFile .\tokenhub-upgrade.ps1

# Docker Hub 安装
powershell -NoProfile -ExecutionPolicy Bypass -File .\tokenhub-upgrade.ps1 -Directory 'C:\TokenHub' -Tag 1.0.2 -Check
powershell -NoProfile -ExecutionPolicy Bypass -File .\tokenhub-upgrade.ps1 -Directory 'C:\TokenHub' -Tag 1.0.2

# 源码构建安装
powershell -NoProfile -ExecutionPolicy Bypass -File .\tokenhub-upgrade.ps1 -Directory 'C:\TokenHub' -Ref v1.0.2 -Check
powershell -NoProfile -ExecutionPolicy Bypass -File .\tokenhub-upgrade.ps1 -Directory 'C:\TokenHub' -Ref v1.0.2
```

- `--check` / `-Check` 只检查本地安装目录、版本配置、Docker 和 Compose；它不会联网确认标签、下载源码或镜像、写入配置或重启服务。
- 正式执行时需要确认。无人值守时使用 `--yes` / `-Yes` 跳过提示，仍会备份。脚本会输出备份位置，请保留该路径。
- Bash 升级入口在切换版本前校验备份，并修复旧版备份脚本可能写入的临时校验文件条目。
- 若通过源码升级，脚本用旧版本的部署文件先备份，再切换到目标 GitHub 标签对应的提交。新镜像标为 `source-<提交前12位>`，便于区分和回退。
- 若脚本提示项目或镜像环境变量冲突，请核实原部署，将正确的项目名与镜像配置保留在 `.env` 中，并清除当前终端的同名覆盖变量后再试。

## 3. 验收升级

看到 `Upgrade verified` 表示升级入口调用的部署检查已通过。然后登录原管理后台，确认旧账号能登录、现有数据集和文档可检索，并使用已有测试 Key 发一次常用 API 请求。`1.0.2` 的多轮查询改写和图片检索需要按[知识库向量运维文档](../rag-vector-operations.md)配置；升级不会自动启用这些能力，也不要求立即批量转换旧向量。

若需要重新检查，在原安装目录执行 `bash deploy/docker/verify.sh`；Windows 对应 `powershell -NoProfile -File .\deploy\docker\verify.ps1`。独立运维包直接运行包内的 `verify.sh` / `verify.ps1`。检查报告保存在 `verify-report-*.txt`。

## 4. 升级失败或需要回退

1. 先保留升级输出和备份，不要删除原容器的数据卷，也不要执行 `docker compose down -v`。如果拉取或构建失败，检查网络、镜像仓库、架构和磁盘后再试。
2. 迁移或启动失败时，底层升级脚本会尝试切回旧镜像。源码模式会保留已下载的目标源码并打印旧提交。若只是升级后的独立验收失败，先看报告和容器日志，脚本不会擅自执行数据恢复。
3. 切回镜像不会撤销数据库迁移。若迁移已执行、旧版本无法兼容，暂停业务写入，源码模式先切回升级前的提交，再按[备份与恢复](backup-restore.md#docker-部署)恢复升级前的备份。**恢复会覆盖备份之后的写入。** Windows 请通过 WSL 或 Git Bash 运行恢复脚本。

升级备份含密钥、数据库和应用数据，请妥善保管，并将需要长期保存的副本移到受控存储。不要把 `.env`、备份内容或 API Key 贴到公开 Issue 中。
