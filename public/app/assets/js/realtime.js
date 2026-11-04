// Live updates while the APP is in the foreground: a Server-Sent Events stream
// read with fetch (so the bearer token can be sent). The server ends each
// stream after about a minute and the APP reconnects at once. In the
// background the stream is closed (Web Push takes over); when the server is
// busy (503) the pages keep polling.
import { authHeaders, refresh, signedIn } from "./auth.js";

const listeners = new Set();
const state = { controller: null, connected: false, attempts: 0, timer: 0, started: false, busyUntil: 0 };

export function onRealtime(listener) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function realtimeConnected() {
  return state.connected;
}

function emit(type, data) {
  for (const listener of listeners) {
    try {
      listener(type, data);
    } catch {
      // a listener must not break the stream
    }
  }
}

function setConnected(value) {
  if (state.connected !== value) {
    state.connected = value;
    emit("status", { connected: value });
  }
}

function schedule(delay) {
  window.clearTimeout(state.timer);
  if (!state.started) {
    return;
  }
  state.timer = window.setTimeout(() => void connect(), delay);
}

function backoff() {
  state.attempts += 1;
  return Math.min(30_000, 1000 * 2 ** Math.min(state.attempts, 5));
}

async function connect() {
  if (!state.started || state.controller || !signedIn() || (document.visibilityState === "hidden" && !window.TokenHubNative?.keepLiveInBackground) || !navigator.onLine) {
    return;
  }
  if (Date.now() < state.busyUntil) {
    schedule(state.busyUntil - Date.now());
    return;
  }
  const controller = new AbortController();
  state.controller = controller;
  let next = null;
  try {
    const response = await fetch("/admin-api/app-auth/stream", {
      headers: { Accept: "text/event-stream", ...authHeaders() },
      credentials: "same-origin",
      cache: "no-store",
      signal: controller.signal
    });
    if (response.status === 401) {
      const ok = await refresh().catch(() => false);
      next = ok ? 0 : null;
      return;
    }
    if (response.status === 503) {
      state.busyUntil = Date.now() + 30_000;
      next = 30_000;
      return;
    }
    if (!response.ok || !response.body) {
      next = backoff();
      return;
    }
    setConnected(true);
    state.attempts = 0;
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";
    let ended = false;
    while (!ended) {
      const { done, value } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      const blocks = buffer.split(/\r?\n\r?\n/);
      buffer = blocks.pop() || "";
      for (const block of blocks) {
        let type = "message";
        const data = [];
        for (const line of block.split(/\r?\n/)) {
          if (line.startsWith("event:")) type = line.slice(6).trim();
          else if (line.startsWith("data:")) data.push(line.slice(5).trim());
        }
        if (data.length === 0) {
          continue;
        }
        let payload = {};
        try {
          payload = JSON.parse(data.join("\n"));
        } catch {
          continue;
        }
        if (type === "end") {
          ended = true;
          next = payload.reason === "signed_out" ? null : 200;
          if (payload.reason === "signed_out") {
            void refresh().catch(() => false);
          }
          break;
        }
        emit(type, payload);
      }
    }
    if (next === null && !ended) {
      next = 500; // the connection dropped: reconnect soon
    }
  } catch (error) {
    if (error?.name !== "AbortError") {
      next = backoff();
    }
  } finally {
    if (state.controller === controller) {
      state.controller = null;
    }
    setConnected(false);
    if (next !== null) {
      schedule(next);
    }
  }
}

function stopStream() {
  window.clearTimeout(state.timer);
  state.controller?.abort();
  state.controller = null;
  setConnected(false);
}

document.addEventListener("visibilitychange", () => {
  if (!state.started) {
    return;
  }
  if (document.visibilityState === "hidden") {
    // The desktop shell keeps the stream (it shows system notifications);
    // phones and browsers close it and rely on push.
    if (!window.TokenHubNative?.keepLiveInBackground) {
      stopStream();
    }
  } else {
    state.attempts = 0;
    schedule(0);
  }
});
window.addEventListener("online", () => state.started && schedule(0));
window.addEventListener("offline", () => stopStream());

export function startRealtime() {
  state.started = true;
  schedule(0);
}

export function stopRealtime() {
  state.started = false;
  stopStream();
}
