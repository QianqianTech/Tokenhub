// Native shells (native/capacitor, native/tauri) expose secure storage to the
// /app/ page. This shim turns them into window.TokenHubNative, the interface
// auth.js uses (see native/README.md). In a normal browser it does nothing.
(() => {
  if (window.TokenHubNative) {
    return;
  }
  const origin = window.location.origin;
  const key = `tokenhub.device.${origin}`;

  // Capacitor shell: links that leave this TokenHub server (other origins or
  // target=_blank) open in the system browser instead of inside the shell's
  // web view, which would otherwise carry the native bridge to that page.
  if (window.Capacitor?.isNativePlatform?.()) {
    const openExternal = (href) => {
      const browser = window.Capacitor.Plugins?.Browser;
      if (browser?.open) {
        browser.open({ url: href }).catch(() => window.open(href, "_system"));
      } else {
        // Capacitor hands window.open of a new window to the operating system.
        window.open(href, "_system");
      }
    };
    const external = (href) => {
      try {
        const url = new URL(href, window.location.href);
        if (!["http:", "https:", "mailto:", "tel:"].includes(url.protocol)) {
          return null;
        }
        return url.origin === origin ? null : url.href;
      } catch {
        return null;
      }
    };
    document.addEventListener("click", (event) => {
      if (event.defaultPrevented || event.button !== 0) {
        return;
      }
      const anchor = event.target instanceof Element ? event.target.closest("a[href]") : null;
      if (!anchor) {
        return;
      }
      let url;
      try {
        url = new URL(anchor.getAttribute("href") || "", window.location.href);
      } catch {
        return;
      }
      const newWindow = anchor.target === "_blank" && (url.protocol === "http:" || url.protocol === "https:");
      const target = external(url.href) || (newWindow ? url.href : null);
      if (target) {
        event.preventDefault();
        openExternal(target);
      }
    }, true);
    const nativeOpen = window.open.bind(window);
    window.open = (href, name, features) => {
      const target = href ? external(String(href)) : null;
      if (target && name !== "_system") {
        openExternal(target);
        return null;
      }
      return nativeOpen(href, name, features);
    };
  }

  const capacitor = window.Capacitor?.Plugins?.TokenHubSecureStore;
  if (capacitor && window.Capacitor?.isNativePlatform?.()) {
    window.TokenHubNative = {
      platform: window.Capacitor.getPlatform(),
      async getRefreshToken() {
        const result = await capacitor.get({ key }).catch(() => null);
        return result?.value || null;
      },
      async setRefreshToken(token) {
        await capacitor.set({ key, value: String(token) });
      },
      async clearRefreshToken() {
        await capacitor.remove({ key }).catch(() => undefined);
      },
      // Native push (APNs / FCM) through @capacitor/push-notifications.
      push: window.Capacitor.Plugins.PushNotifications ? {
        async register() {
          const push = window.Capacitor.Plugins.PushNotifications;
          const permission = await push.requestPermissions();
          if (permission.receive !== "granted") {
            throw new Error("没有获得通知权限。");
          }
          if (window.Capacitor.getPlatform() === "android") {
            await push.createChannel?.({ id: "tokenhub_alerts", name: "TokenHub 告警", importance: 5, visibility: 0 }).catch(() => undefined);
          }
          const token = await new Promise((resolve, reject) => {
            const timer = window.setTimeout(() => reject(new Error("系统没有返回推送令牌。")), 20000);
            push.addListener("registration", (result) => { window.clearTimeout(timer); resolve(result.value); });
            push.addListener("registrationError", (error) => { window.clearTimeout(timer); reject(new Error(error?.error || "推送注册失败。")); });
            push.register();
          });
          return { platform: window.Capacitor.getPlatform(), token: String(token) };
        },
        async unregister() {
          await window.Capacitor.Plugins.PushNotifications.unregister?.().catch(() => undefined);
        }
      } : null
    };
    // Tapping a push notification opens the alert page of this server.
    window.Capacitor.Plugins.PushNotifications?.addListener?.("pushNotificationActionPerformed", (action) => {
      const url = String(action?.notification?.data?.url || "");
      if (url.startsWith("/app/")) {
        window.location.href = `${origin}${url}`;
      }
    });
    // Deep links (tokenhub://link?u=…) while a server page is open.
    window.Capacitor.Plugins.App?.addListener?.("appUrlOpen", (event) => {
      const match = String(event?.url || "").match(/^tokenhub:\/\/link\?u=(.+)$/);
      let link = String(event?.url || "");
      if (match) {
        try { link = decodeURIComponent(match[1]); } catch { return; }
      }
      try {
        const url = new URL(link);
        const code = new URLSearchParams(url.hash.replace(/^#/, "")).get("c") || "";
        if (url.origin === origin && /^thl_[0-9a-f]{64}$/.test(code)) {
          window.location.href = `${origin}/app/link#c=${code}`;
        } else {
          // Another server: back to the shell's start screen, which picks the server.
          const local = window.Capacitor.getPlatform() === "ios" ? "capacitor://localhost" : "https://localhost";
          window.location.href = `${local}/index.html#link=${encodeURIComponent(link)}`;
        }
      } catch {
        // not a link
      }
    });
    return;
  }

  const tauri = window.__TAURI__?.core?.invoke;
  if (typeof tauri === "function") {
    window.TokenHubNative = {
      platform: /Windows/.test(navigator.userAgent) ? "windows" : "macos",
      async getRefreshToken() {
        return (await tauri("secure_get", { key }).catch(() => null)) || null;
      },
      async setRefreshToken(token) {
        await tauri("secure_set", { key, value: String(token) });
      },
      async clearRefreshToken() {
        await tauri("secure_remove", { key }).catch(() => undefined);
      },
      // Desktop: the window keeps its live stream in the background and shows
      // system notifications for new alerts (tauri-plugin-notification).
      keepLiveInBackground: true,
      async notify(title, body) {
        const notification = window.__TAURI__?.notification;
        if (!notification) {
          return;
        }
        let granted = await notification.isPermissionGranted();
        if (!granted) {
          granted = (await notification.requestPermission()) === "granted";
        }
        if (granted) {
          notification.sendNotification({ title, body });
        }
      }
    };
  }
})();
