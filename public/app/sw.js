// TokenHub APP service worker.
// - Caches only the app shell and static assets (network first, cache as offline fallback).
// - Never touches /admin-api: API data is never cached on the device.
// - Shows alert push notifications (payload has no alert details).
// Build id stamped at release time (scripts/release/stamp-app-version.sh), so
// every release starts a fresh shell cache; "dev" in an unstamped checkout.
const BUILD = "__TOKENHUB_APP_BUILD__";
const VERSION = `tokenhub-app-${BUILD.startsWith("__") ? "dev" : BUILD}`;
const SHELL = [
  "/app/",
  "/app/index.html",
  "/app/manifest.webmanifest",
  "/app/assets/app.css",
  "/app/assets/js/main.js",
  "/app/assets/js/auth.js",
  "/app/assets/icons/icon-192.png",
  "/admin/assets/css/tokens.css",
  "/admin/assets/css/app.css",
  "/admin/assets/css/components.css",
  "/admin/assets/css/redesign.css",
  "/admin/assets/brand/tokenhub-app-icon.svg"
];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(SHELL)).catch(() => undefined));
});

self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key.startsWith("tokenhub-app-") && key !== VERSION).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener("message", (event) => {
  if (event.data === "skip-waiting") {
    self.skipWaiting();
  }
});

function cacheable(url) {
  return url.origin === self.location.origin
    && (url.pathname === "/app/" || url.pathname.startsWith("/app/assets/") || url.pathname === "/app/index.html"
      || url.pathname === "/app/manifest.webmanifest" || url.pathname.startsWith("/admin/assets/"));
}

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") {
    return;
  }
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith("/admin-api/")) {
    return; // straight to the network, never cached
  }
  if (request.mode === "navigate" && url.pathname.startsWith("/app")) {
    event.respondWith((async () => {
      try {
        return await fetch(request);
      } catch {
        return (await caches.match("/app/index.html")) || (await caches.match("/app/")) || Response.error();
      }
    })());
    return;
  }
  if (!cacheable(url)) {
    return;
  }
  event.respondWith((async () => {
    try {
      const response = await fetch(request);
      if (response.ok && response.type === "basic") {
        const copy = response.clone();
        caches.open(VERSION).then((cache) => cache.put(request, copy)).catch(() => undefined);
      }
      return response;
    } catch {
      const cached = await caches.match(request, { ignoreSearch: true });
      return cached || Response.error();
    }
  })());
});

self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = {};
  }
  const title = data.title || "TokenHub";
  event.waitUntil(self.registration.showNotification(title, {
    body: data.body || "有新的告警待处理",
    tag: data.tag || "tokenhub-alert",
    renotify: true,
    icon: "/app/assets/icons/icon-192.png",
    badge: "/app/assets/icons/icon-192.png",
    data: { url: typeof data.url === "string" && data.url.startsWith("/app/") ? data.url : "/app/#/overview" }
  }));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const target = event.notification.data?.url || "/app/#/overview";
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    for (const client of windows) {
      if (new URL(client.url).pathname.startsWith("/app")) {
        await client.focus();
        client.postMessage({ type: "navigate", url: target });
        return;
      }
    }
    await self.clients.openWindow(target);
  })());
});
