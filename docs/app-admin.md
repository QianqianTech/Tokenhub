# TokenHub APP 管理端

TokenHub APP 是可以安装的管理端（PWA），地址是 `https://<站点>/app/`。手机和电脑都能用：窄屏显示底部标签栏，宽屏显示左侧导航。它复用后台的全部页面，权限与后台完全一致。

## 1. 登录：认证链接

1. 在电脑上打开后台，点左下角头像旁的手机图标「APP 设备」。
2. 点「生成认证链接」。链接 10 分钟内有效，只能使用一次。
3. 在手机上扫二维码，或者复制链接后粘贴到 APP 首页。

注意事项：

- 认证链接只能在 Web 后台生成，APP 里不能生成。
- 链接中的认证码放在 `#` 后面，不会出现在服务器和代理的日志里。打开链接后，APP 会立即把认证码从地址栏清掉。
- 每个管理员最多绑定 5 台设备（`APP_MAX_DEVICES_PER_USER`），超出时需要先吊销旧设备。
- **为其他管理员生成链接：** 超级管理员可以在「组织与权限 → 管理员账号 → 更多 → APP 设备」中操作。可以为对方生成认证链接、查看对方的设备、吊销对方的设备，所有操作都写入审计。请把链接当面交给本人。
- **设备风险提示：** 设备列表会标出两种情况：使用的网络与绑定时不同（IPv4 按 /16，IPv6 按 /48 比较），以及浏览器或系统与绑定时不同。这些只是提示，不会自动吊销设备。
- 用同一个浏览器再次打开新链接时，这个浏览器之前绑定的设备会自动退出，不会多占一个名额。

### 1.1 新设备确认（可选）

设置 `APP_NEW_DEVICE_APPROVAL=true` 后，认证链接即使泄露，也不能直接登录：

1. 打开链接的设备进入「等待确认」页面，显示 4 位匹配码。此时设备拿不到访问令牌。
2. 在电脑上的后台打开「APP 设备」。等待确认的设备会自动出现在列表顶部，并显示同样的匹配码。
3. 核对两边的匹配码一致后点「确认」，设备随即自动进入 APP；点「拒绝」则设备回到登录页，并提示已被拒绝。

规则：

- 只能在 Web 后台确认，不能用另一台 APP 设备确认。超级管理员可以在「管理员账号 → 更多 → APP 设备」中替其他管理员确认。
- 10 分钟内未确认，设备自动作废，需要重新生成认证链接。
- 等待确认的设备也占用设备名额；等待期间 APP 被关闭再打开，仍然停留在等待页面。
- 审计事件：`admin.app_device.link_pending`、`approve`、`reject`。

## 2. 保持登录

设备有两种凭证：

- **设备凭证**：长期有效，每次使用都会更换。PWA 把它存在前端脚本读不到的 Cookie 里，安装包把它存在系统钥匙串里。
- **访问令牌**：60 分钟有效，只保存在内存中。

APP 闪退、被系统关闭、手机重启或断网之后再打开，会自动用设备凭证换一个新的访问令牌，不需要重新登录。即使 APP 恰好在更换凭证的过程中被杀掉，下次打开也能自动恢复。

这种“丢失更换结果后的恢复”有两个条件：同一个浏览器或安装包（User-Agent 相同），并且与上次刷新处于同一网段（IPv4 /24，IPv6 /48）。任一条件不满足，就按凭证被复制处理：设备立即吊销，并写入审计 `admin.app_device.reuse_detected`。恢复成功时 APP 会提示一次“登录状态已自动恢复”，审计事件为 `admin.app_device.refresh_recovered`。因此，如果 APP 在更换凭证途中被杀掉，又刚好从 Wi-Fi 换到了蜂窝网络，需要重新打开认证链接。

以下情况需要重新打开认证链接：

- 在 APP 中退出登录；
- 设备在后台被吊销，或管理员执行了“吊销全部会话”；
- 重置了密码；
- 账号或部门被停用；
- 30 天没有使用（通过 HTTP 绑定的设备为 7 天）；
- 绑定已满 180 天；
- PIN 连续输错 5 次。

## 3. PIN 与敏感操作

在 APP 中执行以下操作前，必须在 5 分钟内验证过本设备的 PIN：

- 删除、轮换或批量操作 API 密钥；
- 调整额度；
- 管理员账号管理；
- 修改系统设置；
- 修改供应商（新增、编辑、删除、导入模型、同步价格目录）；
- 管理大屏分享链接（新建、编辑、停用、重置密码、踢出会话、放行客户端 IP）；
- 修改向量库配置（包括连接测试）；
- 重置用户密码；
- 吊销其他设备。

验证由服务端执行，直接调用接口也绕不过去。

以下操作不需要 PIN：查看数据、处理告警、新建密钥、编辑或停用密钥。停用是止损动作，不应被拦住。

其他说明：

- 第一次遇到敏感操作时，APP 会提示设置 6～12 位数字 PIN。
- **Passkey：** 在「我的 → Passkey」中添加。之后验证时可以用面容、指纹或系统密码代替 PIN。服务端会校验来源、挑战值、签名和签名计数器，支持 ES256 和 RS256。Passkey 需要通过 HTTPS 和域名访问，`https://IP` 下只能用 PIN。设备已设置 PIN 或已有 Passkey 时，添加新的 Passkey 前需要先验证一次。
- 忘记 PIN 时，重新打开一个认证链接即可。
- 「我的 → 打开 APP 时验证 PIN」：开启后，APP 离开前台超过设定的时间，再打开时需要输入 PIN。
- 纯内网、低风险的部署可以设置 `APP_STEP_UP_REQUIRED=false` 关闭二次验证。

## 4. 实时更新与告警推送

APP 在前台和后台用两种方式接收更新：

| APP 状态 | 方式 | 延迟 |
| --- | --- | --- |
| 打开在前台 | 实时连接（SSE，`GET /admin-api/app-auth/stream`） | 新告警约 2 秒内弹出提示，并更新角标和概览；用量每 10 秒、服务健康每 15 秒刷新。顶部显示“实时”标记 |
| 在后台或已关闭 | Web Push 系统通知（PWA）；APNs / FCM（iOS、Android 安装包） | 告警入队后立即投递，不再等下一轮告警评估（最长约 1 分钟） |
| 电脑安装包在后台 | 实时连接不断开，新告警弹出系统通知 | 约 2 秒 |

关于实时连接：

- 每条连接都会占用一个 PHP-FPM 进程，所以连接只保持 `APP_STREAM_SECONDS`（默认 55 秒），到时 APP 自动重连。
- 全局最多 `APP_STREAM_MAX_CONNECTIONS` 条（默认 8 条），每台设备最多 `APP_STREAM_MAX_PER_DEVICE` 条（默认 2 条）。超过上限时服务端返回 503，APP 自动改为定时刷新。请把全局上限设得明显低于 `PHP_FPM_MAX_CHILDREN`。
- APP 切到后台、断网或设备被吊销时，连接会立即关闭。

## 4.1 告警推送（Web Push）

在「我的 → 告警推送」中开启。开启的条件：

- 使用 HTTPS 访问；
- iPhone 和 iPad 需要 iOS 16.4 以上，并且先用 Safari「分享 → 添加到主屏幕」安装 APP，再从主屏幕打开。

推送的工作方式：

- 推送内容只提示“有新告警”和严重级别，不包含告警详情。点开推送后，APP 再拉取告警。
- **推送规则**（每台设备单独设置）：可以选择推送全部告警、警告及以上（默认）或仅严重告警；可以设置免打扰时段（按手机所在时区，支持跨午夜）。免打扰期间仍然推送严重告警。
- 服务端第一次需要时会自动生成 VAPID 密钥，用 `APP_KEY` 加密后保存在 `system_settings` 中。
- 推送服务地址（FCM、Mozilla、Apple）需要能从 php 容器访问。可以用 `APP_PUSH_SUBJECT` 设置联系邮箱。

## 4.2 安装包的原生推送（APNs / FCM）

iOS 的 WebView 不支持 Web Push，所以 iOS 和 Android 安装包改用系统推送。凭证由每个部署自己提供（Q5 采用“自备凭证”），不经过任何中转服务。

| 平台 | 需要准备 | 配置 |
| --- | --- | --- |
| iOS（APNs） | Apple 开发者账号中创建的 APNs 认证密钥（`.p8`），Key ID、Team ID；安装包的 Bundle ID | `APNS_KEY_ID`、`APNS_TEAM_ID`、`APNS_BUNDLE_ID`（默认 `com.qianqian.tokenhub`）、`APNS_PRIVATE_KEY_PATH`、`APNS_PRODUCTION`（开发版安装包用 `false`） |
| Android（FCM） | Firebase 项目的服务账号 JSON；构建安装包时用的 `google-services.json` | `FCM_SERVICE_ACCOUNT_PATH` |

说明：

- 密钥文件必须能被 php 容器读取。Docker 部署可以放在存储卷中，例如：`docker compose cp AuthKey_XXXX.p8 php:/srv/app/storage/app-push/`，再把 `APNS_PRIVATE_KEY_PATH` 设为 `/srv/app/storage/app-push/AuthKey_XXXX.p8`。不要把密钥提交到代码仓库。
- 服务器需要能访问 `api.push.apple.com:443`（或 sandbox）、`oauth2.googleapis.com` 和 `fcm.googleapis.com`。
- 没有配置凭证时，安装包的「告警推送」开关不可用，并提示原因；APP 打开时的实时更新不受影响。
- 推送内容与 Web Push 相同，只有“有新告警”和严重级别。严重告警在 iOS 上以“时效性通知”送达，在 Android 上以高优先级送达。点开通知后进入告警页面。
- 设备令牌失效（APNs 410 / `BadDeviceToken`，FCM 404 / `UNREGISTERED`）时，服务端自动删除该订阅。
- 安装包的构建步骤（推送权限、`google-services.json`）见 `native/README.md`。

## 5. 部署方式与能力

| 能力 | 域名（自动 HTTPS） | `https://IP` | 纯 HTTP |
| --- | --- | --- | --- |
| 链接登录、闪退免登录、全部页面 | 支持 | 支持 | 支持 |
| 安装到主屏幕或桌面、离线外壳 | 支持 | 手机信任根证书后支持 | 不支持 |
| 告警推送 | 支持 | 手机信任根证书后支持 | 不支持 |
| APP 内扫码 | 支持（iPhone / iPad 除外） | 手机信任根证书后支持（iPhone / iPad 除外） | 不支持，可用系统相机扫码 |
| 设备未使用失效时间 | 30 天 | 30 天 | 7 天 |

APP 内扫码依赖浏览器的 BarcodeDetector。iPhone / iPad 上的 Safari、主屏幕 APP 和安装包都没有这个接口，登录页会直接提示改用系统相机扫描后台二维码，或复制链接粘贴。Caddy 只对 `/app/` 放开 `Permissions-Policy: camera=(self)`，其他页面仍禁止使用相机。

使用 `https://IP` 时，先打开 `/app/#/ca`（证书安装指引）。该页面提供：

- iPhone、iPad、Mac 使用的描述文件：`/admin-api/app-auth/ca.mobileconfig`；
- Android、Windows 使用的根证书：`/admin-api/app-auth/ca.crt`；
- 分步说明。

根证书只包含公开信息。Docker 部署中，caddy 容器只开放内部 CA 的 `.crt` 文件供下载，私钥保持不可读。

## 6. 运维

- **Caddy：** `docker/caddy/Caddyfile`、`docker/release/caddy/Caddyfile` 和 `Caddyfile.example` 都已加入 `/app/*`，路径不存在时回落到 `index.html`。`sw.js` 和 manifest 每次都会重新校验。
- **Service Worker 版本：** `sw.js` 的缓存名在构建镜像时由 `scripts/release/stamp-app-version.sh` 写入（`<版本>-<提交>`），每次发布都会换一份离线外壳缓存；源码安装由 `deploy/linux/install.sh` 写入 `<VERSION>-<内容哈希>`；直接从源码目录运行时为 `dev`。
- **定时清理：** 定时任务 `cleanup.app-devices` 每小时运行一次，把过期设备标记为失效，并删除 7 天前的认证码。
- **数据库迁移：**
  - `2026_10_31_000001_admin_app_devices.sql`：设备、认证码，以及 `admin_sessions.channel`、`device_id`、`step_up_at` 字段；
  - `2026_10_31_000003_admin_app_push.sql`：推送订阅；
  - `2026_10_31_000004_admin_app_security.sql`：审计渠道和设备字段、Passkey、推送规则、设备风险；
  - `2026_10_31_000005_admin_app_native.sql`：推送订阅类型（`webpush` / `ios` / `android`），设备状态 `pending` 与新设备确认字段。
- **审计：** 审计日志新增“渠道”字段（Web 后台或 TokenHub APP）和“APP 设备 ID”字段，可以在审计页面按这两个字段筛选。
- **手机上的表格：** 在 APP 内用手机打开后台页面时，表格会自动显示为带字段名的卡片。宽屏下仍然显示为表格。
- **审计事件：** `admin.app_link.create`、`admin.app_device.link`、`refresh_recovered`、`reuse_detected`、`revoke`、`logout`、`pin_set`、`pin_change`、`pin_locked`、`passkey_add`、`passkey_failed`、`passkey_remove`、`push_subscribe`、`revoke_all`、`link_pending`、`approve`、`reject`。
- **测试：**
  - 静态测试：`tests/Support/AppAuthRouteContractTest.php`、`AppShellContractTest.php`、`AppSecurityExtrasTest.php`（包含用真实认证器录制的 Passkey 数据）、`AppRealtimeLoopTest.php`、`AppNativePushTest.php`（APNs / FCM 令牌签名与发送逻辑）；
  - 数据库测试：`tests/Services/AppDeviceAuthServiceTest.php`、`AppStepUpPushTest.php`、`AppSecurityExtrasDbTest.php`、`AppRealtimeDbTest.php`、`AppDeviceApprovalDbTest.php`；
  - 浏览器测试：`tests/e2e/app.test.mjs`、`tests/e2e/app-mobile.test.mjs`（所有后台页面在 390 px 和 768 px 下打开，检查报错和横向滚动）；
  - CI：`tests.yml` 的 unit 任务运行上面的静态测试，`admin-e2e` 任务运行全部浏览器测试，`integration` 任务（push / PR 必跑，MySQL + Redis）运行全部数据库测试并单独跑一遍 `tests/Services/App*DbTest.php`；`app-native.yml`（手动触发）构建未签名的 Android、iOS 模拟器和桌面安装包。

## 7. 安装包（iOS、Android、Mac、Windows）

代码在 `native/`：`capacitor/` 是 iOS 和 Android 版，`tauri/` 是 macOS 和 Windows 版。构建步骤见 `native/README.md`。安装包只是薄壳，界面仍由服务器的 `/app/` 提供，凭证改为存放在系统钥匙串中，并按服务器来源隔离。安装包支持原生推送（见 4.2），电脑版在后台时保持实时连接并弹出系统通知。在安装包中，离开当前 TokenHub 服务器的链接（其他域名，或 `target="_blank"`）一律交给系统浏览器打开，不在壳内加载。通用安装包需要连接用户输入的任意 HTTPS 服务器，因此 `allowNavigation` 默认仍为 `*`；企业自用的安装包可在构建时用 `TOKENHUB_ALLOWED_HOSTS=tokenhub.example.com npm run sync` 限定只能加载自己的域名（见 `native/README.md`）。**iOS 已通过未签名模拟器构建；真机签名和推送尚未验证。macOS 构建仍待补齐 Rust 下载依赖，Windows 和 Android 未在本机构建。**
