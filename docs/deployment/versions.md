# TokenHub 版本与新增功能

Docker Hub 安装使用实际镜像标签，例如 `--tag 1.0.3`。GitHub 源码构建使用源码标签，例如 `--ref v1.0.3`。下面列出了各版本的可用入口和升级后可以检查的功能。

| 版本 | 主要新增功能 | GitHub 与 Docker Hub | 升级或验收要点 |
| --- | --- | --- | --- |
| **1.0.3** | 多模态会话记录。加密保存参考图、音视频、生成结果和语音识别转写，后台支持预览、播放和下载，可同步到腾讯云 COS。 | [GitHub v1.0.3](https://github.com/QianqianTech/Tokenhub/releases/tag/v1.0.3) · Docker Hub `1.0.3`（`linux/arm64`） · [镜像摘要](https://github.com/QianqianTech/Tokenhub/releases/download/v1.0.3/docker-hub-digests.tsv) | 已在全局和 API 密钥上启用会话记录时，默认保存多模态内容，可在会话记录设置里关闭。媒体索引在独立会话库内按需创建。 |
| **1.0.2** | 新增按 API 密钥启用的文字会话记录，支持用量和费用查看、保留规则、定时清理、加密存储和 COS。更新运营大屏，并提供独立升级脚本。 | [GitHub v1.0.2 源码](https://github.com/QianqianTech/Tokenhub/tree/v1.0.2) · Docker Hub 部署使用已发布的 `1.0.3`。 | 会话记录默认关闭。要验证该功能，先在系统设置启用，再为测试 API 密钥开启。升级会为 API 密钥添加会话记录相关字段。 |
| **1.0.1** | 知识库新增多轮追问改写、以文搜图、以图搜图、阈值校准、重排和紧凑向量存储。 | 变更随 `1.0.2` 对外发布。[变更记录](../../CHANGELOG.md#101---2026-11-04) | 启用多轮改写和图片检索需要相应模型配置。迁移后仍可读取旧向量。[知识库运维](../rag-vector-operations.md) |
| **1.0.0** | 首个版本。提供统一模型网关、密钥和额度管理、知识库、运营大屏和 Docker 部署。 | Docker Hub `1.0.0-bcc60e571698-arm64-cache`（`linux/arm64`）。[历史镜像摘要](docker-hub-1.0.0-bcc60e571698-arm64-cache.tsv) | 升级前备份数据库、配置和数据卷。选择 `1.0.3` 时，需要一并查看 `1.0.1` 和 `1.0.2` 的变更及迁移说明。 |

GitHub 会显示带 `v` 的源码标签。可以用下面的命令查询当前公开的版本。

```bash
git ls-remote --tags --refs https://github.com/QianqianTech/Tokenhub.git 'refs/tags/v*'
```

确认版本后，在原安装目录备份并升级。镜像部署填 Docker Hub 的完整标签，源码部署填 GitHub 标签。升级到 `1.0.3` 可参考[安装与升级命令](README.md#升级已有-docker-hub-部署)。各版本的完整变更见 [CHANGELOG](../../CHANGELOG.md)，备份和回退步骤见[升级指南](upgrade.md)。
