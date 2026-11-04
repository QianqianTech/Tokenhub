// Web Push for alerts. Needs a secure context (HTTPS), a service worker and,
// on iPhone/iPad, the APP added to the home screen (iOS 16.4+).
import { api } from "/admin/assets/js/api.js";
import { platform } from "./auth.js";

export function standalone() {
  return window.matchMedia?.("(display-mode: standalone)").matches || window.navigator.standalone === true;
}

function nativePush() {
  return window.TokenHubNative?.push || null;
}

export function pushSupport() {
  if (nativePush()) {
    return { ok: true, reason: "", native: true };
  }
  if (!window.isSecureContext) {
    return { ok: false, reason: "推送需要 HTTPS 连接。当前为 HTTP 访问，只能在 APP 打开时看到告警。" };
  }
  if (platform() === "ios" && !standalone()) {
    return { ok: false, reason: "在 iPhone / iPad 上，请先用 Safari 的「分享 → 添加到主屏幕」安装 APP（需要 iOS 16.4 及以上），再从主屏幕打开开启推送。" };
  }
  if (!("serviceWorker" in navigator) || !("PushManager" in window) || !("Notification" in window)) {
    return { ok: false, reason: "当前浏览器不支持推送通知。" };
  }
  if (Notification.permission === "denied") {
    return { ok: false, reason: "通知权限已被拒绝，请在系统或浏览器设置中允许 TokenHub 发送通知。" };
  }
  return { ok: true, reason: "" };
}

function keyBytes(base64url) {
  const base64 = base64url.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (base64url.length % 4)) % 4);
  return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}

export async function currentSubscription() {
  if (nativePush()) {
    return { native: true };
  }
  if (!("serviceWorker" in navigator)) {
    return null;
  }
  const registration = await navigator.serviceWorker.getRegistration("/app/");
  return registration ? registration.pushManager.getSubscription() : null;
}

export async function enablePush() {
  const support = pushSupport();
  if (!support.ok) {
    throw new Error(support.reason);
  }
  const native = nativePush();
  if (native) {
    const { platform: kind, token } = await native.register();
    await api("/admin-api/app-auth/push/native", { method: "POST", body: JSON.stringify({ platform: kind, token }), silent: true });
    return true;
  }
  const permission = await Notification.requestPermission();
  if (permission !== "granted") {
    throw new Error("没有获得通知权限。");
  }
  const registration = await navigator.serviceWorker.ready;
  const { data } = await api("/admin-api/app-auth/push/key", { silent: true });
  let subscription = await registration.pushManager.getSubscription();
  if (!subscription) {
    subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(String(data.public_key)) });
  }
  await api("/admin-api/app-auth/push/subscription", { method: "POST", body: JSON.stringify(subscription.toJSON()), silent: true });
  return true;
}

export async function disablePush() {
  const native = nativePush();
  if (native) {
    await native.unregister?.();
  } else {
    const subscription = await currentSubscription().catch(() => null);
    await subscription?.unsubscribe?.().catch(() => undefined);
  }
  await api("/admin-api/app-auth/push/subscription", { method: "DELETE", silent: true });
  return true;
}
