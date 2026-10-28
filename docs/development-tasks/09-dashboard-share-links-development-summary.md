# 09 独立大屏链接开发总结

## 1. 任务目标

本任务实现可独立访问的智慧大屏分享链接：`/screen/{share_code}`。

每个分享链接具备独立的用户名、密码、数据范围、可查看指标、IP 白名单、持久登录会话和访问日志。大屏账号与后台管理员账号完全隔离，不能通过大屏会话访问 `/admin/*` 或 `/admin-api/*`。

## 2. 实现范围

### 数据结构

已使用现有迁移中的三张表承载大屏分享能力：

- `dashboard_share_links`：保存链接码、名称、用户名、密码哈希、授权范围、允许指标、IP 白名单、登录截止时间、状态和 `session_version`。
- `dashboard_share_sessions`：保存持久化大屏会话哈希、客户端 IP、User-Agent、会话版本和撤销时间。
- `dashboard_share_access_logs`：记录登录成功、登录失败、退出、刷新、IP 拒绝、登录过期拒绝、会话撤销和链接停用拒绝。

### 后台管理

后台新增专用大屏分享接口，避免通过通用 CRUD 直接写入敏感字段：

- `GET /admin-api/dashboard-share-links`
- `POST /admin-api/dashboard-share-links`
- `PUT /admin-api/dashboard-share-links/{id}`
- `DELETE /admin-api/dashboard-share-links/{id}`
- `POST /admin-api/dashboard-share-links/{id}/reset-password`
- `POST /admin-api/dashboard-share-links/{id}/revoke-sessions`
- `GET /admin-api/dashboard-share-access-logs`

后台前端已支持：

- 大屏分享链接列表、创建、编辑、停用。
- 打开 `/screen/{share_code}`。
- 重置密码并仅在重置响应中展示一次明文。
- 撤销指定链接的全部大屏会话。
- 查询大屏访问日志。

### 大屏访问端

大屏页面通过 `/screen/{share_code}` 访问，前端从 URL 解析 `share_code`，用户只需输入用户名和密码。

大屏接口包括：

- `POST /screen-api/auth/login`
- `POST /screen-api/auth/logout`
- `GET /screen-api/auth/me`
- `GET /screen-api/dashboard/realtime`
- `GET /screen-api/dashboard/trends`
- `GET /screen-api/dashboard/rankings`
- `GET /screen-api/dashboard/security`
- `GET /screen-api/dashboard/performance`

大屏公开面已收口到任务 09 要求的五个 dashboard 指标，不再暴露 screen 侧多模态 dashboard 或下载链接接口。

## 3. 会话与安全规则

### 持久会话

登录成功后服务端写入 `screen_session` Cookie：

- `HttpOnly`
- `Secure`
- `SameSite=Strict`
- 长期有效

会话不会因为时间、空闲、刷新页面或关闭浏览器自动过期。正常情况下，用户主动退出后才需要重新登录。

### 登录截止时间

`expires_at` 只限制新登录：

- 未登录用户在链接过期后不能再登录。
- 已登录大屏不会因为 `expires_at` 到达而被踢出。

### 强制下线

以下操作会使旧会话失效：

- 管理员撤销该链接会话。
- 链接停用。
- 密码重置。
- IP 白名单不再允许当前客户端。

实现上使用 `session_version` 作为会话撤销原语。创建会话时记录当时的版本；撤销、停用或重置密码时递增链接版本并标记旧 session 的 `revoked_at`。

### 敏感数据保护

大屏接口默认不返回：

- 用户生成内容原文。
- API Key 明文。
- 厂商密钥。
- 导出下载链接。
- 审计详情。

`allow_drilldown=false` 时，大屏排行等接口只返回聚合值，不返回明细 ID。

## 4. 数据范围与指标过滤

大屏指标接口按分享链接配置过滤：

- `scope_type=global`：全局聚合。
- `scope_type=department`：按 `department_id` 过滤。
- `scope_type=user`：按 `user_id` 过滤。
- `scope_type=api_key`：按 `api_key_id` 过滤。

`scope_ids` 支持多个 ID。`allowed_metrics` 用于限制可访问指标；未允许的指标返回 blocked 结果，不返回数据。

## 5. 关键文件

- `app/Services/ScreenService.php`：大屏分享链接、登录、会话、日志和撤销逻辑。
- `app/Services/DashboardService.php`：大屏指标过滤、指标授权和 drilldown 数据收口。
- `app/Http/Controllers/ScreenController.php`：大屏登录、退出、当前会话和 dashboard API。
- `app/Http/Controllers/AdminController.php`：后台大屏分享专用管理接口。
- `app/Bootstrap/Kernel.php`：后台与大屏路由注册。
- `app/Middleware/ScreenAuthMiddleware.php`：大屏会话鉴权。
- `public/screen/index.html`：独立大屏页面。
- `public/screen/assets/screen.js`：大屏登录、会话恢复、轮询刷新和强制下线处理。
- `public/admin/assets/js/resources.js`：后台资源菜单和表单配置。
- `public/admin/assets/js/table.js`：大屏分享重置密码、撤销会话、打开链接等行操作。
- `tests/Services/ScreenServiceShareLinksTest.php`：大屏分享服务行为测试。
- `tests/Services/DashboardScreenScopeTest.php`：大屏指标范围和 drilldown 测试。
- `tests/Frontend/ScreenShareFrontendTest.php`：大屏前端和路由公开面测试。

## 6. 验证结果

已通过聚焦测试：

```bash
docker compose exec -T php php tests/Services/ScreenServiceShareLinksTest.php
docker compose exec -T php php tests/Services/DashboardScreenScopeTest.php
docker compose exec -T php php tests/Frontend/ScreenShareFrontendTest.php
```

已通过全量测试：

```bash
docker compose exec -T php php bin/run-tests.php
```

最终结果：

```text
33 test file(s) passed.
```

## 7. 完成状态

任务 09 的开发任务、测试任务和验收标准均已完成。原任务清单 `docs/development-tasks/09-dashboard-share-links.md` 已同步勾选完成状态。

