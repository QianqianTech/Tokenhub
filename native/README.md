# TokenHub APP 安装包（M6）

两个薄壳工程：

| 目录 | 平台 | 技术 |
| --- | --- | --- |
| `native/capacitor/` | iOS / Android | Capacitor 6 |
| `native/tauri/` | macOS / Windows | Tauri 2 |

> 构建状态（2026-10-30）：iOS 工程已生成、同步，并通过未签名模拟器 Debug 构建；真机签名和原生推送尚未验证。桌面 npm 依赖和图标已生成，macOS 构建因 Rust 索引连接超时、缺少 `tauri-plugin-notification` 缓存而未完成，尚无 DMG；Windows、Android 未在本机构建。分发与签名按部署另行配置；原生推送仍需要部署方的 APNs / FCM 凭证。`app-native` 工作流可用于各平台构建。

## 工作方式

1. **启动页**（`www/` 或 `dist/`）：粘贴认证链接 `https://<服务器>/app/link#c=thl_…`。启动页从链接中解析出服务器地址，记入本机的服务器列表，然后打开该服务器的 `/app/link#c=…`。下次启动时直接打开上次使用的服务器。
2. **服务器的 `/app/` 页面**：页面里的 `native-bridge.js` 检测到原生壳后，生成 `window.TokenHubNative`。登录改用 `client=native` 模式，设备凭证存入系统钥匙串，不再放在 Cookie 里。
3. **按页面来源隔离凭证**：凭证按服务器 origin 分开存放。原生代码根据**当前页面的实际 URL** 计算存储键，忽略网页传来的值，所以在壳里打开的其他网站读不到 TokenHub 的凭证。
4. **深链接**：`tokenhub://link?u=<URL 编码的认证链接>`，只有 Capacitor 版支持。在电脑后台生成的二维码可以直接用手机相机扫，也可以把链接包装成这种格式。
5. **只支持 HTTPS**：安装包只连接 HTTPS 服务器。纯 HTTP 部署请在浏览器中使用。
6. **外部链接用系统浏览器打开**：服务器页面里指向其他来源的链接、`target="_blank"` 链接和 `window.open` 都交给系统浏览器（有 `@capacitor/browser` 时用它），不在壳内加载（`public/app/assets/js/native-bridge.js`）。
7. **限定可加载的域名**：通用安装包要连接用户自己部署的服务器，`server.allowNavigation` 默认是 `*`。企业自用的安装包应在构建时限定：

   ```sh
   TOKENHUB_ALLOWED_HOSTS="tokenhub.example.com,*.corp.example.com" npm run sync
   ```

   `scripts/configure-navigation.mjs` 会把列表写入 `capacitor.config.json`；其他站点在壳内无法加载，由系统浏览器打开。
8. **相机权限**：iOS 的 `Info.plist` 含 `NSCameraUsageDescription`（`install-plugin.mjs` 也会补上）。WebKit 没有 BarcodeDetector，iOS 上登录页会提示用系统相机扫码，或粘贴链接；系统相机打开 `tokenhub://link?u=…` 深链接即可进入安装包。

| 平台 | 凭证存放位置 | 代码 |
| --- | --- | --- |
| iOS | Keychain，`AfterFirstUnlockThisDeviceOnly`，不同步到 iCloud | `capacitor/plugins/ios/TokenHubSecureStorePlugin.swift` |
| Android | EncryptedSharedPreferences，密钥由 Keystore 保护；关闭备份 | `capacitor/plugins/android/TokenHubSecureStorePlugin.java` |
| macOS / Windows | Keychain / 凭据管理器（`keyring` crate） | `tauri/src-tauri/src/main.rs` |

## 构建：iOS / Android

```sh
cd native/capacitor
npm install
npm run add:ios        # 首次创建工程；已有工程则重新注册插件，不重复创建
npm run add:android    # cap add android，然后复制插件、注册 MainActivity、加依赖、关闭备份、注册深链接
npx cap sync
npm run open:ios       # 在 Xcode 中选择团队签名后运行
npm run open:android   # 在 Android Studio 中运行
```

注意事项：

- iOS：脚本会自动把 `TokenHubSecureStorePlugin.swift` 加入 App 编译目标，并复制 `resources/AppIcon-1024.png`。
- iOS 推送：在 Xcode 的 Signing & Capabilities 中添加 Push Notifications 和 Background Modes → Remote notifications。`install-plugin.mjs` 已在 `AppDelegate.swift` 中把设备令牌转交给 `@capacitor/push-notifications`。服务器端配置 `APNS_*`（见 `docs/app-admin.md` 4.2），开发版安装包要设 `APNS_PRODUCTION=false`。
- Android 推送：从 Firebase 控制台下载 `google-services.json`，执行 `TOKENHUB_GOOGLE_SERVICES_JSON=/path/google-services.json npm run add:android`（或手动放到 `android/app/`）。没有这个文件时安装包仍可使用，只是推送开关不可用。Android 13 以上首次开启推送时会请求通知权限，通知渠道为 `tokenhub_alerts`。
- 应用图标：用 `public/app/assets/icons/icon-512.png` 生成，例如用 `@capacitor/assets`。

## 构建：macOS / Windows

```sh
cd native/tauri
npm install
npm run icon      # 从 A! 图标生成各尺寸图标
npm run dev       # 调试
npm run build     # 生成 dmg / msi / nsis 安装包
```

`src-tauri/capabilities/default.json` 只允许 `https://*/app/*` 页面调用 `secure_get`、`secure_set`、`secure_remove` 三个命令和系统通知（`tauri-plugin-notification`）。电脑版在后台时不关闭实时连接，新告警通过系统通知提示。

### 下载受限时

iOS 基础壳的 Pods 全部来自已安装的 npm 包。CocoaPods CDN 不可达时，可以执行 `npm run sync:ios:local`；它使用项目内的隔离缓存和空规格索引，不修改全局 CocoaPods 配置。以后若加入依赖远程 Pod 的插件，应恢复正常 `npx cap sync ios`。

桌面版需要从 [Cargo 官方索引](https://index.crates.io/config.json) 解析 Rust 依赖；当前缺失的插件见 [tauri-plugin-notification](https://crates.io/crates/tauri-plugin-notification)。网络恢复后，在 `native/tauri` 执行：

```sh
cargo fetch --manifest-path src-tauri/Cargo.toml --target aarch64-apple-darwin
npm run build -- --ci
```

该命令对应 Apple Silicon Mac。`bundle.targets` 使用 `all`，按构建机平台选择安装包格式；Windows 安装包需要 Windows 构建环境。

## 网页与原生之间的接口

```ts
interface TokenHubNative {
  platform: string;                       // "ios" | "android" | "macos" | "windows"
  getRefreshToken(): Promise<string | null>;
  setRefreshToken(token: string): Promise<void>;   // 必须原子写入：写入失败时保留旧值
  clearRefreshToken(): Promise<void>;
  push?: {                                // iOS / Android：原生推送
    register(): Promise<{ platform: "ios" | "android"; token: string }>;
    unregister(): Promise<void>;
  };
  keepLiveInBackground?: boolean;         // 电脑版：后台保持实时连接
  notify?(title: string, body: string): Promise<void>;  // 电脑版：系统通知
}
```

这个接口由 `public/app/assets/js/native-bridge.js` 生成，再由 `auth.js` 使用。如果要换一个原生框架，只需要提供同样的接口。

## 待定

- 原生推送已按自备凭证实现；如果以后要由 TokenHub 统一中转（Q5 的另一种方案），只需替换 `app/Services/AppNativePushService.php` 的发送部分。
- 分发方式（Q6）、签名证书、Universal Links（需要在服务器上部署 `apple-app-site-association`）。
