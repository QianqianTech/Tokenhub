# TokenHub 初始管理员密码

通过 TokenHub 安装脚本全新部署时，默认超级管理员用户名是 `admin`。未指定初始密码时，脚本会为本次部署生成 20 个字符的随机密码。用这组初始凭据登录 `/admin/`。如果安装时指定了用户名或密码，以实际部署值为准。

## 查看初始密码

安装完成后，先看终端末尾的 `Password`。同一区域的 `Administrator` 是用户名，`Admin console` 是登录地址。

如果安装终端已关闭，在部署服务器的配置中查找 `TOKENHUB_ADMIN_PASSWORD`。下面的命令也会显示用户名和站点地址。请在自己的终端执行，避免把输出或配置文件发到聊天或工单中。

**Docker 部署**。进入实际部署的 `compose.yml` 所在目录。仓库内的生产部署目录通常是 `deploy/docker`，独立运维包则使用包根目录。

```sh
grep -E '^(APP_URL|TOKENHUB_ADMIN_(USERNAME|PASSWORD))=' .env
```

Windows PowerShell 在同一目录运行以下命令。

```powershell
Select-String -Path .env -Pattern '^(APP_URL|TOKENHUB_ADMIN_(USERNAME|PASSWORD))='
```

**Linux 原生安装**。配置文件位于 `/etc/tokenhub/tokenhub.env`。

```sh
sudo grep -E '^(APP_URL|TOKENHUB_ADMIN_(USERNAME|PASSWORD))=' /etc/tokenhub/tokenhub.env
```

## 安装时指定初始密码

需要预先指定时，直接使用对应的部署脚本。初始密码至少 12 个字符，使用随机字母和数字可兼容安装脚本的配置文件格式。

| 部署脚本 | 初始用户名参数 | 初始密码参数 |
| --- | --- | --- |
| Docker Bash `deploy/docker/install.sh` | `--admin-user` | `--admin-password` |
| Docker PowerShell `deploy/docker/install.ps1` | `-AdminUser` | `-AdminPassword` |
| Linux 原生 `deploy/linux/install.sh` | `--admin-user` | `--admin-password` |

## 首次登录后更换密码

1. 打开 `Admin console` 指向的地址，或在站点地址 `APP_URL` 后加 `/admin/`。本机快速安装示例的地址是 `http://localhost:8080/admin/`。
2. 用初始用户名和密码登录，进入「组织与权限 → 管理员管理」。在自己的账号行点击「更多 → 重置密码」，输入并保存新密码。重置会撤销该账号的现有后台会话。
3. 用新密码重新登录成功后，从实际部署的配置文件中删除 `TOKENHUB_ADMIN_PASSWORD` 这一行，保留其他配置。

## 初始密码找不到或无法登录

先确认访问的是本次部署的站点，输入的是对应的管理员用户名。原地升级保留现有密码，已改过密码的账号应使用改后的密码。`TOKENHUB_ADMIN_PASSWORD` 仅在初始化时使用。

如果初始密码丢失，由部署服务器管理员执行以下命令设置新密码。把 `admin` 换成实际的超级管理员用户名，并按提示输入至少 12 个字符的新密码。命令也能创建超级管理员，填错用户名可能新增账号。

Docker 部署在 `compose.yml` 所在目录运行以下命令。

```sh
docker compose exec php php bin/install.php admin --username=admin
```

Linux 原生安装在服务器上执行。如果安装时自定义过应用目录，请替换 `/var/www/tokenhub`。

```sh
sudo -u tokenhub php /var/www/tokenhub/bin/install.php admin --username=admin
```

账号已绑定找回邮箱且 SMTP 可用时，也可以用登录页的「忘记密码？」设置新密码。[邮件找回配置](../tokenhub-usage-management-guide.md#31-超级管理员密码初始化或找回)
