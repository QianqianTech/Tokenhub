# 09 独立大屏链接任务清单

## 目标

实现可独立访问的智慧大屏链接：`/screen/{share_code}`。每个链接有独立用户名、密码、数据范围、可看指标、IP 白名单、持久登录会话和访问日志。

## 数据表

- `dashboard_share_links`
- `dashboard_share_access_logs`

## 会话规则

- 大屏输入用户名和密码后保持登录。
- 会话不因时间、空闲、刷新页面或关闭浏览器自动过期。
- 正常情况下只有用户主动退出后才需要重新登录。
- `expires_at` 只限制未登录用户的新登录，不踢出已登录大屏。
- 管理员撤销会话、链接停用、密码重置、IP 不允许或安全策略触发时可以强制下线。

## 开发任务

- [x] 创建 `dashboard_share_links` 表，字段包含 `share_code`、`name`、`username`、`password_hash`、`scope_type`、`scope_ids`、`allowed_metrics`、`allow_drilldown`、`allowed_ip_cidrs`、`expires_at`、`status`。
- [x] 创建 `dashboard_share_access_logs` 表，记录登录成功、登录失败、退出、刷新、IP 拒绝、新登录因链接到期被拒绝、会话撤销。
- [x] 实现后台大屏分享链接列表、创建、编辑、停用。
- [x] 实现独立密码设置和重置，明文只在创建或重置时出现。
- [x] 实现强制撤销该链接所有大屏会话。
- [x] 实现 `/screen/{share_code}` 静态页面。
- [x] 实现 `/screen-api/auth/login`，校验链接状态、登录有效期、IP 白名单、用户名、密码。
- [x] 实现持久 `screen_session` Cookie，使用 `HttpOnly`、`Secure`、`SameSite=Strict`。
- [x] 实现 `/screen-api/auth/logout`，主动退出后会话失效。
- [x] 实现 `/screen-api/auth/me`。
- [x] 实现 `/screen-api/dashboard/realtime`、`trends`、`rankings`、`security`、`performance`。
- [x] 大屏 API 按 `scope_type`、`scope_ids`、`allowed_metrics` 过滤。
- [x] 默认禁止返回用户生成内容原文、API Key 明文、厂商密钥、导出下载链接和审计详情。
- [x] `allow_drilldown=false` 时只返回聚合数据，不返回明细 ID。
- [x] 访问日志可在 Admin 后台查询。

## 测试任务

- [x] 只有 `/screen/{share_code}` 链接码不能直接查看数据，必须输入用户名和密码。
- [x] 输入正确用户名和密码后可以看到授权范围内指标。
- [x] 刷新页面、关闭浏览器再打开，仍保持登录。
- [x] 长时间展示后不会自动退出。
- [x] 主动点击退出后，再访问需要重新登录。
- [x] 链接登录有效期到达后，未登录用户不能新登录。
- [x] 链接登录有效期到达后，已登录大屏不被踢出。
- [x] 链接停用后访问被拒绝。
- [x] 管理员撤销会话后已登录大屏被强制下线。
- [x] 密码重置后旧会话被强制下线。
- [x] IP 不在白名单时登录和数据接口都被拒绝。
- [x] 大屏账号不能访问 `/admin/*` 和 `/admin-api/*`。
- [x] 访问日志完整记录登录、失败、刷新、退出、拒绝原因。

## 验收标准

- 可生成独立大屏链接并分配给会议室、运营中心或客户现场。
- 大屏分享不会绕过后台权限。
- 大屏会话符合“输入密码后不过期，除非用户退出”的要求。
- 安全强制下线和普通退出有清晰日志。
